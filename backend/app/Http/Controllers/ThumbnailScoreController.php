<?php

namespace App\Http\Controllers;

use App\Models\ThumbnailScore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ThumbnailScoreController extends Controller
{
    public function getStatus(Request $request, string $id)
    {
        try {
            $thumbnailScore = ThumbnailScore::whereHas('video.channel', function($query) {
                $query->where('user_id', Auth::id());
            })->findOrFail($id);

            $analyzer = [
                'id' => $thumbnailScore->id,
                'status' => $thumbnailScore->status,
            ];

            // Include scores if completed
            if ($thumbnailScore->status === 'completed') {
                $scores = $thumbnailScore->only(ThumbnailScore::SCORE_FIELDS);
                $scores['average_score'] = $thumbnailScore->avg_score;
                $analyzer['scores'] = $scores;
            }

            // Include error message if failed
            if ($thumbnailScore->status === 'failed') {
                $analyzer['error_message'] = $thumbnailScore->error_message;
            }

            Log::info('Retrieved thumbnail score status', [
                'thumbnail_score_id' => $id,
                'user_id' => Auth::id(),
                'status' => $thumbnailScore->status
            ]);

            return response()->json([
                'success' => true,
                'data' => $analyzer
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting thumbnail score status: ' . $e->getMessage(), [
                'thumbnail_score_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get thumbnail score status'
            ], 500);
        }
    }
}


