<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Models\Title;
use App\Models\TitleScore;
use App\Models\ThumbnailScore;
use App\Services\TitleAnalyzer;
use App\Jobs\AnalyzeTitleJob;
use App\Jobs\AnalyzeThumbnailJob;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class VideoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $perPage = min($perPage, 100); // Limit to 100 per page
            
            // Get videos through the channel relationship
            $videos = Video::with(['channel.user', 'titles'])
                ->whereHas('channel', function($query) {
                    $query->where('user_id', Auth::id());
                })
                ->select('id', 'channel_id', 'title', 'description', 'youtube_video_id', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $videos->items(),
                'pagination' => [
                    'current_page' => $videos->currentPage(),
                    'last_page' => $videos->lastPage(),
                    'per_page' => $videos->perPage(),
                    'total' => $videos->total(),
                    'from' => $videos->firstItem(),
                    'to' => $videos->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('List videos failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve videos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'description' => 'required|string|max:10000',
                'youtube_video_id' => 'required|string|max:255|unique:videos,youtube_video_id',
                'title_ids' => 'sometimes|array',
                'title_ids.*' => 'integer|exists:titles,id'
            ]);

            $video = Video::create([
                'user_id' => Auth::id(),
                'description' => $request->description,
                'youtube_video_id' => $request->youtube_video_id,
            ]);

            // Attach titles if provided
            if ($request->has('title_ids') && is_array($request->title_ids)) {
                $video->titles()->sync($request->title_ids);
            }

            // Load relationships for response
            $video->load(['user', 'titles']);

            return response()->json([
                'success' => true,
                'data' => $video,
                'message' => 'Video created successfully'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create video: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $video = Video::with(['channel.user', 'titles'])
                ->whereHas('channel', function($query) {
                    $query->where('user_id', Auth::id());
                })
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $video
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Video not found'
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $request->validate([
                'description' => 'required|string|max:10000',
                'youtube_video_id' => 'required|string|max:255|unique:videos,youtube_video_id,' . $id,
                'title_ids' => 'sometimes|array',
                'title_ids.*' => 'integer|exists:titles,id'
            ]);

            $video = Video::where('user_id', Auth::id())->findOrFail($id);
            
            $video->update([
                'description' => $request->description,
                'youtube_video_id' => $request->youtube_video_id,
            ]);

            // Update title associations if provided
            if ($request->has('title_ids')) {
                $video->titles()->sync($request->title_ids);
            }

            // Load relationships for response
            $video->load(['user', 'titles']);

            return response()->json([
                'success' => true,
                'data' => $video,
                'message' => 'Video updated successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update video: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $video = Video::where('user_id', Auth::id())->findOrFail($id);
            $video->delete();

            return response()->json([
                'success' => true,
                'message' => 'Video deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete video: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attach titles to a video.
     */
    public function attachTitles(Request $request, string $id)
    {
        try {
            $request->validate([
                'title_ids' => 'required|array',
                'title_ids.*' => 'integer|exists:titles,id'
            ]);

            $video = Video::where('user_id', Auth::id())->findOrFail($id);
            $video->titles()->syncWithoutDetaching($request->title_ids);

            // Load relationships for response
            $video->load(['user', 'titles']);

            return response()->json([
                'success' => true,
                'data' => $video,
                'message' => 'Titles attached successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error attaching titles to video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to attach titles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detach titles from a video.
     */
    public function detachTitles(Request $request, string $id)
    {
        try {
            $request->validate([
                'title_ids' => 'required|array',
                'title_ids.*' => 'integer|exists:titles,id'
            ]);

            $video = Video::where('user_id', Auth::id())->findOrFail($id);
            $video->titles()->detach($request->title_ids);

            // Load relationships for response
            $video->load(['user', 'titles']);

            return response()->json([
                'success' => true,
                'data' => $video,
                'message' => 'Titles detached successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error detaching titles from video: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to detach titles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Review a video's title and thumbnail using AI (dispatches background jobs).
     */
    public function review(Request $request, string $id)
    {
        try {
            $video = Video::whereHas('channel', function($query) {
                $query->where('user_id', Auth::id());
            })->findOrFail($id);

            Log::info('Starting video review process', [
                'video_id' => $id,
                'user_id' => Auth::id(),
                'has_title' => !empty($video->title),
                'has_youtube_id' => !empty($video->youtube_video_id)
            ]);

            $titleAnalyzer = null;
            $thumbnailAnalyzer = null;

            $reviewJobs = [];

            // Create or update title score record and dispatch job if title exists
            if (!empty($video->title)) {
                $titleScore = TitleScore::where('video_id', $video->id)->first();

                // If score already exists and is completed, return it without dispatching job
                if ($titleScore && $titleScore->status === 'completed') {
                    $titleAnalyzer = [
                        'id' => $titleScore->id,
                        'status' => 'completed',
                        'scores' => array_merge(
                            $titleScore->only(TitleScore::SCORE_FIELDS),
                            ['avg_score' => $titleScore->avg_score]
                        )
                    ];

                    Log::info('Returning existing completed title score', [
                        'video_id' => $id,
                        'title_score_id' => $titleScore->id
                    ]);
                } elseif ($titleScore && ($titleScore->status === 'processing' || $titleScore->status === 'pending')) {
                    // Already processing, return current status
                    $titleAnalyzer = [
                        'id' => $titleScore->id,
                        'status' => $titleScore->status
                    ];

                    Log::info('Title analysis job already in progress', [
                        'video_id' => $id,
                        'title_score_id' => $titleScore->id
                    ]);
                } else {
                    // No score or failed, create new and dispatch job
                    $titleScore = TitleScore::firstOrNew(['video_id' => $video->id]);
                    $titleScore->status = 'pending';
                    $titleScore->error_message = null;
                    $titleScore->save();

                    // Dispatch title analysis job
                    $reviewJobs[] = new AnalyzeTitleJob($video->id);

                    $titleAnalyzer = [
                        'id' => $titleScore->id,
                        'status' => 'processing'
                    ];

                    Log::info('Dispatched title analysis job', [
                        'video_id' => $id,
                        'title_score_id' => $titleScore->id
                    ]);
                }
               
            }else{
                return response()->json([
                    'success' => false,
                    'message' => 'Video does not have a title to review'
                ], 400);
            }

            // Create or update thumbnail score record and dispatch job if youtube_video_id exists
            if (!empty($video->youtube_video_id)) {
                $thumbnailScore = ThumbnailScore::where('video_id', $video->id)->first();

                // If score already exists and is completed, return it without dispatching job
                if ($thumbnailScore && $thumbnailScore->status === 'completed') {
                    $thumbnailAnalyzer = [
                        'id' => $thumbnailScore->id,
                        'status' => 'completed',
                        'scores' => array_merge(
                            $thumbnailScore->only(ThumbnailScore::SCORE_FIELDS),
                            ['avg_score' => $thumbnailScore->avg_score]
                        )
                    ];

                    Log::info('Returning existing completed thumbnail score', [
                        'video_id' => $id,
                        'thumbnail_score_id' => $thumbnailScore->id
                    ]);
                } elseif ($thumbnailScore && ($thumbnailScore->status === 'processing' || $thumbnailScore->status === 'pending')) {
                    // Already processing, return current status
                    $thumbnailAnalyzer = [
                        'id' => $thumbnailScore->id,
                        'status' => $thumbnailScore->status
                    ];

                    Log::info('Thumbnail analysis job already in progress', [
                        'video_id' => $id,
                        'thumbnail_score_id' => $thumbnailScore->id
                    ]);
                } else {
                    // No score or failed, create new and dispatch job
                    $thumbnailScore = ThumbnailScore::firstOrNew(['video_id' => $video->id]);
                    $thumbnailScore->status = 'pending';
                    $thumbnailScore->error_message = null;
                    $thumbnailScore->thumbnail_file_path = null;
                    $thumbnailScore->save();

                    // Dispatch thumbnail analysis job
                    $reviewJobs[] = new AnalyzeThumbnailJob($video->id);

                    $thumbnailAnalyzer = [
                        'id' => $thumbnailScore->id,
                        'status' => 'processing'
                    ];

                    Log::info('Dispatched thumbnail analysis job', [
                        'video_id' => $id,
                        'thumbnail_score_id' => $thumbnailScore->id
                    ]);
                }
            }else{
                return response()->json([
                    'success' => false,
                    'message' => 'Video does not have a youtube_video_id to review'
                ], 400);
            }


            if (empty($reviewJobs)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Video already reviewed.',
                    'data' => [
                        'video' => $video,
                        'title_analyzer' => $titleAnalyzer,
                        'thumbnail_analyzer' => $thumbnailAnalyzer
                    ]
                ]);
            }


            Log::info('Dispatching batch review jobs', [
                'video_id' => $id,
                'user_id' => Auth::id(),
                'review_jobs' => $reviewJobs
            ]);

            $user = User::find(Auth::id());
            Bus::batch($reviewJobs)
            ->name("Video Review: {$video->id}")
            ->then(function (Batch $batch) use ($id, $user) {
                Log::info('Batch review complete. Deducting credits', ['video_id' => $id]);
                $creditService = app(CreditService::class);
                $creditService->deductCreditsForOperation($user, CreditService::REVIEW_OPERATION);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($id, $user) {
                Log::error('Error Reviewing Video: ' . $e->getMessage(), [
                    'video_id' => $id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            })
            ->dispatch();

            // Load relationships for response
            $video->load(['channel.user', 'titles', 'titleScore', 'thumbnailScore']);

            return response()->json([
                'success' => true,
                'data' => [
                    'video' => $video,
                    'title_analyzer' => $titleAnalyzer,
                    'thumbnail_analyzer' => $thumbnailAnalyzer
                ],
                'message' => 'Video review started successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error starting video review: ' . $e->getMessage(), [
                'video_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start video review: ' . $e->getMessage()
            ], 500);
        }
    }
}
