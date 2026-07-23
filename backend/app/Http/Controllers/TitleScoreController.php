<?php

namespace App\Http\Controllers;

use App\Models\TitleScore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TitleScoreController extends Controller
{
    public function getStatus(Request $request, string $id)
    {
        try {
            $titleScore = TitleScore::whereHas('video.channel', function($query) {
                $query->where('user_id', Auth::id());
            })->findOrFail($id);

            $analyzer = [
                'id' => $titleScore->id,
                'status' => $titleScore->status,
            ];

            // Include scores if completed
            if ($titleScore->status === 'completed') {
                $scores = $titleScore->only(TitleScore::SCORE_FIELDS);
                $scores['average_score'] = $titleScore->avg_score;
                $analyzer['scores'] = $scores;
            }

            // Include error message if failed
            if ($titleScore->status === 'failed') {
                $analyzer['error_message'] = $titleScore->error_message;
            }

            Log::info('Retrieved title score status', [
                'title_score_id' => $id,
                'user_id' => Auth::id(),
                'status' => $titleScore->status
            ]);

            return response()->json([
                'success' => true,
                'data' => $analyzer
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting title score status: ' . $e->getMessage(), [
                'title_score_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get title score status'
            ], 500);
        }
    }
}


