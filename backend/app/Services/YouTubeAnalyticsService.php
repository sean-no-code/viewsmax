<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeAnalyticsService
{
    private const API_BASE_URL = 'https://youtubeanalytics.googleapis.com/v2';

    /**
     * Get channel analytics data including average watch time
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @param string|null $startDate Start date (YYYY-MM-DD format). If null, defaults to 30 days ago
     * @param string|null $endDate End date (YYYY-MM-DD format). If null, defaults to yesterday
     * @return array Analytics data
     * @throws \Exception
     */
    public function getChannelAnalytics(string $accessToken, string $channelId, ?string $startDate = null, ?string $endDate = null): array
    {
        // Calculate default dates if not provided (YouTube Analytics API requires YYYY-MM-DD format)
        if ($startDate === null) {
            $startDate = now()->subDays(30)->format('Y-m-d');
        }
        if ($endDate === null) {
            $endDate = now()->subDay()->format('Y-m-d');
        }

        $params = [
            'ids' => 'channel==' . $channelId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'metrics' => 'views,estimatedMinutesWatched,averageViewDuration',
            'dimensions' => 'day',
            'sort' => 'day'
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/reports', $params);

        if (!$response->successful()) {
            $statusCode = $response->status();
            $errorBody = $response->body();

            Log::error('YouTube Analytics API error', [
                'status' => $statusCode,
                'body' => $errorBody,
                'channel_id' => $channelId
            ]);

            // Handle specific error cases
            if ($statusCode === 403) {
                throw new \Exception('YouTube Analytics API access not available. This requires channel monetization and sufficient analytics history.');
            }

            throw new \Exception('Failed to fetch analytics data: ' . $errorBody);
        }

        $data = $response->json();

        // Response structure when dimension='day':
        // row[0] = date (dimension value)
        // row[1] = views (first metric)
        // row[2] = estimatedMinutesWatched (second metric)
        // row[3] = averageViewDuration (third metric, in seconds)

        // Calculate average watch time across all days
        // averageViewDuration is already the average per view for each day
        // We need to calculate a weighted average across all days
        $totalViews = 0;
        $totalWatchTime = 0;

        foreach ($data['rows'] ?? [] as $row) {
            $views = $row[1] ?? 0; // views for this day
            $avgDuration = $row[3] ?? 0; // averageViewDuration in seconds for this day
            $totalViews += $views;
            $totalWatchTime += $views * $avgDuration; // weighted watch time
        }

        $avgWatchTime = $totalViews > 0 ? round($totalWatchTime / $totalViews) : 0;

        $analyticsResult = [
            'avg_watch_time' => $avgWatchTime,
            'total_views' => $totalViews,
            'estimated_minutes_watched' => array_sum(array_column($data['rows'] ?? [], 2)) ?? 0,
            'raw_data' => $data
        ];

        // Log analytics fetch results
        Log::info('YouTube Analytics data fetched successfully', [
            'channel_id' => $channelId,
            'date_range' => $startDate . ' to ' . $endDate,
            'avg_watch_time_seconds' => $avgWatchTime,
            'total_views' => $totalViews,
            'data_points' => count($data['rows'] ?? []),
            'has_analytics_data' => !empty($data['rows'])
        ]);

        return $analyticsResult;
    }

    /**
     * Get real-time analytics (last 28 days)
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Real-time analytics
     */
    public function getRealtimeAnalytics(string $accessToken, string $channelId): array
    {
        $startDate = now()->subDays(28)->format('Y-m-d');
        $endDate = now()->subDay()->format('Y-m-d');
        return $this->getChannelAnalytics($accessToken, $channelId, $startDate, $endDate);
    }

    /**
     * Check if channel is eligible for YouTube Analytics API
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Eligibility status and reason
     */
    public function checkAnalyticsEligibility(string $accessToken, string $channelId): array
    {
        try {
            // Try to fetch a simple analytics query to check eligibility
            $startDate = now()->subDays(30)->format('Y-m-d');
            $endDate = now()->subDay()->format('Y-m-d');

            $params = [
                'ids' => 'channel==' . $channelId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'metrics' => 'views',
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get(self::API_BASE_URL . '/reports', $params);

            if ($response->successful()) {
                return [
                    'eligible' => true,
                    'reason' => null
                ];
            }

            // Check for specific error codes
            $statusCode = $response->status();
            $errorBody = $response->json();

            if ($statusCode === 403) {
                $errorMessage = $errorBody['error']['message'] ?? 'Access denied to YouTube Analytics API';
                return [
                    'eligible' => false,
                    'reason' => $errorMessage
                ];
            }

            return [
                'eligible' => false,
                'reason' => 'Unknown error: ' . ($errorBody['error']['message'] ?? 'Failed to check eligibility')
            ];

        } catch (\Exception $e) {
            Log::warning('Analytics eligibility check failed', [
                'channel_id' => $channelId,
                'error' => $e->getMessage()
            ]);

            return [
                'eligible' => false,
                'reason' => $e->getMessage()
            ];
        }
    }

    /**
     * Get views over time data (30-day time series)
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Views over time data formatted for storage
     */
    public function getViewsOverTimeData(string $accessToken, string $channelId): array
    {
        $startDate = now()->subDays(30)->format('Y-m-d');
        $endDate = now()->subDay()->format('Y-m-d');

        $params = [
            'ids' => 'channel==' . $channelId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'metrics' => 'views,estimatedMinutesWatched,averageViewDuration',
            'dimensions' => 'day',
            'sort' => 'day'
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/reports', $params);

        if (!$response->successful()) {
            Log::error('YouTube Analytics views over time error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId
            ]);
            throw new \Exception('Failed to fetch views over time: ' . $response->body());
        }

        $data = $response->json();
        $viewsOverTime = [];

        foreach ($data['rows'] ?? [] as $row) {
            // row[0] = date, row[1] = views, row[2] = estimatedMinutesWatched, row[3] = averageViewDuration
            $viewsOverTime[] = [
                'date' => $row[0] ?? null,
                'views' => (int)($row[1] ?? 0),
                'estimatedMinutesWatched' => (int)($row[2] ?? 0),
                'averageViewDuration' => (int)($row[3] ?? 0), // in seconds
            ];
        }

        Log::info('Views over time data fetched successfully', [
            'channel_id' => $channelId,
            'data_points' => count($viewsOverTime)
        ]);

        return $viewsOverTime;
    }

    /**
     * Get audience demographics data
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Audience demographics formatted for storage
     */
    public function getAudienceDemographicsData(string $accessToken, string $channelId): array
    {
        $startDate = now()->subDays(30)->format('Y-m-d');
        $endDate = now()->subDay()->format('Y-m-d');

        $demographics = [
            'ageGroups' => [],
            'gender' => [],
            'topCountries' => []
        ];

        // Fetch age groups
        try {
            $ageParams = [
                'ids' => 'channel==' . $channelId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'metrics' => 'viewerPercentage',
                'dimensions' => 'ageGroup',
            ];

            $ageResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get(self::API_BASE_URL . '/reports', $ageParams);

            if ($ageResponse->successful()) {
                $ageData = $ageResponse->json();
                foreach ($ageData['rows'] ?? [] as $row) {
                    $ageGroup = $row[0] ?? '';
                    $percentage = (float)($row[1] ?? 0);
                    
                    // Map YouTube age groups to frontend format
                    $mappedKey = $this->mapAgeGroup($ageGroup);
                    if ($mappedKey) {
                        $demographics['ageGroups'][$mappedKey] = $percentage;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch age groups', ['error' => $e->getMessage()]);
        }

        // Fetch gender data
        try {
            $genderParams = [
                'ids' => 'channel==' . $channelId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'metrics' => 'viewerPercentage',
                'dimensions' => 'gender',
            ];

            $genderResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get(self::API_BASE_URL . '/reports', $genderParams);

            if ($genderResponse->successful()) {
                $genderData = $genderResponse->json();
                foreach ($genderData['rows'] ?? [] as $row) {
                    $gender = strtolower($row[0] ?? '');
                    $percentage = (float)($row[1] ?? 0);
                    
                    if (in_array($gender, ['male', 'female', 'other'])) {
                        $demographics['gender'][$gender] = $percentage;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch gender data', ['error' => $e->getMessage()]);
        }

        // Fetch top countries
        try {
            $countryParams = [
                'ids' => 'channel==' . $channelId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'metrics' => 'views',
                'dimensions' => 'country',
                'sort' => '-views',
                'maxResults' => 10,
            ];

            $countryResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get(self::API_BASE_URL . '/reports', $countryParams);

            if ($countryResponse->successful()) {
                $countryData = $countryResponse->json();
                $totalViews = array_sum(array_column($countryData['rows'] ?? [], 1));

                foreach ($countryData['rows'] ?? [] as $row) {
                    $country = $row[0] ?? '';
                    $views = (int)($row[1] ?? 0);
                    $percentage = $totalViews > 0 ? ($views / $totalViews) * 100 : 0;

                    $demographics['topCountries'][] = [
                        'country' => $country,
                        'views' => $views,
                        'percentage' => round($percentage, 2)
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch country data', ['error' => $e->getMessage()]);
        }

        Log::info('Audience demographics data fetched successfully', [
            'channel_id' => $channelId,
            'has_age_groups' => !empty($demographics['ageGroups']),
            'has_gender' => !empty($demographics['gender']),
            'countries_count' => count($demographics['topCountries'])
        ]);

        return $demographics;
    }

    /**
     * Map YouTube age group format to frontend format
     *
     * @param string $youtubeAgeGroup YouTube age group (e.g., "age13-17", "age18-24")
     * @return string|null Mapped key (e.g., "age13_17", "age18_24") or null
     */
    private function mapAgeGroup(string $youtubeAgeGroup): ?string
    {
        $mapping = [
            'age13-17' => 'age13_17',
            'age18-24' => 'age18_24',
            'age25-34' => 'age25_34',
            'age35-44' => 'age35_44',
            'age45-54' => 'age45_54',
            'age55-64' => 'age55_64',
            'age65-' => 'age65_',
        ];

        return $mapping[$youtubeAgeGroup] ?? null;
    }

    /**
     * Get watch time analytics data
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Watch time analytics formatted for storage
     */
    public function getWatchTimeAnalyticsData(string $accessToken, string $channelId): array
    {
        $startDate = now()->subDays(30)->format('Y-m-d');
        $endDate = now()->subDay()->format('Y-m-d');

        $params = [
            'ids' => 'channel==' . $channelId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'metrics' => 'averageViewDuration,estimatedMinutesWatched',
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(self::API_BASE_URL . '/reports', $params);

        if (!$response->successful()) {
            Log::error('YouTube Analytics watch time error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'channel_id' => $channelId
            ]);
            throw new \Exception('Failed to fetch watch time analytics: ' . $response->body());
        }

        $data = $response->json();
        
        // When no dimensions, row[0] = averageViewDuration, row[1] = estimatedMinutesWatched
        $row = $data['rows'][0] ?? [0, 0];
        $averageViewDurationSeconds = (int)($row[0] ?? 0);
        $estimatedMinutesWatched = (int)($row[1] ?? 0);
        $totalWatchTimeHours = round($estimatedMinutesWatched / 60, 2);

        // Format average view duration as "M:SS" or "H:MM:SS"
        $hours = floor($averageViewDurationSeconds / 3600);
        $minutes = floor(($averageViewDurationSeconds % 3600) / 60);
        $seconds = $averageViewDurationSeconds % 60;

        if ($hours > 0) {
            $averageViewDuration = sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            $averageViewDuration = sprintf('%d:%02d', $minutes, $seconds);
        }

        $watchTimeAnalytics = [
            'averageViewDuration' => $averageViewDuration,
            'averageViewDurationSeconds' => $averageViewDurationSeconds, // Store seconds for easy access (replaces avg_watch_time column)
            'totalWatchTimeHours' => $totalWatchTimeHours
        ];

        Log::info('Watch time analytics data fetched successfully', [
            'channel_id' => $channelId,
            'average_view_duration' => $averageViewDuration,
            'total_watch_time_hours' => $totalWatchTimeHours
        ]);

        return $watchTimeAnalytics;
    }

    /**
     * Get comprehensive analytics data (all analytics in one call)
     *
     * @param string $accessToken OAuth access token
     * @param string $channelId YouTube channel ID
     * @return array Comprehensive analytics data
     */
    public function getComprehensiveAnalytics(string $accessToken, string $channelId): array
    {
        // Check eligibility first
        $eligibility = $this->checkAnalyticsEligibility($accessToken, $channelId);

        if (!$eligibility['eligible']) {
            return [
                'analytics_eligible' => false,
                'analytics_reason' => $eligibility['reason'],
                'views_over_time' => null,
                'audience_demographics' => null,
                'watch_time_analytics' => null,
            ];
        }

        // Fetch all analytics data
        $viewsOverTime = null;
        $demographics = null;
        $watchTime = null;

        try {
            $viewsOverTime = $this->getViewsOverTimeData($accessToken, $channelId);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch views over time', ['error' => $e->getMessage()]);
        }

        try {
            $demographics = $this->getAudienceDemographicsData($accessToken, $channelId);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch demographics', ['error' => $e->getMessage()]);
        }

        try {
            $watchTime = $this->getWatchTimeAnalyticsData($accessToken, $channelId);
        } catch (\Exception $e) {
            Log::warning('Failed to fetch watch time analytics', ['error' => $e->getMessage()]);
        }

        return [
            'analytics_eligible' => true,
            'analytics_reason' => null,
            'views_over_time' => $viewsOverTime,
            'audience_demographics' => $demographics,
            'watch_time_analytics' => $watchTime,
        ];
    }
}
