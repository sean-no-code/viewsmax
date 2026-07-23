<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'social_accounts' => SocialAccountResource::collection($this->whenLoaded('socialAccounts')),
            'connections' => $this->whenLoaded('connections', fn () => $this->connections->map->toApiArray()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
