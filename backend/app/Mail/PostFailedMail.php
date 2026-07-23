<?php

namespace App\Mail;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PostFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Post $post;

    /** @var Collection<int, \App\Models\PostTarget> */
    public Collection $failedTargets;

    public function __construct(Post $post, Collection $failedTargets)
    {
        $this->post = $post;
        $this->failedTargets = $failedTargets;
    }

    public function envelope(): Envelope
    {
        $platforms = $this->failedTargets->pluck('platform')
            ->map(fn (string $p) => ucfirst($p))
            ->join(', ');

        return new Envelope(
            subject: "Your post failed to publish to {$platforms}",
        );
    }

    public function content(): Content
    {
        $historyUrl = rtrim(config('app.frontend_url'), '/').'/dashboard/post/history';

        return new Content(
            view: 'emails.post-failed',
            with: [
                'captionExcerpt' => Str::limit((string) $this->post->caption, 120) ?: '(no caption)',
                'failedTargets' => $this->failedTargets,
                'historyUrl' => $historyUrl,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
