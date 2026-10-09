<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Ops email from the queue heartbeat (App\Services\QueueHeartbeatMonitor):
 * "stuck" when heartbeat jobs stop being processed, "recovered" when they
 * resume. Deliberately NOT queued — it reports the queue being dead.
 */
class QueueHeartbeatAlertMail extends Mailable
{
    /**
     * @param  string  $event  QueueHeartbeatMonitor::EVENT_STUCK | EVENT_RECOVERED
     * @param  array  $status  QueueHeartbeatMonitor::status()
     */
    public function __construct(public string $event, public array $status) {}

    public function envelope(): Envelope
    {
        $app = config('app.name', 'ViewsMax');
        $subject = $this->event === 'recovered'
            ? "[{$app}] Queue recovered — jobs are being processed again"
            : "[{$app}] Queue is NOT processing jobs";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.queue-heartbeat',
            with: [
                'event' => $this->event,
                'status' => $this->status,
                'heartbeat' => $this->status['heartbeat'],
                'queue' => $this->status['queue'],
                'scheduler' => $this->status['scheduler'],
            ],
        );
    }
}
