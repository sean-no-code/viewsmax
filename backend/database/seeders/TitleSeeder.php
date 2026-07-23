<?php

namespace Database\Seeders;

use App\Models\Title;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sampleTitles = [
            ['title' => '100 days of training like david goggins', 'virality_score' => 99],
            ['title' => 'Survive [TIME] Chained To Your Ex, Win [AMOUNT]', 'virality_score' => 100],
            ['title' => '$1 vs $[AMOUNT] [PLACE/OBJECT]!', 'virality_score' => 100],
            ['title' => 'Survive [TIME] In Prison, Win [AMOUNT]', 'virality_score' => 100],
            ['title' => '[NUMBER] People Get Clean Water For The First Time!', 'virality_score' => 100],
            ['title' => 'Survive [TIME] Trapped In A [PLACE], Keep It', 'virality_score' => 100],
            ['title' => 'Beat [CELEBRITY], Win [AMOUNT]', 'virality_score' => 100],
            ['title' => 'I Built [FAMOUS PLACE OR OBJECT]!', 'virality_score' => 100],
            ['title' => '[NUMBER] Players Compete in a Giant Game of [GAME]', 'virality_score' => 100],
            ['title' => 'I Gave [AMOUNT] To [RECIPIENT]', 'virality_score' => 100],
            ['title' => 'Last To Leave The [PLACE] Wins [AMOUNT]', 'virality_score' => 100],
            ['title' => 'I Bought Everything In A [PLACE] And Gave It Away', 'virality_score' => 100],
            ['title' => 'I Donated [AMOUNT] To [RECIPIENT] With 0 Viewers', 'virality_score' => 100],
            ['title' => 'I Hosted The Largest Game Of [GAME]', 'virality_score' => 100],
            ['title' => 'I Bought A [PLACE/OBJECT] And Gave It Away', 'virality_score' => 100],
            ['title' => 'I Survived [TIME] In A Maximum Security [PLACE]', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Tournament', 'virality_score' => 100],
            ['title' => 'I Survived [TIME] In The World\'s Most Dangerous Place', 'virality_score' => 100],
            ['title' => 'I Built The World\'s Largest [OBJECT]', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] [EVENT/COMPETITION]', 'virality_score' => 100],
            ['title' => 'I Survived [TIME] In [PLACE/ENVIRONMENT]', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Game Show', 'virality_score' => 100],
            ['title' => 'I Built A $[AMOUNT] [PLACE/OBJECT] For [RECIPIENT]', 'virality_score' => 100],
            ['title' => 'I Gave $[AMOUNT] To A Random [RECIPIENT]', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Scavenger Hunt', 'virality_score' => 100],
            ['title' => 'I Built A $[AMOUNT] Playground', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Trivia Contest', 'virality_score' => 100],
            ['title' => 'I Survived [TIME] In A Haunted [PLACE]', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Cooking Competition', 'virality_score' => 100],
            ['title' => 'I Hosted A $[AMOUNT] Dance Battle', 'virality_score' => 100],
            ['title' => 'I Gave $[AMOUNT] To A Random [RECIPIENT]', 'virality_score' => 100],
            ['title' => 'How to Bake the Perfect Sourdough Bread', 'virality_score' => 100],
            ['title' => '10-Minute Full Body Workout for Beginners', 'virality_score' => 99],
            ['title' => 'DIY Home Office Setup on a Budget', 'virality_score' => 98],
            ['title' => 'Exploring Hidden Gems in Tokyo', 'virality_score' => 97],
            ['title' => 'Ultimate Guide to Personal Finance', 'virality_score' => 96],
            ['title' => 'Mastering the Art of Photography', 'virality_score' => 95],
            ['title' => 'Top 5 Strategies for Effective Time Management', 'virality_score' => 94],
            ['title' => 'Beginner\'s Guide to Meditation and Mindfulness', 'virality_score' => 93],
            ['title' => 'How to Start a Successful YouTube Channel', 'virality_score' => 92],
            ['title' => 'Exploring the Wonders of the Amazon Rainforest', 'virality_score' => 91],
            ['title' => 'Essential Tips for First-Time Homebuyers', 'virality_score' => 90],
            ['title' => 'Understanding Cryptocurrency Basics', 'virality_score' => 89],
            ['title' => 'A Day in the Life of a Digital Nomad', 'virality_score' => 88],
            ['title' => 'How to Build a Sustainable Garden', 'virality_score' => 87],
            ['title' => 'Exploring the Deep Sea Ecosystem', 'virality_score' => 100],
            ['title' => 'Mastering the Guitar in 30 Days', 'virality_score' => 99],
            ['title' => 'Ultimate Guide to Vegan Cooking', 'virality_score' => 98],
            ['title' => 'Exploring the Great Barrier Reef', 'virality_score' => 97],
            ['title' => 'How to Plan a Budget-Friendly Vacation', 'virality_score' => 96],
            ['title' => 'Understanding Quantum Physics', 'virality_score' => 95],
            ['title' => 'Tips for Effective Study Habits', 'virality_score' => 94],
            ['title' => 'How to Start a Podcast from Scratch', 'virality_score' => 93],
            ['title' => 'Exploring the Wonders of the Northern Lights', 'virality_score' => 92],
            ['title' => 'How to Build a Personal Brand Online', 'virality_score' => 91],
            ['title' => 'Understanding the Basics of Stock Market Investing', 'virality_score' => 90],
            ['title' => 'How to Create a Minimalist Lifestyle', 'virality_score' => 89],
            ['title' => 'Exploring the History of Ancient Rome', 'virality_score' => 88],
            ['title' => 'Tips for Effective Networking', 'virality_score' => 87],
        ];

        foreach ($sampleTitles as $titleData) {
            try {
                // Get embedding from OpenAI (or use dummy for testing)
                $embedding = $this->getEmbedding($titleData['title']);
                
                Title::create([
                    'title' => $titleData['title'],
                    'virality_score' => $titleData['virality_score'],
                    'embedding' => $embedding
                ]);
                
                $this->command->info("Created title: {$titleData['title']}");
                
            } catch (\Exception $e) {
                Log::error("Error seeding title '{$titleData['title']}': " . $e->getMessage());
                $this->command->error("Failed to create title: {$titleData['title']}");
                
                // Fallback to dummy embedding if OpenAI fails
                try {
                    $dummyEmbedding = array_fill(0, 3072, 0.0);
                    Title::create([
                        'title' => $titleData['title'],
                        'virality_score' => $titleData['virality_score'],
                        'embedding' => $dummyEmbedding
                    ]);
                    $this->command->info("Created title with dummy embedding: {$titleData['title']}");
                } catch (\Exception $fallbackError) {
                    $this->command->error("Failed to create title even with dummy embedding: {$titleData['title']}");
                }
            }
        }
    }

    /**
     * Get embedding from OpenAI
     */
    private function getEmbedding(string $text): array
    {
        $apiKey = config('services.openai.api_key');
        
        // If no API key is set, return a dummy embedding for testing
        if (empty($apiKey) || $apiKey === 'your-openai-api-key-here') {
            return array_fill(0, 3072, 0.0);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => $text,
            'model' => config('services.openai.model')
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['data'][0]['embedding'])) {
            throw new \Exception('Invalid response from OpenAI API');
        }

        return $data['data'][0]['embedding'];
    }
}
