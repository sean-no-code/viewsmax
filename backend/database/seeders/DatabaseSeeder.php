<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            RoleSeeder::class,
            PlanSeeder::class,
            FileCategorySeeder::class, // Add file categories before other seeders
            GoalTypeSeeder::class, // Conversion goal/event types (reference list)
            PromptSeeder::class, // Add prompts before other seeders that might use them
            AiModelTypeSeeder::class, // Add AI model types before other seeders
            EthnicitySeeder::class, // Add ethnicities before other seeders
                // TitleSeeder::class,
            UserSeeder::class,
            ScriptSeeder::class, // Seed scripts after users are created
                // VideoSeeder::class, // Seed videos after users and titles are created
            ThumbnailSeeder::class,
            AiModelSeeder::class, // Seed AI models after users, ethnicities, and types are created
            // ViralTitleSeeder::class, // Seed viral titles first
        ]);

        $this->call(TrackingEventSeeder::class);
    }
}
