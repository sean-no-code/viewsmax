<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TitleScore extends Model
{
    /**
     * Score fields that are used for calculating average score
     */
    public const SCORE_FIELDS = [
        'fre', 'clarity', 'stakes', 'curiosity_gap', 'emotional_trigger',
        'concreteness', 'human_element', 'scale', 'visualizability',
        'specificity', 'no_cleverness'
    ];

    protected $fillable = [
        'video_id',
        'fre',
        'clarity',
        'stakes',
        'curiosity_gap',
        'emotional_trigger',
        'concreteness',
        'human_element',
        'scale',
        'visualizability',
        'specificity',
        'no_cleverness',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'fre' => 'integer',
        'clarity' => 'integer',
        'stakes' => 'integer',
        'curiosity_gap' => 'integer',
        'emotional_trigger' => 'integer',
        'concreteness' => 'integer',
        'human_element' => 'integer',
        'scale' => 'integer',
        'visualizability' => 'integer',
        'specificity' => 'integer',
        'no_cleverness' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the video that owns the title score.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Scope a query to only include completed title scores.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include processing title scores.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include failed title scores.
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
