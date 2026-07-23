<?php

namespace App\Http\Controllers;

use App\Models\Script;
use App\Models\Video;
use App\Models\VideoTranscription;
use App\Services\ScriptHelper;
use App\Services\YouTubeTranscriptService;
use App\Jobs\GenerateScriptJob;
use App\Jobs\GenerateScriptResearchJob;
use App\Jobs\UpdateScriptJob;
use App\Jobs\ProcessVideoTranscriptionJob;
use App\Models\ScriptVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ScriptController extends Controller
{
    private ScriptHelper $scriptHelper;
    private YouTubeTranscriptService $transcriptService;

    public function __construct(ScriptHelper $scriptHelper, YouTubeTranscriptService $transcriptService)
    {
        $this->scriptHelper = $scriptHelper;
        $this->transcriptService = $transcriptService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $perPage = min($perPage, 100); // Limit to 100 per page
            
            $wordsPerMinute = config('app.words_per_minute', 130);
            
            $scripts = Script::forUser(Auth::id())
                ->select('id', 'title', 'text', 'length', 'word_count', 'status', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Calculate reading time for each script
            $scriptsData = collect($scripts->items())->map(function ($script) use ($wordsPerMinute) {
                $wordCount = $script->word_count ?? ($script->text ? str_word_count($script->text) : 0);
                $readingTime = $wordCount > 0 && $wordsPerMinute > 0 
                    ? round($wordCount / $wordsPerMinute) 
                    : 0;
                
                return [
                    'id' => $script->id,
                    'title' => $script->title,
                    'text' => $script->text,
                    'length' => $script->length,
                    'word_count' => $wordCount,
                    'reading_time' => $readingTime,
                    'status' => $script->status,
                    'created_at' => $script->created_at,
                    'updated_at' => $script->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $scriptsData,
                'pagination' => [
                    'current_page' => $scripts->currentPage(),
                    'last_page' => $scripts->lastPage(),
                    'per_page' => $scripts->perPage(),
                    'total' => $scripts->total(),
                    'from' => $scripts->firstItem(),
                    'to' => $scripts->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('List scripts failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve scripts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
public function store(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        
        Log::info('ScriptController::store started', [
            'user_id' => $userId,
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $request->validate([
            'title' => 'required|string|max:255',
            'prompt' => 'required|string',
            'youtube_urls' => 'sometimes|array',
            'youtube_urls.*' => 'url|max:500',
            'script_length' => 'sometimes|integer|min:1|max:60', // Optional: 1-60 minutes
        ]);

        try {
            $title = $request->title; // Now required by validation
            $prompt = $request->prompt; // Required by validation
            $youtubeUrls = $request->input('youtube_urls', []);
            
            Log::info('Creating script', [
                'user_id' => $userId,
                'title' => $title,
                'prompt_length' => strlen($prompt),
                'youtube_urls_count' => count($youtubeUrls),
                'script_length' => $request->input('script_length'),
            ]);

            // Create script record with processing status
            $scriptData = [
                'user_id' => $userId,
                'title' => $title,
                'prompt' => $prompt,
                'status' => 'pending'
            ];
            
            // Add script_length if provided
            if ($request->has('script_length')) {
                $scriptData['length'] = $request->input('script_length');
            }
            
            $script = Script::create($scriptData);

            // Create initial research record with processing status
            $script->research()->create([
                'status' => 'processing',
            ]);

            // Dispatch job to generate real research
            GenerateScriptResearchJob::dispatch($script->id);

            // Process YouTube URLs if provided
            $videoIds = [];
            if (!empty($youtubeUrls)) {
                foreach ($youtubeUrls as $url) {
                    Log::warning('Video URL to extract', [
                        'url' => $url,
                        'script_id' => $script->id
                    ]);
                    $videoId = $this->transcriptService->extractVideoId($url);
                    
                    if (!$videoId) {
                        Log::info('Failed to extract video ID from URL', [
                            'url' => $url,
                            'script_id' => $script->id
                        ]);
                        continue;
                    }

                    Log::info('Extracted video ID from URL', [
                        'url' => $url,
                        'video_id' => $videoId,
                        'script_id' => $script->id
                    ]);

                    // Find or create video in database
                    $video = Video::firstOrCreate(
                        ['youtube_video_id' => $videoId]
                    );

                    // Attach video to script
                    if (!$script->videos()->where('video_id', $video->id)->exists()) {
                        $script->videos()->attach($video->id);
                        Log::info('Video attached to script', [
                            'script_id' => $script->id,
                            'video_id' => $video->id,
                            'youtube_video_id' => $videoId
                        ]);
                    }

                    $videoIds[] = $video->id;

                    // Find or create transcription record for the video
                    $transcription = VideoTranscription::firstOrCreate(
                        ['video_id' => $video->id],
                        [
                            'file_location' => null,
                            'processed_at' => null
                        ]
                    );

                    // Link transcription to script via join table
                    DB::table('scripts_videos_transcriptions')->insertOrIgnore([
                        'script_id' => $script->id,
                        'video_transcription_id' => $transcription->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    Log::info('Video transcription linked to script', [
                        'script_id' => $script->id,
                        'video_id' => $video->id,
                        'transcription_id' => $transcription->id,
                        'has_file_location' => !empty($transcription->file_location),
                        'processed_at' => $transcription->processed_at,
                        'was_recently_created' => $transcription->wasRecentlyCreated
                    ]);

                    // Dispatch job to process transcription if it doesn't have file_location
                    // Note: firstOrCreate only fires 'created' event for new records,
                    // so we need to manually dispatch for existing records that need processing
                    // REMOVED: Do not dispatch job here. Delayed until generation stage.
                    // if (empty($transcription->file_location)) {
                    //    ProcessVideoTranscriptionJob::dispatch($transcription->id);
                    // }
                }
            }

            Log::info('Script record created', [
                'script_id' => $script->id,
                'user_id' => $userId,
                'status' => 'processing',
                'created_at' => $script->created_at,
                'videos_count' => count($videoIds)
            ]);

            // Check if all transcriptions are ready (have file_location and processed_at)
            $allTranscriptionsReady = true;
            if (!empty($videoIds)) {
                Log::info('Checking transcription readiness for script', [
                    'script_id' => $script->id,
                    'video_ids_count' => count($videoIds)
                ]);

                $transcriptions = DB::table('scripts_videos_transcriptions')
                    ->where('script_id', $script->id)
                    ->join('video_transcriptions', 'scripts_videos_transcriptions.video_transcription_id', '=', 'video_transcriptions.id')
                    ->select('video_transcriptions.id', 'video_transcriptions.file_location', 'video_transcriptions.processed_at')
                    ->get();

                Log::info('Found transcriptions to check', [
                    'script_id' => $script->id,
                    'transcriptions_count' => $transcriptions->count()
                ]);

                foreach ($transcriptions as $index => $transcription) {
                    $hasFileLocation = !empty($transcription->file_location);
                    $hasProcessedAt = !empty($transcription->processed_at);
                    
                    Log::debug('Checking transcription', [
                        'script_id' => $script->id,
                        'transcription_id' => $transcription->id,
                        'index' => $index + 1,
                        'has_file_location' => $hasFileLocation,
                        'has_processed_at' => $hasProcessedAt,
                        'file_location' => $transcription->file_location,
                        'processed_at' => $transcription->processed_at
                    ]);

                    if (!$hasFileLocation || !$hasProcessedAt) {
                        $allTranscriptionsReady = false;
                        
                        Log::warning('Transcription not ready', [
                            'script_id' => $script->id,
                            'transcription_id' => $transcription->id,
                            'missing_file_location' => !$hasFileLocation,
                            'missing_processed_at' => !$hasProcessedAt
                        ]);
                        
                        break;
                    }
                }

                Log::info('Transcription readiness check completed', [
                    'script_id' => $script->id,
                    'all_transcriptions_ready' => $allTranscriptionsReady,
                    'total_transcriptions_checked' => $transcriptions->count()
                ]);
            } else {
                Log::info('No video IDs provided, skipping transcription readiness check', [
                    'script_id' => $script->id
                ]);
            }

            // Do NOT dispatch script generation job here. 
            // The frontend will call the generate endpoint explicitly when ready.
            Log::info('Script created. Waiting for user to initiate generation.', [
                'script_id' => $script->id,
                'videos_count' => count($videoIds)
            ]);


            $totalTime = microtime(true) - $startTime;

            Log::info('ScriptController::store completed successfully', [
                'script_id' => $script->id,
                'user_id' => $userId,
                'total_time_seconds' => round($totalTime, 3),
                'response_status' => 201
            ]);

            // Load videos and research for response
            $script->load(['videos', 'research']);
            
            return response()->json([
                'success' => true,
                'data' => $script,
                'message' => 'Script created successfully. Script generation is processing in the background.'
            ], 201);

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('ScriptController::store failed', [
                'user_id' => $userId,
                'prompt' => $request->input('prompt', ''),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_time_seconds' => round($totalTime, 3),
                'script_id' => isset($script) ? $script->id : null
            ]);
            
            // Update script status to failed if it was created
            if (isset($script)) {
                try {
                    $script->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ]);
                    
                    Log::info('Script status updated to failed', [
                        'script_id' => $script->id,
                        'error_message' => $e->getMessage()
                    ]);
                } catch (\Exception $updateException) {
                    Log::error('Failed to update script status to failed', [
                        'script_id' => $script->id,
                        'update_error' => $updateException->getMessage()
                    ]);
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create script: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $script = Script::forUser(Auth::id())->with(['research', 'videos', 'components'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $script
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving script: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve script: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'prompt' => 'sometimes|required|string',
            'text' => 'sometimes|required|string',
            'regenerate' => 'sometimes|boolean',
            'refine' => 'sometimes|boolean'
        ]);

        try {
            $script = Script::forUser(Auth::id())->findOrFail($id);
            
            // If refine is requested, dispatch a job to refine the script (preserves context)
            if ($request->has('refine') && $request->refine) {
                $startTime = microtime(true);
                $userId = Auth::id();
                
                Log::info('ScriptController::update - refinement requested', [
                    'script_id' => $script->id,
                    'user_id' => $userId,
                    'current_status' => $script->status,
                    'request_ip' => $request->ip()
                ]);

                // Update prompt and any text provided, set status to processing
                $updateData = $request->only(['prompt', 'text']);
                $updateData['status'] = 'processing';
                $updateData['error_message'] = null; // Clear any previous errors
                
                $script->update($updateData);
                
                Log::info('Script updated for refinement, status set to processing', [
                    'script_id' => $script->id,
                    'user_id' => $userId
                ]);
                
                // Dispatch job to refine script asynchronously
                UpdateScriptJob::dispatch($script->id);
                
                Log::info('Script refinement job dispatched successfully', [
                    'script_id' => $script->id,
                    'user_id' => $userId,
                    'dispatch_time' => now()->toISOString()
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $script->fresh(),
                    'message' => 'Script refinement started. Processing in the background.'
                ]);
            }

            // If regenerate is requested, dispatch a job to regenerate the script (from scratch)
            if ($request->has('regenerate') && $request->regenerate) {
                $startTime = microtime(true);
                $userId = Auth::id();
                
                Log::info('ScriptController::update - regeneration requested', [
                    'script_id' => $script->id,
                    'user_id' => $userId,
                    'current_status' => $script->status,
                    'request_ip' => $request->ip()
                ]);

                $script->update(['status' => 'processing']);
                
                Log::info('Script status updated to processing for regeneration', [
                    'script_id' => $script->id,
                    'user_id' => $userId
                ]);
                
                // Dispatch job to regenerate script asynchronously
                GenerateScriptJob::dispatch($script->id);
                
                Log::info('Script regeneration job dispatched successfully', [
                    'script_id' => $script->id,
                    'user_id' => $userId,
                    'dispatch_time' => now()->toISOString(),
                    'regeneration_time_seconds' => round(microtime(true) - $startTime, 3)
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $script->fresh(),
                    'message' => 'Script regeneration started. Processing in the background.'
                ]);
            }
            
            // Regular update for title, prompt, and text
            $updateData = $request->only(['title', 'prompt', 'text']);
            
            // Regular update for title, prompt, and text
            // If text is being updated, calculate word count
            if (isset($updateData['text']) && !empty($updateData['text'])) {
                $updateData['word_count'] = str_word_count($updateData['text']);
            }
            
            $script->update($updateData);

            return response()->json([
                'success' => true,
                'data' => $script,
                'message' => 'Script updated successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating script: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update script: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save script (Autosave)
     */
    public function save(Request $request, string $id)
    {
        try {
            $script = Script::forUser(Auth::id())->findOrFail($id);
            
            $request->validate([
                'text' => 'required|string',
            ]);

            $newText = $request->input('text');
            $wordCount = str_word_count($newText);
            
            // Update script first
            $script->update([
                'text' => $newText,
                'word_count' => $wordCount,
            ]);

            // --- Versioning Logic ---
            $intervalSeconds = config('app.script_version_interval', 300); // Default 5 mins
            
            // Get the latest version for this script
            $latestVersion = ScriptVersion::where('script_id', $script->id)
                ->orderBy('created_at', 'desc')
                ->first();

            $shouldCreateVersion = false;

            if (!$latestVersion) {
                // No version exists yet, create one
                $shouldCreateVersion = true;
            } else {
                // Check interval
                $secondsSinceLastVersion = Carbon::parse($latestVersion->created_at)->diffInSeconds(now());
                
                if ($secondsSinceLastVersion >= $intervalSeconds) {
                    // Check content hash (Strict Deduplication)
                    $newHash = hash('sha256', $newText);
                    
                    if ($newHash !== $latestVersion->content_hash) {
                        $shouldCreateVersion = true;
                    }
                }
            }

            if ($shouldCreateVersion) {
                Log::info('Creating new script version (Autosave)', [
                    'script_id' => $script->id,
                    'user_id' => Auth::id()
                ]);

                ScriptVersion::create([
                    'script_id' => $script->id,
                    'user_id' => Auth::id(),
                    'content' => $newText, // Model handles compression & hashing
                ]);
                
                // --- Inline Pruning Logic ---
                $versionLimit = config('app.script_version_limit', 50);
                
                $count = ScriptVersion::where('script_id', $script->id)->count();
                
                if ($count > $versionLimit) {
                    try {  
                        $keepIds = ScriptVersion::where('script_id', $script->id)
                            ->orderBy('created_at', 'desc')
                            ->limit($versionLimit)
                            ->pluck('id');
                            
                        if ($keepIds->isNotEmpty()) {
                            ScriptVersion::where('script_id', $script->id)
                                ->whereNotIn('id', $keepIds)
                                ->delete();
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to prune script versions: ' . $e->getMessage());
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $script->fresh(),
                'message' => 'Script saved successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error saving script: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save script'
            ], 500);
        }
    }



    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $script = Script::forUser(Auth::id())->findOrFail($id);
            $script->delete();

            return response()->json([
                'success' => true,
                'message' => 'Script deleted successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting script: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete script: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get script status
     */
    public function status(string $id)
    {
        try {
            $script = Script::forUser(Auth::id())->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $script->id,
                    'status' => $script->status,
                    'error_message' => $script->error_message
                ],
                'message' => 'Script status retrieved successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving script status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve script status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get script version history
     */
    public function history(string $id)
    {
        try {
            $script = Script::forUser(Auth::id())->findOrFail($id);

            $versions = ScriptVersion::where('script_id', $script->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($version) {
                    return [
                        'id' => $version->id,
                        'script_id' => $version->script_id,
                        'user_id' => $version->user_id,
                        'content' => $version->content,
                        'content_preview' => substr($version->content, 0, 200) . (strlen($version->content) > 200 ? '...' : ''),
                        'created_at' => $version->created_at->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $versions,
                'message' => 'Script history retrieved successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Script not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving script history: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve script history'
            ], 500);
        }
    }
}