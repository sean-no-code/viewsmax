<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\YouTubeQuotaSpend;
use App\Support\Quota\Keys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class YouTubeQuotaAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]);
    }

    private function spend(string $resource, int $units, string $at, ?int $userId = null, int $status = 200): YouTubeQuotaSpend
    {
        return YouTubeQuotaSpend::create([
            'resource' => $resource, 'endpoint' => '/youtube/v3/'.$resource, 'method' => 'GET',
            'status' => $status, 'units' => $units, 'user_id' => $userId, 'spent_at' => Carbon::parse($at),
        ]);
    }

    public function test_only_admins_may_see_the_dashboard(): void
    {
        $this->getJson('/api/admin/youtube-quota/summary')->assertStatus(401);
        $this->as(User::factory()->create())->getJson('/api/admin/youtube-quota/summary')->assertStatus(403);
        $this->as(User::factory()->promotional()->create())->getJson('/api/admin/youtube-quota/summary')->assertStatus(403);
    }

    public function test_the_summary_shows_todays_counters_against_each_tiers_env_cap(): void
    {
        Carbon::setTestNow('2026-10-29 12:00:00'); // 05:00 Pacific, 29th
        config(['services.youtube.quota.thresholds.search_list' => ['free' => 48, 'paid' => 90, 'admin' => null]]);
        Cache::put(Keys::counter('search_list'), 12);
        $this->spend('search_list', 100, '2026-10-29 11:00:00');
        $this->spend('search_list', 100, '2026-10-29 11:30:00');
        $this->spend('videos_list', 1, '2026-10-26 09:00:00');

        $response = $this->as(User::factory()->admin()->create())->getJson('/api/admin/youtube-quota/summary')->assertOk();

        $response->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.date', '2026-10-29')
            ->assertJsonPath('data.resets_at', '2026-10-30T07:00:00+00:00');

        $search = collect($response->json('data.resources'))->firstWhere('resource', 'search_list');
        $this->assertSame(100, $search['units_per_call']);
        $this->assertSame(12, $search['used_today']);
        $this->assertTrue($search['gated']);
        $this->assertSame(['free' => 48, 'paid' => 90, 'admin' => null], $search['caps']);

        $daily = collect($response->json('data.daily'));
        $this->assertSame(2, $daily->firstWhere('resource', 'search_list')['calls']);
        $this->assertSame(200, $daily->firstWhere('resource', 'search_list')['units']);
        $this->assertSame('2026-10-26', $daily->firstWhere('resource', 'videos_list')['date']);
    }

    public function test_the_ledger_filters_sorts_and_paginates_server_side(): void
    {
        $alice = User::factory()->create();
        $this->spend('search_list', 100, '2026-10-20 10:00:00', $alice->id);
        $this->spend('search_list', 100, '2026-10-22 10:00:00', null, 403);
        $this->spend('videos_list', 1, '2026-10-24 10:00:00', $alice->id);
        $admin = User::factory()->admin()->create();
        $url = '/api/admin/youtube-quota/ledger';

        $this->as($admin)->getJson($url)->assertOk()->assertJsonPath('data.total', 3);
        $this->as($admin)->getJson($url.'?resource=search_list')->assertJsonPath('data.total', 2);
        $this->as($admin)->getJson($url.'?user_id='.$alice->id)->assertJsonPath('data.total', 2);
        $this->as($admin)->getJson($url.'?status=403')->assertJsonPath('data.total', 1);
        $this->as($admin)->getJson($url.'?from=2026-10-21&to=2026-10-23')->assertJsonPath('data.total', 1);

        $asc = $this->as($admin)->getJson($url.'?sort=units&dir=asc')->json('data.data');
        $this->assertSame([1, 100, 100], array_column($asc, 'units'));
        $newestFirst = $this->as($admin)->getJson($url)->json('data.data');
        $this->assertSame('videos_list', $newestFirst[0]['resource']);

        $page = $this->as($admin)->getJson($url.'?per_page=2&page=2')->assertOk();
        $page->assertJsonPath('data.last_page', 2)->assertJsonCount(1, 'data.data');

        $this->as($admin)->getJson($url.'?sort=drop+table')->assertStatus(422);
    }
}
