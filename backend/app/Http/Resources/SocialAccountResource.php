<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array. Tokens are intentionally omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'platform_label' => config("social.platforms.{$this->platform}.label", ucfirst($this->platform)),
            'platform_account_id' => $this->platform_account_id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $this->profile_url,
            'status' => $this->status,
            // "Can it publish without user action?" — NOT raw access-token
            // expiry (expired-but-refreshable is healthy; the FE shows
            // "Reconnect" whenever this is false).
            'token_valid' => $this->hasUsableCredentials(),
            'token_expires_at' => $this->token_expires_at?->toISOString(),
            'last_error' => $this->last_error,
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'connected_at' => $this->created_at?->toISOString(),
        ];
    }
}
