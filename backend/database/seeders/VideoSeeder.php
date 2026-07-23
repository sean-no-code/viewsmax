<?php

namespace Database\Seeders;

use App\Models\Video;
use App\Models\User;
use App\Models\Title;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class VideoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users and titles for seeding
        $users = User::all();
        $titles = Title::all();
        
        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please run UserSeeder first.');
            return;
        }

        $videos = [
            [
                'description' => 'Complete Guide to AI and Machine Learning - From Beginner to Expert',
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'title_ids' => [1, 2, 3] // Will be adjusted based on available titles
            ],
            [
                'description' => 'Digital Marketing Masterclass: Grow Your Business Online in 2024',
                'youtube_video_id' => 'jNQXAC9IVRw',
                'title_ids' => [4, 5, 6]
            ],
            [
                'description' => 'Personal Finance and Investment Strategies for Financial Freedom',
                'youtube_video_id' => 'M7lc1UVf-VE',
                'title_ids' => [7, 8, 9]
            ],
            [
                'description' => 'Health and Fitness Transformation: Lose Weight and Build Muscle',
                'youtube_video_id' => 'kJQP7kiw5Fk',
                'title_ids' => [10, 11, 12]
            ],
            [
                'description' => 'Web Development Career Guide: From Zero to Six Figures',
                'youtube_video_id' => '9bZkp7q19f0',
                'title_ids' => [13, 14, 15]
            ],
            [
                'description' => 'Entrepreneurship and Business: How to Start Your Own Company',
                'youtube_video_id' => 'fJ9rUzIMcZQ',
                'title_ids' => [16, 17, 18]
            ],
            [
                'description' => 'Cryptocurrency and Blockchain: The Future of Money Explained',
                'youtube_video_id' => 'Gv1uLfF35Uw',
                'title_ids' => [19, 20, 21]
            ],
            [
                'description' => 'Mental Health and Wellness: Building Resilience and Happiness',
                'youtube_video_id' => 'YQHsXMglC9A',
                'title_ids' => [22, 23, 24]
            ],
            [
                'description' => 'Travel and Adventure: Digital Nomad Lifestyle Guide',
                'youtube_video_id' => 'L_jWHffIx5E',
                'title_ids' => [25, 26, 27]
            ],
            [
                'description' => 'Technology and Innovation: Latest Trends Shaping Our Future',
                'youtube_video_id' => 'CevxZvSJLk8',
                'title_ids' => [28, 29, 30]
            ],
            [
                'description' => 'Cooking and Nutrition: Healthy Recipes for Busy Professionals',
                'youtube_video_id' => 'hFZFjoX2cGg',
                'title_ids' => [31, 32, 33]
            ],
            [
                'description' => 'Photography and Videography: Professional Tips and Techniques',
                'youtube_video_id' => '3JZ_D3ELwOQ',
                'title_ids' => [34, 35, 36]
            ],
            [
                'description' => 'Language Learning: Master Any Language in 6 Months',
                'youtube_video_id' => 'YQHsXMglC9A',
                'title_ids' => [37, 38, 39]
            ],
            [
                'description' => 'Real Estate Investing: Build Wealth Through Property',
                'youtube_video_id' => 'fJ9rUzIMcZQ',
                'title_ids' => [40, 41, 42]
            ],
            [
                'description' => 'Productivity and Time Management: Get More Done in Less Time',
                'youtube_video_id' => 'Gv1uLfF35Uw',
                'title_ids' => [43, 44, 45]
            ]
        ];

        foreach ($videos as $index => $videoData) {
            try {
                // Get a random user for each video
                $user = $users->random();
                
                // Create varied creation dates
                $baseDate = now()->subDays(rand(1, 90)); // Random base date within last 90 days
                $videoCreatedAt = $baseDate->copy()->addHours($index * rand(1, 8))->addMinutes(rand(0, 59));
                
                $video = Video::create([
                    'user_id' => $user->id,
                    'description' => $videoData['description'],
                    'youtube_video_id' => $videoData['youtube_video_id'],
                    'created_at' => $videoCreatedAt,
                    'updated_at' => $videoCreatedAt
                ]);

                // Attach titles if they exist
                if ($titles->isNotEmpty()) {
                    $availableTitleIds = $titles->pluck('id')->toArray();
                    $titleIdsToAttach = [];
                    
                    // Try to attach the specified title IDs, or random ones if they don't exist
                    foreach ($videoData['title_ids'] as $titleId) {
                        if (in_array($titleId, $availableTitleIds)) {
                            $titleIdsToAttach[] = $titleId;
                        }
                    }
                    
                    // If no specified titles are available, attach random ones
                    if (empty($titleIdsToAttach)) {
                        $titleIdsToAttach = array_slice($availableTitleIds, 0, rand(1, 3));
                    }
                    
                    if (!empty($titleIdsToAttach)) {
                        $video->titles()->sync($titleIdsToAttach);
                    }
                }

                $this->command->info("Created video: '{$videoData['description']}' (User: {$user->email}, YouTube ID: {$videoData['youtube_video_id']}, Titles: " . count($titleIdsToAttach ?? []) . ")");

            } catch (\Exception $e) {
                Log::error("Error seeding video '{$videoData['description']}': " . $e->getMessage());
                $this->command->error("Failed to create video: {$videoData['description']}");
            }
        }

        $this->command->info("VideoSeeder completed successfully!");
    }
}
