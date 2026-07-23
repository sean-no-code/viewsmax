<?php

namespace Database\Seeders;

use App\Models\ViralTitle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ViralTitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sampleViralTitles = [
            '100 days of training like david goggins',
            'Survive [TIME] Chained To Your Ex, Win [AMOUNT]',
            '$1 vs $[AMOUNT] [PLACE/OBJECT]!',
            'Survive [TIME] In Prison, Win [AMOUNT]',
            '[NUMBER] People Get Clean Water For The First Time!',
            'Survive [TIME] Trapped In A [PLACE], Keep It',
            'Beat [CELEBRITY], Win [AMOUNT]',
            'I Built [FAMOUS PLACE OR OBJECT]!',
            '[NUMBER] Players Compete in a Giant Game of [GAME]',
            'I Gave [AMOUNT] To [RECIPIENT]',
            'Last To Leave The [PLACE] Wins [AMOUNT]',
            'I Bought Everything In A [PLACE] And Gave It Away',
            'I Donated [AMOUNT] To [RECIPIENT] With 0 Viewers',
            'I Hosted The Largest Game Of [GAME]',
            'I Bought A [PLACE/OBJECT] And Gave It Away',
            'I Survived [TIME] In A Maximum Security [PLACE]',
            'I Hosted A $[AMOUNT] Tournament',
            'I Survived [TIME] In The World\'s Most Dangerous Place',
            'I Built The World\'s Largest [OBJECT]',
            'I Hosted A $[AMOUNT] [EVENT/COMPETITION]',
            'I Survived [TIME] In [PLACE/ENVIRONMENT]',
            'I Hosted A $[AMOUNT] Game Show',
            'I Built A $[AMOUNT] [PLACE/OBJECT] For [RECIPIENT]',
            'I Gave $[AMOUNT] To A Random [RECIPIENT]',
            'I Hosted A $[AMOUNT] Scavenger Hunt',
            'I Built A $[AMOUNT] Playground',
            'I Hosted A $[AMOUNT] Trivia Contest',
            'I Survived [TIME] In A Haunted [PLACE]',
            'I Hosted A $[AMOUNT] Cooking Competition',
            'I Hosted A $[AMOUNT] Dance Battle',
            'How to Bake the Perfect Sourdough Bread',
            '10-Minute Full Body Workout for Beginners',
            'DIY Home Office Setup on a Budget',
            'Exploring Hidden Gems in Tokyo',
            'Ultimate Guide to Personal Finance',
            'Mastering the Art of Photography',
            'Top 5 Strategies for Effective Time Management',
            'Beginner\'s Guide to Meditation and Mindfulness',
            'How to Start a Successful YouTube Channel',
            'Exploring the Wonders of the Amazon Rainforest',
            'Essential Tips for First-Time Homebuyers',
            'Understanding Cryptocurrency Basics',
            'A Day in the Life of a Digital Nomad',
            'How to Build a Sustainable Garden',
            'Exploring the Deep Sea Ecosystem',
            'Mastering the Guitar in 30 Days',
            'Ultimate Guide to Vegan Cooking',
            'Exploring the Great Barrier Reef',
            'How to Plan a Budget-Friendly Vacation',
            'Understanding Quantum Physics',
            'Tips for Effective Study Habits',
            'How to Start a Podcast from Scratch',
            'Exploring the Wonders of the Northern Lights',
            'How to Build a Personal Brand Online',
            'Understanding the Basics of Stock Market Investing',
            'How to Create a Minimalist Lifestyle',
            'Exploring the History of Ancient Rome',
            'Tips for Effective Networking',
        ];

        foreach ($sampleViralTitles as $index => $title) {
            try {
                // Create viral title with varied creation date
                $baseDate = now()->subDays(rand(1, 60)); // Random base date within last 60 days
                $createdAt = $baseDate->copy()->addHours($index * rand(1, 3))->addMinutes(rand(0, 59));
                
                $viralTitle = ViralTitle::firstOrCreate(
                    ['title' => $title],
                    [
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt
                    ]
                );
                
                if ($viralTitle->wasRecentlyCreated) {
                    $this->command->info("Created viral title: {$title} (created: {$viralTitle->created_at})");
                } else {
                    $this->command->info("Found existing viral title: {$title}");
                }
                
            } catch (\Exception $e) {
                Log::error("Error seeding viral title '{$title}': " . $e->getMessage());
                $this->command->error("Failed to create viral title: {$title}");
            }
        }
    }
}
