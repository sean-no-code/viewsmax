<?php

namespace App\Console\Commands;

use App\Mail\PasswordResetMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:email {email} {--mailer=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test email sending with detailed logging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        // Default to the app's configured mailer so the test reflects what
        // production actually uses, instead of forcing a specific provider.
        $mailer = $this->option('mailer') ?: config('mail.default');

        $this->info("Testing email sending to: {$email}");
        $this->info("Using mailer: {$mailer}");

        // A "log" or "array" mailer never delivers — it only writes to the log
        // (or memory). Warn loudly so a "success" here isn't mistaken for a real
        // delivery; this is the #1 reason production emails silently don't arrive.
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("⚠️  Mailer '{$mailer}' does NOT send real email — set MAIL_MAILER to smtp/brevo/ses to actually deliver.");
        }

        // Log current mail configuration
        $this->info("\n=== Mail Configuration ===");
        $this->info("Default mailer: " . config('mail.default'));
        $this->info("Mail host: " . config("mail.mailers.{$mailer}.host"));
        $this->info("Mail port: " . config("mail.mailers.{$mailer}.port"));
        $this->info("Mail username: " . config("mail.mailers.{$mailer}.username"));
        $this->info("Mail encryption: " . config("mail.mailers.{$mailer}.encryption"));
        $this->info("From address: " . config('mail.from.address'));
        $this->info("From name: " . config('mail.from.name'));
        $this->info("Frontend URL: " . config('app.frontend_url'));

        // Log to the dedicated mail channel (storage/logs/mail.log) so command
        // runs sit alongside the live request diagnostics.
        Log::channel('mail')->info('Email test command started', [
            'email' => $email,
            'mailer' => $mailer,
            'config' => [
                'default_mailer' => config('mail.default'),
                'mail_host' => config("mail.mailers.{$mailer}.host"),
                'mail_port' => config("mail.mailers.{$mailer}.port"),
                'mail_username' => config("mail.mailers.{$mailer}.username"),
                'mail_encryption' => config("mail.mailers.{$mailer}.encryption"),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
            ]
        ]);

        try {
            $this->info("\n=== Sending Test Email ===");

            // Create a test token
            $testToken = 'test-token-' . time();

            // Send the email via the resolved mailer.
            Mail::mailer($mailer)->to($email)->send(new PasswordResetMail($testToken, $email));

            $this->info("✅ Email handed to mailer '{$mailer}' without error.");
            Log::channel('mail')->info('Test email sent successfully', ['email' => $email, 'mailer' => $mailer]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("❌ Email sending failed: " . $e->getMessage());
            Log::channel('mail')->error('Test email failed', [
                'email' => $email,
                'mailer' => $mailer,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->info("\n=== Check Logs ===");
            $this->info("See storage/logs/mail.log for the failure detail.");

            return self::FAILURE;
        }
    }
}
