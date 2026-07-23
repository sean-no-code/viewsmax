<?php

namespace Database\Seeders;

use App\Models\Script;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ScriptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users for seeding
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please run UserSeeder first.');
            return;
        }

        $scripts = [
            [
                'title' => 'AI and Machine Learning Tutorial',
                'description' => 'AI and Machine Learning Tutorial',
                'text' => "Welcome to the ultimate AI and Machine Learning tutorial! In the next 10 minutes, I'll show you exactly how to build your first AI model from scratch.

Here's what we'll cover:
1. The fundamentals of machine learning
2. Setting up your development environment
3. Building your first neural network
4. Training and testing your model
5. Deploying your AI solution

Machine learning is revolutionizing every industry. From healthcare to finance, AI is creating opportunities that never existed before. I've been working in AI for over 8 years, and I've seen the transformation firsthand.

Let's start with the basics. Machine learning is essentially teaching computers to learn patterns from data. Think of it like teaching a child to recognize cats - you show them thousands of cat pictures until they can identify cats on their own.

The key to success in AI is understanding the data. Garbage in, garbage out. You need clean, relevant data to build effective models. I've seen projects fail not because of complex algorithms, but because of poor data quality.

Python is the go-to language for machine learning. Libraries like TensorFlow, PyTorch, and Scikit-learn make it incredibly easy to build sophisticated models. Don't worry if you're new to programming - I'll walk you through every step.

Building your first model is easier than you think. We'll start with a simple linear regression model to predict house prices. This will teach you the fundamental concepts without overwhelming complexity.

Training a model is an iterative process. You feed data to your algorithm, measure its performance, adjust parameters, and repeat. It's like tuning a musical instrument - small adjustments can make a huge difference.

Testing is crucial. Never trust a model that hasn't been properly validated. We'll use techniques like cross-validation to ensure our model performs well on unseen data.

Deployment is where many projects fail. A model that works perfectly in your notebook might fail in production. We'll cover best practices for deploying AI models at scale.

Remember, AI is a tool, not a magic solution. It amplifies human intelligence but doesn't replace human judgment. Use it responsibly and ethically.

Ready to start your AI journey? Let's build something amazing together!",
                'length' => 8,
                'status' => 'completed'
            ],
            [
                'title' => 'Digital Marketing Strategies',
                'description' => 'Digital Marketing Strategies',
                'text' => "Are you struggling to get your business noticed online? Today I'm revealing the exact digital marketing strategies that helped me grow my business from zero to six figures in just 18 months.

Here's what we'll cover:
1. Content marketing that actually converts
2. Social media strategies that build real engagement
3. Email marketing that doesn't get deleted
4. SEO tactics that work in 2024
5. Paid advertising that pays for itself

Most businesses are doing digital marketing wrong. They're throwing money at ads without understanding their audience or tracking their results. That's why 70% of small businesses fail within the first 10 years.

Content is still king, but context is the kingdom. You need to create content that speaks directly to your ideal customer's pain points. Generic content gets ignored. Specific content gets shared.

Social media isn't about selling - it's about building relationships. People buy from people they know, like, and trust. Share behind-the-scenes content, tell your story, and engage authentically with your audience.

Email marketing has the highest ROI of any marketing channel. But here's the secret - it's not about the size of your list, it's about the quality of your relationship with your subscribers.

SEO has changed dramatically. Google's algorithms are getting smarter, focusing on user experience and content quality. Long-tail keywords and user intent are more important than ever.

Paid advertising can accelerate your growth, but only if you know what you're doing. Start with a small budget, test everything, and scale what works.

The key to digital marketing success is consistency. You can't post once and expect results. You need to show up every day, provide value, and build trust over time.

Track everything. What gets measured gets managed. Use analytics to understand what's working and what's not. Double down on what works and eliminate what doesn't.

Remember, digital marketing is a marathon, not a sprint. It takes time to build momentum, but once you do, the results compound exponentially.

Ready to transform your digital marketing? Let's make it happen!",
                'length' => 12,
                'status' => 'completed'
            ],
            [
                'title' => 'Personal Finance and Investing',
                'description' => 'Personal Finance and Investing',
                'text' => "Want to build wealth and achieve financial freedom? I'm about to share the exact investment strategies that helped me retire at 35 and build a multi-million dollar portfolio.

Here's what we'll cover:
1. The power of compound interest
2. Building an emergency fund
3. Choosing the right investment vehicles
4. Diversification strategies that work
5. Tax optimization techniques

Most people think investing is complicated, but it's actually quite simple. The hard part is having the discipline to stick to your plan and avoid emotional decisions.

Compound interest is the eighth wonder of the world. Starting early is more important than investing large amounts. A 25-year-old investing $200 per month will have more money at retirement than a 35-year-old investing $400 per month.

Your emergency fund is your financial safety net. Aim for 3-6 months of expenses in a high-yield savings account. This prevents you from having to sell investments during market downturns.

Index funds are your best friend. They're low-cost, diversified, and historically outperform most actively managed funds. Warren Buffett recommends index funds for most investors.

Diversification reduces risk without reducing returns. Don't put all your eggs in one basket. Spread your investments across different asset classes, sectors, and geographic regions.

Tax optimization can save you thousands of dollars. Use tax-advantaged accounts like 401(k)s, IRAs, and HSAs. Consider tax-loss harvesting to offset gains with losses.

Time in the market beats timing the market. Don't try to predict short-term movements. Focus on long-term trends and stay invested through market cycles.

Automate your investments. Set up automatic transfers from your checking account to your investment accounts. This removes emotion from the equation and ensures consistency.

Review and rebalance your portfolio regularly, but don't overdo it. Once or twice a year is usually sufficient. Rebalancing helps maintain your target asset allocation.

Remember, investing is about building wealth over time, not getting rich quick. Be patient, stay disciplined, and let compound interest work its magic.

Ready to start building wealth? Your future self will thank you!",
                'length' => 10,
                'status' => 'completed'
            ],
            [
                'title' => 'Health and Fitness Transformation',
                'description' => 'Health and Fitness Transformation',
                'text' => "Ready to transform your health and fitness? I'm about to share the exact strategies that helped me lose 50 pounds, gain 20 pounds of muscle, and completely transform my life in just 6 months.

Here's what we'll cover:
1. The nutrition plan that burns fat while building muscle
2. Workout routines that fit into any schedule
3. Mental health strategies that boost your energy
4. How to make healthy habits stick for life
5. The mindset shift that changed everything

Most people fail at fitness because they're trying to do too much too soon. The secret is to start small and build momentum. I've been where you are - overweight, unmotivated, and frustrated with failed attempts.

Nutrition is 80% of the battle. You can't out-exercise a bad diet. Focus on whole foods, lean proteins, vegetables, and healthy fats. Eat real food, not processed products.

Exercise doesn't have to be complicated. You don't need a gym membership or expensive equipment. Bodyweight exercises, walking, and simple resistance training can transform your body.

Consistency beats intensity every time. It's better to exercise for 20 minutes every day than to exercise for 2 hours once a week. Small, consistent actions compound into massive results.

Mental health is just as important as physical health. Stress, lack of sleep, and poor mental habits can sabotage even the best fitness plan. Learn to manage stress and prioritize sleep.

The biggest mistake I see people make is trying to change everything at once. Pick one habit, master it, then add another. Small changes compound into massive results over time.

Track your progress, but don't obsess over the scale. Take photos, measure your body fat percentage, and notice how your clothes fit. The scale doesn't tell the whole story.

Find activities you enjoy. If you hate running, don't run. Try dancing, swimming, hiking, or martial arts. The best exercise is the one you'll actually do consistently.

Remember, fitness isn't about perfection - it's about progress. Some days you'll eat perfectly, other days you won't. The key is to get back on track quickly and never give up.

Your health is your greatest asset. Invest in it daily, and you'll see returns for the rest of your life.

Ready to transform your health? Let's build a healthier, stronger you together!",
                'length' => 9,
                'status' => 'completed'
            ],

        ];

        foreach ($scripts as $index => $scriptData) {
            try {
                // Get a random user for each script
                $user = $users->random();
                
                // Create varied creation dates
                $baseDate = now()->subDays(rand(1, 60)); // Random base date within last 60 days
                $scriptCreatedAt = $baseDate->copy()->addHours($index * rand(1, 6))->addMinutes(rand(0, 59));
                
                // Create prompt from description or title
                $prompt = !empty($scriptData['description']) 
                    ? "Create an engaging video script about: {$scriptData['description']}"
                    : "Create an engaging video script about: {$scriptData['title']}";
                
                $script = Script::create([
                    'user_id' => $user->id,
                    'title' => $scriptData['title'],
                    'prompt' => $prompt,
                    'text' => $scriptData['text'],
                    'length' => $scriptData['length'],
                    'status' => $scriptData['status'],
                    'created_at' => $scriptCreatedAt,
                    'updated_at' => $scriptCreatedAt
                ]);

                $this->command->info("Created script: '{$scriptData['description']}' (User: {$user->email}, Length: {$scriptData['length']} min, Status: {$scriptData['status']})");

            } catch (\Exception $e) {
                Log::error("Error seeding script '{$scriptData['description']}': " . $e->getMessage());
                $this->command->error("Failed to create script: {$scriptData['description']}");
            }
        }

        // Create some scripts with different statuses for variety
        $statusVariations = [
            [
                'title' => 'Pending Script Generation',
                'description' => 'Pending Script Generation',
                'text' => null,
                'length' => 0,
                'status' => 'pending'
            ],
            [
                'title' => 'Processing Script Generation',
                'description' => 'Processing Script Generation',
                'text' => null,
                'length' => 0,
                'status' => 'processing'
            ],
            [
                'title' => 'Failed Script Generation',
                'description' => 'Failed Script Generation',
                'text' => null,
                'length' => 0,
                'status' => 'failed',
                'error_message' => 'OpenAI API rate limit exceeded'
            ]
        ];

        foreach ($statusVariations as $index => $scriptData) {
            try {
                $user = $users->random();
                $baseDate = now()->subDays(rand(1, 30));
                $scriptCreatedAt = $baseDate->copy()->addHours($index * 2)->addMinutes(rand(0, 59));
                
                // Create prompt from description or title
                $prompt = !empty($scriptData['description']) 
                    ? "Create an engaging video script about: {$scriptData['description']}"
                    : "Create an engaging video script about: {$scriptData['title']}";
                
                $script = Script::create([
                    'user_id' => $user->id,
                    'title' => $scriptData['title'],
                    'prompt' => $prompt,
                    'text' => $scriptData['text'],
                    'length' => $scriptData['length'],
                    'status' => $scriptData['status'],
                    'error_message' => $scriptData['error_message'] ?? null,
                    'created_at' => $scriptCreatedAt,
                    'updated_at' => $scriptCreatedAt
                ]);

                $this->command->info("Created script: '{$scriptData['description']}' (User: {$user->email}, Status: {$scriptData['status']})");

            } catch (\Exception $e) {
                Log::error("Error seeding script '{$scriptData['description']}': " . $e->getMessage());
                $this->command->error("Failed to create script: {$scriptData['description']}");
            }
        }

        // Create a processed script specifically for admin@testaccount.com
        $adminUser = User::where('email', 'admin@testaccount.com')->first();
        if ($adminUser) {
            try {
                $adminScript = Script::create([
                    'user_id' => $adminUser->id,
                    'title' => 'YouTube Script Writing Masterclass',
                    'prompt' => 'Create an engaging video script about: YouTube Script Writing Masterclass',
                    'text' => "Want to write scripts that get millions of views? I'm about to reveal the exact scriptwriting formula that top YouTubers use to create viral content.

Here's what we'll cover:
1. The hook formula that stops the scroll
2. Story structure that keeps viewers watching
3. How to write for retention, not just views
4. The secret to creating emotional connections
5. Call-to-action strategies that actually work

Most creators write scripts like essays - they're informative but boring. The best YouTube scripts are written like conversations. They feel natural, engaging, and keep you watching until the very end.

The first 15 seconds determine everything. Your hook needs to promise value, create curiosity, or solve a problem. Don't waste time with introductions - jump straight into the value.

Story structure is your secret weapon. Every great video follows a pattern: Hook, Problem, Solution, Proof, Call to Action. Master this structure and your retention will skyrocket.

Write for retention, not just views. A video with 1 million views and 20% retention is worse than a video with 500k views and 60% retention. Focus on keeping people watching, not just getting them to click.

Emotional connection is what separates good scripts from great ones. Share personal stories, use specific examples, and write like you're talking to a friend. People don't subscribe to information - they subscribe to people.

Your call-to-action should feel natural, not forced. Don't just say 'subscribe' - give them a reason. Tell them what's coming next, why they should stick around, and what value they'll get.

Remember, great scripts are rewritten, not written. Your first draft is just the beginning. Edit for clarity, cut the fluff, and make every word count.

Ready to write scripts that get results? Let's make your next video your best video!",
                    'length' => 7,
                    'status' => 'completed',
                    'created_at' => now()->subDays(5),
                    'updated_at' => now()->subDays(5)
                ]);

                $this->command->info("Created processed script for admin user: 'YouTube Script Writing Masterclass' (User: {$adminUser->email}, Length: 7 min, Status: completed)");
            } catch (\Exception $e) {
                Log::error("Error seeding admin script: " . $e->getMessage());
                $this->command->error("Failed to create admin script");
            }
        }

        $this->command->info("ScriptSeeder completed successfully!");
    }
}
