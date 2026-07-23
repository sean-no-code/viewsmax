<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThumbnailScore extends Model
{
    /**
     * Score fields that are used for calculating average score
     */
    public const SCORE_FIELDS = [
        'face_detection', 'expression_analysis', 'object_counting', 'contrast',
        'brightness', 'saturation', 'clutter_score', 'readability_check',
        'curiosity_gap_estimation', 'story_clarity_estimation',
        'title_thumbnail_alignment', 'safety_classification', 'dimensions',
        'file_format', 'file_size', 'histogram_contrast', 'sharpness',
        'noise', 'rule_of_thirds', 'text_detection_count'
    ];

    protected $fillable = [
        'video_id',
        'face_detection',
        'expression_analysis',
        'object_counting',
        'contrast',
        'brightness',
        'saturation',
        'clutter_score',
        'readability_check',
        'curiosity_gap_estimation',
        'story_clarity_estimation',
        'title_thumbnail_alignment',
        'safety_classification',
        'dimensions',
        'file_format',
        'file_size',
        'histogram_contrast',
        'sharpness',
        'noise',
        'rule_of_thirds',
        'text_detection_count',
        'status',
        'error_message',
        'started_at',
        'completed_at',
        'thumbnail_file_path',
    ];

    protected $casts = [
        'face_detection' => 'integer',
        'expression_analysis' => 'integer',
        'object_counting' => 'integer',
        'contrast' => 'integer',
        'brightness' => 'integer',
        'saturation' => 'integer',
        'clutter_score' => 'integer',
        'readability_check' => 'integer',
        'curiosity_gap_estimation' => 'integer',
        'story_clarity_estimation' => 'integer',
        'title_thumbnail_alignment' => 'integer',
        'safety_classification' => 'integer',
        'dimensions' => 'integer',
        'file_format' => 'integer',
        'file_size' => 'integer',
        'histogram_contrast' => 'integer',
        'sharpness' => 'integer',
        'noise' => 'integer',
        'rule_of_thirds' => 'integer',
        'text_detection_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the video that owns the thumbnail score.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Scope a query to only include completed thumbnail scores.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include processing thumbnail scores.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include failed thumbnail scores.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Get the average score (computed value: total_score / number of fields)
     * 
     * @return float|null Average score rounded to 1 decimal place, or null if no scores
     */
    public function getAvgScoreAttribute(): ?float
    {
        $scoreFields = self::SCORE_FIELDS;

        $totalScore = 0;
        $fieldCount = 0;

        foreach ($scoreFields as $field) {
            $value = $this->getAttribute($field);
            if ($value !== null && $value > 0) {
                $totalScore += (int) $value;
                $fieldCount++;
            }
        }

        if ($fieldCount === 0) {
            return null;
        }

        return round($totalScore / $fieldCount, 1);
    }
}
