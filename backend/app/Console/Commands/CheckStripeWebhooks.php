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
 * endpoint that points at this app and fails when it is missing, disabled, or
 * not subscribed to an event the webhook depends on
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

        $endpoints = $this->ours($all);
        if (! $endpoints) {
            return $this->reportProblems([
                'No Stripe webhook endpoint points at '.$this->expectedUrl().'.',
                'Stripe has: '.(implode(', ', array_column($all, 'url')) ?: 'none'),
            ]);
        }

        $problems = [];
        foreach ($endpoints as $endpoint) {
            if ($endpoint['status'] !== 'enabled') {
                $problems[] = "{$endpoint['url']} ({$endpoint['id']}) is {$endpoint['status']}.";

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

        $this->info(implode(', ', array_column($endpoints, 'url')).' listens for every event the app needs.');

        return self::SUCCESS;
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

    /** @param  list<string>  $problems */
    private function reportProblems(array $problems): int
    {
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        BillingLog::error('Stripe webhook setup check failed', ['problems' => $problems]);

        return self::FAILURE;
    }
}
