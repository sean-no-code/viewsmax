<?php

namespace App\Mail;

use App\Support\Quota\QuotaAlerts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Ops email from App\Support\Quota\QuotaAlerts: the day's YouTube API calls
 * for a resource reached the warning percentage, the whole allocation, or
 * Google started refusing. Deliberately NOT queued, like the heartbeat's: an
 * alert must not wait behind the queue it may be reporting on.
 */
class YouTubeQuotaAlertMail extends Mailable
{
    /**
     * @param  string  $event    QuotaAlerts::EVENT_WARNING | EVENT_EXHAUSTED | EVENT_REFUSED
     * @param  array   $context  resource, used, limit, percent, resets_at
     */
    public function __construct(public string $event, public array $context) {}

    public function envelope(): Envelope
    {
        $app = config('app.name', 'ViewsMax');
        $resource = $this->context['resource'];
        $subject = match ($this->event) {
            QuotaAlerts::EVENT_REFUSED => "[{$app}] YouTube is refusing {$resource} calls — daily quota exceeded",
            QuotaAlerts::EVENT_EXHAUSTED => "[{$app}] YouTube {$resource} quota used up for today ({$this->context['used']}/{$this->context['limit']})",
            default => "[{$app}] YouTube {$resource} quota at {$this->context['percent']}% ({$this->context['used']}/{$this->context['limit']})",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.youtube-quota-alert', with: ['event' => $this->event] + $this->context);
    }
}
