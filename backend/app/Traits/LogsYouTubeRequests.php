<?php

namespace App\Traits;

use App\Services\YouTubeCreditTracker;
use Illuminate\Support\Facades\Log;

trait LogsYouTubeRequests
{
    /**
     * Log a YouTube API request with estimated credit usage.
     *
     * @param string $endpoint The API endpoint (e.g., 'search', 'videos', 'channels').
     * @param array $params The query parameters for the request.
     * @return void
     */
    protected function logYouTubeRequest(string $endpoint, array $params = []): void
    {
        $creditCost = 1; // Default cost

        // Determine credit cost based on endpoint
        if (str_contains($endpoint, '/search')) {
            $creditCost = 100;
        } elseif (str_contains($endpoint, '/videos')) {
            $creditCost = 1;
        } elseif (str_contains($endpoint, '/channels')) {
            $creditCost = 1;
        } elseif (str_contains($endpoint, '/playlists')) {
            $creditCost = 1;
        } elseif (str_contains($endpoint, '/playlistItems')) {
            $creditCost = 1;
        }

        YouTubeCreditTracker::add($creditCost);

        // Simplified Logging
        $message = 'YouTube API [outliers]: ';

        if (str_contains($endpoint, '/search')) {
            $term     = $params['q'] ?? 'unknown';
            $message .= "Searching for term \"{$term}\" (Est. Cost: {$creditCost})";
        } elseif (str_contains($endpoint, '/videos')) {
            $ids     = $params['id'] ?? '';
            $count   = substr_count($ids, ',') + 1;
            $message .= "Fetching details for {$count} video(s) (Est. Cost: {$creditCost})";
        } elseif (str_contains($endpoint, '/channels')) {
            $id      = $params['id'] ?? 'unknown';
            $message .= "Fetching channel data for {$id} (Est. Cost: {$creditCost})";
        } elseif (str_contains($endpoint, '/playlistItems')) {
            $playlistId = $params['playlistId'] ?? 'unknown';
            $message   .= "Fetching playlist items for {$playlistId} (Est. Cost: {$creditCost})";
        } elseif (str_contains($endpoint, '/playlists')) {
            $channelId = $params['channelId'] ?? 'unknown';
            $message  .= "Fetching playlists for channel {$channelId} (Est. Cost: {$creditCost})";
        } else {
            $message .= "Requesting {$endpoint} (Est. Cost: {$creditCost})";
        }

        Log::info($message);
    }
}
