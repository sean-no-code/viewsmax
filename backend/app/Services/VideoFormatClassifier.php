<?php

namespace App\Services;

use App\Models\OutlierVideo;

/**
 * Decides whether an outlier video is a Short (short-form) or long-form.
 *
 * YouTube goes by the stored duration — the same ≤ SHORTS_MAX_SECONDS rule the
 * search query's fallback and the channel sampler already use — so no request
 * ever leaves the box (the old HEAD youtube.com/shorts/{id} probe hit bot walls
 * and consent redirects). A 0s duration is a live stream / premiere, which is
 * long-form; a missing duration is unknown and left for a later backfill once
 * the row has one. TikTok and Instagram are short-form by definition.
 */
class VideoFormatClassifier
{
    /** true = Short, false = long-form, null = unknown (no duration yet). */
    public function classify(OutlierVideo $video): ?bool
    {
        if (OutlierVideo::platformIsAlwaysShort($video->platform)) {
            return true;
        }

        if ($video->platform !== 'youtube') {
            return null;
        }

        $seconds = $video->duration_in_seconds;
        if ($seconds === null) {
            return null;
        }

        return $seconds >= 1 && $seconds <= OutlierVideo::SHORTS_MAX_SECONDS;
    }

    /**
     * Classify once and persist. No-op for rows already classified; stamps
     * format_checked_at even when the answer is unknown so backfills don't
     * re-check the same duration-less row forever.
     */
    public function ensureClassified(OutlierVideo $video): ?bool
    {
        if ($video->is_short !== null) {
            return $video->is_short;
        }

        $result = $this->classify($video);

        $video->forceFill(['is_short' => $result, 'format_checked_at' => now()])->save();

        return $result;
    }
}
