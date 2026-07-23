<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiModelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'thumbnail_image' => $this->whenLoaded('thumbnail', function () {
                return $this->thumbnail ? $this->thumbnail->url : null;
            }),
            'thumbnail' => $this->whenLoaded('thumbnail', function () {
                return $this->thumbnail ? [
                    'id' => $this->thumbnail->id,
                    'name' => $this->thumbnail->name,
                    'original_name' => $this->thumbnail->original_name,
                    'url' => $this->thumbnail->url,
                    'mime_type' => $this->thumbnail->mime_type,
                    'file_size' => $this->thumbnail->file_size,
                    'human_file_size' => $this->thumbnail->human_file_size,
                    'category' => $this->thumbnail->fileCategory ? [
                        'id' => $this->thumbnail->fileCategory->id,
                        'name' => $this->thumbnail->fileCategory->name,
                    ] : null,
                    'created_at' => $this->thumbnail->created_at?->toISOString(),
                ] : null;
            }),
            'file_uploads' => $this->whenLoaded('fileUploads', function () {
                return $this->fileUploads->map(function ($fileUpload) {
                    return [
                        'id' => $fileUpload->id,
                        'name' => $fileUpload->name,
                        'original_name' => $fileUpload->original_name,
                        'url' => $fileUpload->url,
                        'mime_type' => $fileUpload->mime_type,
                        'file_size' => $fileUpload->file_size,
                        'human_file_size' => $fileUpload->human_file_size,
                        'category' => $fileUpload->fileCategory ? [
                            'id' => $fileUpload->fileCategory->id,
                            'name' => $fileUpload->fileCategory->name,
                            'slug' => $fileUpload->fileCategory->slug,
                        ] : null,
                        'created_at' => $fileUpload->created_at?->toISOString(),
                    ];
                });
            }),
            'file_upload_count' => $this->whenLoaded('fileUploads', function () {
                return $this->fileUploads->count();
            }),
            'status' => $this->status,
            'error_message' => $this->error_message,
            'replicates_prediction_id' => $this->replicates_prediction_id ?? null,
            'huggingface_model_id' => $this->huggingface_model_id ?? null,
            'huggingface_model_url' => $this->huggingface_model_url ?? null,
            'model_name' => $this->modelName(),
            'trigger_word' => $this->triggerWord(),
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
