<?php

namespace Tests\Feature;

use App\Models\LibraryComponent;
use App\Models\LibraryComponentTag;
use App\Models\User;
use Database\Seeders\ShockingTruthsHookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShockingTruthsHookSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_tagged_hook_components_for_each_user(): void
    {
        [$userA, $userB] = User::factory()->count(2)->create();

        $this->seed(ShockingTruthsHookSeeder::class);

        $tag = LibraryComponentTag::where('name', ShockingTruthsHookSeeder::TAG_NAME)->first();
        $this->assertNotNull($tag);

        $countA = LibraryComponent::where('user_id', $userA->id)
            ->where('type', LibraryComponent::TYPE_HOOK)
            ->count();
        $countB = LibraryComponent::where('user_id', $userB->id)
            ->where('type', LibraryComponent::TYPE_HOOK)
            ->count();

        $this->assertGreaterThanOrEqual(250, $countA);
        $this->assertSame($countA, $countB);
        $this->assertSame($countA + $countB, $tag->components()->count());

        $sample = LibraryComponent::where('user_id', $userA->id)
            ->where('title', "I tried every [thing] so you don't have to.")
            ->first();
        $this->assertNotNull($sample);
        $this->assertStringContainsString(
            'Example: I tried every productivity app',
            $sample->body
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        User::factory()->create();

        $this->seed(ShockingTruthsHookSeeder::class);
        $components = LibraryComponent::count();
        $tagLinks = LibraryComponentTag::where('name', ShockingTruthsHookSeeder::TAG_NAME)
            ->first()->components()->count();

        $this->seed(ShockingTruthsHookSeeder::class);

        $this->assertSame($components, LibraryComponent::count());
        $this->assertSame(
            $tagLinks,
            LibraryComponentTag::where('name', ShockingTruthsHookSeeder::TAG_NAME)
                ->first()->components()->count()
        );
    }
}
