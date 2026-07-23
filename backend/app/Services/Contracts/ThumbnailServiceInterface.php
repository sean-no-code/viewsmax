<?php

namespace App\Services\Contracts;

interface ThumbnailServiceInterface
{
    /**
     * Generate a thumbnail image
     *
     * @param string $description The description to base thumbnail on
     * @param \App\Models\Thumbnail|null $thumbnail The thumbnail model with prompt references
     * @return array Array with 'image_url' and 'prompt' keys
     * @throws \Exception
     */
    public function generateThumbnailImage(string $description, ?\App\Models\Thumbnail $thumbnail = null): array;

    /**
     * Get a visualizable scene from description
     *
     * @param string $description The description to base scene on
     * @return string The visualizable scene
     * @throws \Exception
     */
    public function getVisualizableScene(string $description): string;

}

