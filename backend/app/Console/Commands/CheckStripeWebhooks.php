<?php

namespace App\Console\Commands;

use App\Http\Controllers\StripeWebhookController;
use App\Services\Billing\BillingLog;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Billing state follows Stripe's webhooks, and which events Stripe sends is a
 * dashboard setting nothing in the code can see. This asks Stripe for the
 * endpoint that points at this app and fails when there is none, none is
 * enabled, or an enabled one is not subscribed to an event the webhook depends on
 * (StripeWebhookController::REQUIRED_EVENTS).
 *
 * Read-only: it never changes the endpoint. Runs on deploy and daily in
 * production; a failure is one error line on the billing channel (Sentry).
 */
class CheckStripeWebhooks extends Command
{
    protected $signature = 'stripe:check-webhooks {--url= : Check the endpoint with this exact URL instead of the one on APP_URL}';

    protected $description = 'Check that the Stripe webhook endpoint is subscribed to every event the app depends on';

    public function handle(StripeService $stripe): int
    {
        try {
            $all = $stripe->webhookEndpoints();
        } catch (Throwable $e) {
            return $this->reportProblems(["Could not read the webhook endpoints from Stripe: {$e->getMessage()}"]);
        }

        // Stripe has two lists. A webhook made in the current dashboard is an
        // "event destination" and may be missing from the older list above.
        $unreadable = null;
        try {
            $all = $this->merged($all, $stripe->eventDestinations());
        } catch (Throwable $e) {
            $unreadable = "Could not read Stripe event destinations: {$e->getMessage()}";
        }

        $endpoints = $this->ours($all);
        if (! $endpoints) {
            return $this->reportProblems(array_values(array_filter([
                'No Stripe webhook delivers to '.$this->expectedUrl().' (or to '.StripeWebhookController::PATH.' on another host).',
                'Stripe has: '.(implode(', ', array_map(fn ($e) => "{$e['url']} ({$e['id']}, {$e['status']})", $all)) ?: 'none'),
                $unreadable,
            ])));
        }

        if ($unreadable) {
            $this->warn($unreadable);
        }

        // A disabled endpoint delivers nothing, so only the enabled ones count:
        // an old endpoint left switched off beside its replacement is fine.
        $enabled = array_values(array_filter($endpoints, fn ($e) => $e['status'] === 'enabled'));
        if (! $enabled) {
            return $this->reportProblems([
                ...array_map(fn ($e) => "{$e['url']} ({$e['id']}) is {$e['status']}: Stripe is sending it nothing.", $endpoints),
                'Stripe has: '.implode(', ', array_map(fn ($e) => "{$e['url']} ({$e['id']}, {$e['status']})", $all)),
            ]);
        }

        $problems = [];
        foreach ($enabled as $endpoint) {
            // The webhook reads the full object out of each event.
            if ($endpoint['payload'] !== 'snapshot') {
                $problems[] = "{$endpoint['url']} ({$endpoint['id']}) sends {$endpoint['payload']} events; the app needs snapshot events.";

                continue;
            }

            $missing = $this->missingEvents($endpoint['enabled_events']);
            if ($missing) {
                $problems[] = "{$endpoint['url']} ({$endpoint['id']}) is not subscribed to: ".implode(', ', $missing).'.';
            }
        }

        if ($problems) {
            return $this->reportProblems($problems);
        }

        if (count($enabled) > 1) {
            // Each endpoint signs with its own secret; the app holds one.
            $this->warn(count($enabled).' enabled endpoints point at this app ('.implode(', ', array_column($enabled, 'id'))
                .'). Only the one whose signing secret is STRIPE_WEBHOOK_SECRET is processed; the others fail and Stripe will disable them.');
        }

        $this->info(implode(', ', array_unique(array_column($enabled, 'url')))." listens for every event the app needs (Stripe {$this->mode()} mode).");

        return self::SUCCESS;
    }

    /**
     * Both lists as one. The same webhook can appear in each under a different
     * id; an entry with the same URL, status, payload and events counts once.
     *
     * @param  list<array{id: string, url: string, status: string, enabled_events: list<string>, payload: string}>  $endpoints
     * @param  list<array{id: string, url: string, status: string, enabled_events: list<string>, payload: string}>  $destinations
     * @return list<array{id: string, url: string, status: string, enabled_events: list<string>, payload: string}>
     */
    private function merged(array $endpoints, array $destinations): array
    {
        $key = function (array $e): string {
            $events = $e['enabled_events'];
            sort($events);

            return implode('|', [rtrim($e['url'], '/'), $e['status'], $e['payload'], implode(',', $events)]);
        };

        $seen = array_flip(array_map($key, $endpoints));
        foreach ($destinations as $destination) {
            if (! isset($seen[$key($destination)])) {
                $endpoints[] = $destination;
            }
        }

        return $endpoints;
    }

    /**
     * The endpoints that deliver to this app: an exact --url, otherwise those
     * on the webhook path — narrowed to APP_URL's host when one matches, so a
     * staging endpoint in the same Stripe account is not checked from production.
     *
     * @param  list<array{id: string, url: string, status: string, enabled_events: list<string>}>  $all
     * @return list<array{id: string, url: string, status: string, enabled_events: list<string>}>
     */
    private function ours(array $all): array
    {
        if ($url = $this->option('url')) {
            return array_values(array_filter($all, fn ($e) => rtrim($e['url'], '/') === rtrim($url, '/')));
        }

        $onPath = array_values(array_filter(
            $all,
            fn ($e) => rtrim((string) parse_url($e['url'], PHP_URL_PATH), '/') === StripeWebhookController::PATH,
        ));

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $onHost = array_values(array_filter($onPath, fn ($e) => parse_url($e['url'], PHP_URL_HOST) === $host));

        return $onHost ?: $onPath;
    }

    /**
     * One entry per unmet group; alternatives are joined with "or".
     *
     * @param  list<string>  $enabled
     * @return list<string>
     */
    private function missingEvents(array $enabled): array
    {
        if (in_array('*', $enabled, true)) {
            return [];
        }

        $missing = [];
        foreach (StripeWebhookController::REQUIRED_EVENTS as $alternatives) {
            if (! array_intersect($alternatives, $enabled)) {
                $missing[] = implode(' or ', $alternatives);
            }
        }

        return $missing;
    }

    private function expectedUrl(): string
    {
        return $this->option('url') ?: rtrim((string) config('app.url'), '/').StripeWebhookController::PATH;
    }

    /** Live and test mode have separate endpoints; which one the app's key sees. */
    private function mode(): string
    {
        return str_contains((string) config('services.stripe.secret'), '_live_') ? 'live' : 'test';
    }

    /** @param  list<string>  $problems */
    private function reportProblems(array $problems): int
    {
        $problems[] = "Checked with the app's Stripe key, which is a {$this->mode()}-mode key: compare with the dashboard in {$this->mode()} mode.";

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        BillingLog::error('Stripe webhook setup check failed', ['problems' => $problems]);

        return self::FAILURE;
    }
}
