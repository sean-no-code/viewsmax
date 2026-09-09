<?php

namespace App\Providers;

use App\Models\VideoTranscription;
use App\Observers\VideoTranscriptionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Passport;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use PDO;
use PDOException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mime\Email;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The migrate commands that should trigger secondary DB creation.
     */
    private const MIGRATE_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve Passport's OAuth approve route to our subclass so the MCP
        // consent screen can downgrade a connection to read-only. Laravel
        // resolves route controllers through the container, so this binding
        // overrides the controller without touching Passport's routes.
        $this->app->bind(
            \Laravel\Passport\Http\Controllers\ApproveAuthorizationController::class,
            \App\Http\Controllers\Oauth\McpApproveAuthorizationController::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register observer for VideoTranscription
        VideoTranscription::observe(VideoTranscriptionObserver::class);

        // Guard the queue worker against a poisoned DB connection. The `database`
        // queue driver shares the app's default connection, and DatabaseQueue::pop()
        // reserves each job inside a transaction. If a prior job leaves a transaction
        // open on that long-lived connection — e.g. a nested bavix/laravel-wallet
        // credit transaction that desyncs Laravel's transaction counter from PDO
        // after a failed commit/rollback on an aborted Postgres transaction — the
        // next pop() calls beginTransaction() while PDO still has one open and throws
        // "There is already an active transaction", wedging the worker until it is
        // restarted. Rolling back any leaked transaction before each daemon loop
        // iteration keeps the worker healthy. A well-behaved job leaves
        // transactionLevel() at 0, so this is a no-op in the normal case.
        Queue::looping(function () {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });

        // Reverse proxies (ngrok in local testing, likely also prod's LB)
        // terminate TLS and forward plain HTTP, so Laravel's own request
        // inspection sees "http" and generates http:// URLs unless told
        // otherwise. Force https when APP_URL says the app is meant to be
        // https — otherwise OAuth discovery docs advertise the wrong scheme
        // for /oauth/authorize etc, breaking token exchange from a real client.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->configureMcpRateLimiters();
        $this->configureMcpOAuth();

        // Centralised outbound-email logging. Lets us trace in production whether
        // an email (verification, password reset, etc.) was actually attempted,
        // with which mailer, and whether the transport accepted it. A "Mail
        // sending" line with no matching "Mail sent" means the transport threw
        // (SMTP auth/connection) — check the calling code's error log for the
        // exception. A mailer of "log"/"array" means nothing was delivered.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            Log::channel('mail')->info('Mail sending', $this->mailLogContext($event->message));
        });

        Event::listen(MessageSent::class, function (MessageSent $event) {
            Log::channel('mail')->info('Mail sent', $this->mailLogContext($event->message) + [
                'message_id' => $event->sent->getMessageId(),
            ]);
        });

        // Ensure all secondary pgsql databases exist before migrations run.
        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event) {
                if (in_array($event->command, self::MIGRATE_COMMANDS)) {
                    $this->ensureSecondaryDatabasesExist($event->output);
                }
            });
        }
    }

    /**
     * Named rate limiters for the MCP server and API key endpoints. Limits
     * come from config/mcp.php so they are tunable via .env without a deploy.
     */
    private function configureMcpRateLimiters(): void
    {
        $limits = fn (string $key): int => (int) config("mcp.rate_limits.{$key}");

        RateLimiter::for('mcp-key-rotate', function (Request $request) use ($limits) {
            return Limit::perHour($limits('key_rotate_per_hour'))
                ->by('mcp-key-rotate:' . ($request->user()?->id ?? $request->ip()));
        });

        // Public discovery endpoint (/api/ai): generous per-IP cap — the
        // payload is cached, this just stops abuse.
        // Automation DM sends: per-Instagram-account throttle so one viral
        // post can't burn the account's messaging quota in a burst. Keyed by
        // the job's socialAccountId (see ExecuteAutomationRunJob::middleware).
        RateLimiter::for('instagram-automations', function ($job) {
            return Limit::perMinute((int) config('social.platforms.instagram.automations_per_minute', 20))
                ->by('ig-automations:'.($job->socialAccountId ?? 0));
        });

        RateLimiter::for('ai-discovery', function (Request $request) {
            return Limit::perMinute(30)->by('ai-discovery:' . $request->ip());
        });

        // Free transcript tools: public + per-IP. Each miss spends CaptAPI credits
        // (repeat URLs are served from the DB cache), so keep this modest.
        RateLimiter::for('transcript', function (Request $request) {
            return Limit::perMinute(15)->by('transcript:' . ($request->user()?->id ?? $request->ip()));
        });

        // Mention typeahead proxy: the FE debounces 300ms and the controller
        // caches results, so 30/min per user is plenty while still staying
        // inside X's own per-user windows.
        RateLimiter::for('x-mention', function (Request $request) {
            return Limit::perMinute(30)->by('x-mention:' . ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('mcp', function (Request $request) use ($limits) {
            $token = \App\Http\Middleware\McpAuth::resolveToken($request);
            $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
            $isMcpKey = $accessToken
                && array_intersect(['mcp', 'mcp:read', 'mcp:write'], $accessToken->abilities ?? []);

            // Valid MCP keys get their own budget; anything else (missing or
            // invalid key) shares a per-IP budget to slow key brute-forcing.
            return $isMcpKey
                ? Limit::perMinute($limits('per_minute'))->by('mcp:' . $accessToken->id)
                : Limit::perMinute($limits('failed_auth_per_minute'))->by('mcp-anon:' . $request->ip());
        });
    }

    /**
     * OAuth 2.1 + PKCE is the customer-facing MCP auth path (replacing the
     * interim URL-embedded API key mode long term): short-lived access
     * tokens, rotating refresh tokens, and a single 'mcp' scope mirroring
     * the 'mcp' ability already used by header/URL-key auth.
     */
    private function configureMcpOAuth(): void
    {
        Passport::tokensCan([
            // Legacy single scope = full access; kept so tokens issued before
            // the read/write split keep working (McpAuth treats it as both).
            'mcp' => 'Access ViewsMax MCP tools (posts, offers, connections, analytics)',
            'mcp:read' => 'Read your ViewsMax posts, offers, connections, and analytics',
            'mcp:write' => 'Create and change your ViewsMax posts, offers, and connections',
        ]);

        // Clients like Claude's connector omit the scope parameter entirely;
        // without a default those tokens carry no scopes and fail McpAuth's
        // scope check. Default to full access (read + write); the consent
        // screen lets the user downgrade a connection to read-only.
        Passport::defaultScopes(['mcp:read', 'mcp:write']);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::authorizationView('oauth.authorize');
    }

    /**
     * Build a structured log context for an outbound email message.
     *
     * @return array<string, mixed>
     */
    private function mailLogContext(Email $message): array
    {
        return [
            'mailer' => config('mail.default'),
            'to' => array_map(fn ($address) => $address->getAddress(), $message->getTo()),
            'subject' => $message->getSubject(),
        ];
    }

    /**
     * Loop through all non-default pgsql connections and create the database
     * if it does not yet exist, mirroring Laravel's behaviour for the default
     * connection.
     */
    private function ensureSecondaryDatabasesExist(OutputInterface $output): void
    {
        $defaultConnection = config('database.default');
        $connections = config('database.connections', []);

        foreach ($connections as $name => $config) {
            // Only handle secondary pgsql connections.
            if (($config['driver'] ?? '') !== 'pgsql' || $name === $defaultConnection) {
                continue;
            }

            $database = $config['database'] ?? null;
            if (! $database) {
                continue;
            }

            if ($this->pgsqlDatabaseExists($config, $database)) {
                continue;
            }

            $output->writeln('');
            $output->writeln("   <comment>WARN</comment>  The database '<info>{$database}</info>' does not exist on the '<info>{$name}</info>' connection.");

            if ($this->createPgsqlDatabase($config, $database)) {
                $output->writeln("   <info>INFO</info>  Database '<info>{$database}</info>' created successfully.");
            } else {
                $output->writeln("   <error>ERROR</error>  Could not create database '<info>{$database}</info>'. Please create it manually.");
            }
        }
    }

    /**
     * Check whether a PostgreSQL database exists by connecting to the
     * postgres system database and querying pg_database.
     */
    private function pgsqlDatabaseExists(array $config, string $database): bool
    {
        try {
            $pdo = $this->makePgsqlSystemPdo($config);
            $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :db');
            $stmt->execute([':db' => $database]);

            return (bool) $stmt->fetchColumn();
        } catch (PDOException) {
            // If we can't connect at all, let the migration fail with a clear error.
            return true;
        }
    }

    /**
     * Create a PostgreSQL database by connecting to the system "postgres" DB.
     */
    private function createPgsqlDatabase(array $config, string $database): bool
    {
        try {
            $pdo = $this->makePgsqlSystemPdo($config);
            // identifiers cannot be parameterised in DDL statements
            $pdo->exec('CREATE DATABASE "' . addslashes($database) . '"');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Build a PDO connection to the postgres system database so we can run
     * CREATE DATABASE without being connected to the target database.
     */
    private function makePgsqlSystemPdo(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=postgres',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '5432',
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}