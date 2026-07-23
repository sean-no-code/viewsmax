<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'media' => $this->media ?? [],
            'link' => $this->link,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'targets' => $this->whenLoaded('targets', function () {
                return $this->targets->map(fn ($target) => [
                    'id' => $target->id,
                    'social_account_id' => $target->social_account_id,
                    'platform' => $target->platform,
                    'status' => $target->status,
                    'remote_post_id' => $target->remote_post_id,
                    'remote_post_url' => $target->remote_post_url,
                    'error' => $target->error,
                    'published_at' => $target->published_at?->toISOString(),
                ]);
            }),
        ];
    }
}
