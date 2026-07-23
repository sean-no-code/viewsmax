<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Video;
use App\Services\YouTubeChannelService;
use App\Services\YouTubeAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    public function __construct(
        private YouTubeChannelService $youtubeService,
        private YouTubeAnalyticsService $analyticsService
    ) {}
    /**
     * Get user's connected channel(s)
     */
    public function index()
    {
        try {
            $channels = Channel::where('user_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $channels
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch channels', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch channels',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch videos from YouTube API and import them into database
     * POST /channels/{channel_id}/fetch-videos
     * Queries YouTube API using stored access token and imports videos
     */
    public function fetchVideos(Request $request, $channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);
            
            // Check if token is expired and refresh if needed
            if ($channel->youtube_token_expires_at && $channel->youtube_token_expires_at->isPast()) {
                // Try to refresh token if refresh token exists
                if ($channel->youtube_refresh_token) {
                    try {
                        $tokenData = $this->youtubeService->refreshAccessToken($channel->youtube_refresh_token);
                        
                        $channel->update([
                            'youtube_access_token' => $tokenData['access_token'],
                            'youtube_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                        ]);
                        
                        Log::info('Token refreshed for channel', [
                            'channel_id' => $channel->id
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to refresh token', [
                            'channel_id' => $channel->id,
                            'error' => $e->getMessage()
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'Access token expired. Please reconnect channel.',
                        ], 401);
                    }
                } else {
                    Log::warning('Access token expired and no refresh token', [
                        'channel_id' => $channel->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Access token expired. Please reconnect channel.',
                    ], 401);
                }
            }
            
            // Get maxTotal from request (optional)
            $maxTotal = $request->input('max_total', null);
            if ($maxTotal) {
                $maxTotal = (int) $maxTotal;
            }
            
            // Use stored access token to query YouTube API
            $videos = $this->youtubeService->getAllChannelVideos(
                $channel->youtube_access_token,
                $channel->youtube_channel_id,
                $maxTotal
            );
            
            $importedCount = 0;
            $updatedCount = 0;
            
            // Import videos and link to channel
            foreach ($videos as $videoData) {
                // Map YouTube API data to database format
                $mappedData = $this->youtubeService->mapVideoDataToDatabase($videoData);
                $mappedData['channel_id'] = $channel->id;
                
                $video = Video::updateOrCreate(
                    ['youtube_video_id' => $videoData['id']],
                    $mappedData
                );
                
                if ($video->wasRecentlyCreated) {
                    $importedCount++;
                } else {
                    $updatedCount++;
                }
            }
            
            Log::info('Videos imported', [
                'channel_id' => $channel->id,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'total_fetched' => count($videos)
            ]);
            
            // Return videos from database
            $storedVideos = Video::where('channel_id', $channel->id)
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $storedVideos,
                'imported' => $importedCount,
                'updated' => $updatedCount,
                'total' => $storedVideos->count(),
                'fetched_from_youtube' => count($videos)
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch/import videos', [
                'channel_id' => $channelId ?? null,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch videos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get channel videos from database
     * GET /channels/{channel_id}/videos
     * Returns videos that are already stored in the database
     */
    public function getVideos(Request $request, $channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);
            
            // Get pagination parameters
            $perPage = $request->input('per_page', 10);
            $perPage = min($perPage, 100); // Limit to 100 per page
            
            // Fetch videos from database with scores
            $videos = Video::where('channel_id', $channel->id)
                ->with(['titleScore', 'thumbnailScore'])
                ->orderBy('published_at', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            // Transform videos to include score information
            $transformedVideos = $videos->getCollection()->map(function ($video) {
                $videoData = $video->toArray();
                
                // Add title score info
                if ($video->titleScore) {
                    $videoData['title_score'] = [
                        'id' => $video->titleScore->id,
                        'status' => $video->titleScore->status,
                        'avg_score' => $video->titleScore->avg_score
                    ];
                }
                
                // Add thumbnail score info
                if ($video->thumbnailScore) {
                    $videoData['thumbnail_score'] = [
                        'id' => $video->thumbnailScore->id,
                        'status' => $video->thumbnailScore->status,
                        'avg_score' => $video->thumbnailScore->avg_score
                    ];
                }
                
                return $videoData;
            });
            
            return response()->json([
                'success' => true,
                'data' => $transformedVideos->values()->all(),
                'pagination' => [
                    'current_page' => $videos->currentPage(),
                    'last_page' => $videos->lastPage(),
                    'per_page' => $videos->perPage(),
                    'total' => $videos->total(),
                    'from' => $videos->firstItem(),
                    'to' => $videos->lastItem()
                ]
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get videos from database', [
                'channel_id' => $channelId ?? null,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get videos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific channel
     */
    public function show($channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);
            
            // Load relationship counts
            $channel->loadCount('videos');
            
            return response()->json([
                'success' => true,
                'data' => $channel
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch channel', [
                'channel_id' => $channelId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch channel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function refresh(Channel $channel, Request $request)
    {
        Log::info('Channel refresh started', ['channel_id' => $channel->id, 'user_id' => Auth::id()]);

        // Validate ownership
        if ($channel->user_id !== Auth::id()) {
            Log::warning('Unauthorized channel refresh attempt', [
                'channel_id' => $channel->id,
                'requesting_user_id' => Auth::id(),
                'channel_owner_id' => $channel->user_id
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            Log::info('Ensuring valid access token for channel refresh');
            // Refresh access token if needed
            $accessToken = $this->ensureValidAccessToken($channel->user);
            Log::info('Access token ready for channel refresh');

            // Refresh channel data and analytics using service
            $freshChannel = $this->youtubeService->createOrUpdateChannel(
                $channel->user,
                $accessToken,
                $channel->youtube_channel_id,
                null // No tokenData for refresh scenario
            );
            
            Log::info('Channel refresh completed successfully', [
                'channel_id' => $freshChannel->id,
                'final_video_count' => $freshChannel->video_count
            ]);

            return response()->json([
                'success' => true,
                'data' => $freshChannel,
                'message' => 'Channel data refreshed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Channel refresh failed', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to refresh channel data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function ensureValidAccessToken($user)
    {
        try {
            // Try to use existing token first
            $this->youtubeService->getChannelData($user->youtube_access_token, null);
            return $user->youtube_access_token;
        } catch (\Exception $e) {
            // Token is invalid, refresh it
            $tokenData = $this->youtubeService->refreshAccessToken($user->youtube_refresh_token);

            // Update user's access token
            $user->update([
                'youtube_access_token' => $tokenData['access_token'],
            ]);

            return $tokenData['access_token'];
        }
    }

    /**
     * Get comprehensive analytics data for a channel
     * GET /api/channels/{channelId}/analytics/comprehensive
     */
    public function getComprehensiveAnalytics($channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);

            // Return stored analytics data from JSON fields
            return response()->json([
                'success' => true,
                'data' => [
                    'playlists' => $channel->playlists ?? [],
                    'viewsOverTime' => $channel->views_over_time ?? [],
                    'audienceDemographics' => $channel->audience_demographics ?? null,
                    'watchTimeAnalytics' => $channel->watch_time_analytics ?? null,
                    'analyticsEligible' => $channel->analytics_eligible ?? false,
                    'analyticsReason' => $channel->analytics_reason,
                    'analyticsLastUpdated' => $channel->analytics_last_updated?->toISOString(),
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get comprehensive analytics', [
                'channel_id' => $channelId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get playlists for a channel
     * GET /api/channels/{channelId}/playlists
     */
    public function getPlaylists($channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);

            return response()->json([
                'success' => true,
                'data' => $channel->playlists ?? []
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get playlists', [
                'channel_id' => $channelId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get playlists: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disconnect/Delete a channel
     * DELETE /api/channels/{channelId}
     */
    public function destroy($channelId)
    {
        try {
            $channel = Channel::where('user_id', Auth::id())
                ->findOrFail($channelId);

            // Delete the channel (videos will cascade delete automatically)
            $channel->delete();

            Log::info('Channel disconnected', [
                'channel_id' => $channelId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Channel disconnected successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Channel not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to disconnect channel', [
                'channel_id' => $channelId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect channel: ' . $e->getMessage()
            ], 500);
        }
    }

}

