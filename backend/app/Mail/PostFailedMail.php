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

/**
 * The publish-failure email. One template serves both audiences — the post's
 * owner and, when configured, the ops address — so the per-platform failure
 * reasons can never differ between the two; `$forAdmin` only changes the
 * subject, the intro, a customer-details block and the footer.
 */
class PostFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Post $post;

    /** @var Collection<int, \App\Models\PostTarget> */
    public Collection $failedTargets;

    /**
     * @param  bool|null  $customerNotified  admin copy only: whether the owner's email went out
     */
    public function __construct(Post $post, Collection $failedTargets, public bool $forAdmin = false, public ?bool $customerNotified = null)
    {
        $this->post = $post;
        $this->failedTargets = $failedTargets;
    }

    public function envelope(): Envelope
    {
        $platforms = $this->failedTargets->pluck('platform')
            ->map(fn (string $p) => ucfirst($p))
            ->join(', ');

        $subject = $this->forAdmin
            ? "[ViewsMax] Post #{$this->post->id} by {$this->post->user?->email} failed to publish to {$platforms}"
            : "Your post failed to publish to {$platforms}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $historyUrl = rtrim(config('app.frontend_url'), '/').'/dashboard/post/history';
        $owner = $this->post->user;

        return new Content(
            view: 'emails.post-failed',
            with: [
                'forAdmin' => $this->forAdmin,
                'customerNotified' => $this->customerNotified,
                'captionExcerpt' => Str::limit((string) $this->post->caption, 120) ?: '(no caption)',
                'failedTargets' => $this->failedTargets,
                'historyUrl' => $historyUrl,
                'postId' => $this->post->id,
                'ownerName' => $owner?->name,
                'ownerEmail' => $owner?->email,
                'ownerId' => $owner?->id,
                'scheduledAt' => $this->post->scheduled_at?->toDayDateTimeString(),
                'createdAt' => $this->post->created_at?->toDayDateTimeString(),
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
