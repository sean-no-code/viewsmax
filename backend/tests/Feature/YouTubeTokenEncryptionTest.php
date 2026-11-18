<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * users.youtube_* and channels.youtube_* OAuth tokens are encrypted at rest
 * and never returned by GET /channels (Postiz 4ff97adf).
 */
class YouTubeTokenEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_120000_encrypt_youtube_tokens.php');
    }

    private function raw(string $table, int $id, string $column): ?string
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    private function channel(User $user, array $attributes = []): Channel
    {
        return Channel::create($attributes + [
            'user_id' => $user->id,
            'youtube_channel_id' => 'UC'.uniqid(),
            'channel_name' => 'My channel',
        ]);
    }

    public function test_user_tokens_round_trip_and_are_not_stored_as_plaintext(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['youtube_access_token' => 'ya29.access', 'youtube_refresh_token' => '1//refresh'])->save();

        $fresh = $user->fresh();
        $this->assertSame('ya29.access', $fresh->youtube_access_token);
        $this->assertSame('1//refresh', $fresh->youtube_refresh_token);

        $stored = $this->raw('users', $user->id, 'youtube_refresh_token');
        $this->assertNotSame('1//refresh', $stored);
        $this->assertStringNotContainsString('1//refresh', $stored);
        $this->assertSame('1//refresh', Crypt::decryptString($stored));
    }

    public function test_channel_tokens_round_trip_and_are_not_stored_as_plaintext(): void
    {
        $channel = $this->channel(User::factory()->create(), [
            'youtube_access_token' => 'ya29.chan', 'youtube_refresh_token' => '1//chan-refresh',
        ]);

        $fresh = $channel->fresh();
        $this->assertSame('ya29.chan', $fresh->youtube_access_token);
        $this->assertSame('1//chan-refresh', $fresh->youtube_refresh_token);

        foreach (['youtube_access_token' => 'ya29.chan', 'youtube_refresh_token' => '1//chan-refresh'] as $column => $plain) {
            $stored = $this->raw('channels', $channel->id, $column);
            $this->assertNotSame($plain, $stored);
            $this->assertSame($plain, Crypt::decryptString($stored));
        }
    }

    public function test_get_channels_returns_no_token_fields(): void
    {
        $user = User::factory()->create();
        $this->channel($user, ['youtube_access_token' => 'ya29.secret', 'youtube_refresh_token' => '1//secret']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'Accept' => 'application/json',
        ])->getJson('/api/channels')->assertOk();

        $channel = $response->json('data.0');
        $this->assertSame('My channel', $channel['channel_name']);
        $this->assertArrayNotHasKey('youtube_access_token', $channel);
        $this->assertArrayNotHasKey('youtube_refresh_token', $channel);
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_the_migration_encrypts_plaintext_rows_and_leaves_null_and_encrypted_values_alone(): void
    {
        $plainUser = User::factory()->create();
        $nullUser = User::factory()->create();
        $encryptedUser = User::factory()->create();
        $emptyUser = User::factory()->create();
        $alreadyEncrypted = Crypt::encryptString('1//already');

        // Rows as production has them today: plaintext written straight to the table.
        DB::table('users')->where('id', $plainUser->id)->update(['youtube_access_token' => 'ya29.plain', 'youtube_refresh_token' => '1//plain']);
        DB::table('users')->where('id', $encryptedUser->id)->update(['youtube_refresh_token' => $alreadyEncrypted]);
        DB::table('users')->where('id', $emptyUser->id)->update(['youtube_access_token' => '', 'youtube_refresh_token' => '1//kept']);
        $channel = $this->channel($plainUser);
        DB::table('channels')->where('id', $channel->id)->update(['youtube_access_token' => 'ya29.chan-plain', 'youtube_refresh_token' => null]);

        $this->migration()->up();

        $this->assertSame('ya29.plain', Crypt::decryptString($this->raw('users', $plainUser->id, 'youtube_access_token')));
        $this->assertSame('1//plain', Crypt::decryptString($this->raw('users', $plainUser->id, 'youtube_refresh_token')));
        $this->assertNull($this->raw('users', $nullUser->id, 'youtube_access_token'));
        $this->assertNull($this->raw('users', $nullUser->id, 'youtube_refresh_token'));
        $this->assertSame($alreadyEncrypted, $this->raw('users', $encryptedUser->id, 'youtube_refresh_token'), 'an encrypted value is not encrypted twice');
        $this->assertNull($this->raw('users', $encryptedUser->id, 'youtube_access_token'));
        $this->assertNull($this->raw('users', $emptyUser->id, 'youtube_access_token'), 'an empty string becomes NULL');
        $this->assertSame('1//kept', Crypt::decryptString($this->raw('users', $emptyUser->id, 'youtube_refresh_token')));
        $this->assertSame('ya29.chan-plain', Crypt::decryptString($this->raw('channels', $channel->id, 'youtube_access_token')));
        $this->assertNull($this->raw('channels', $channel->id, 'youtube_refresh_token'));

        // The models read the migrated rows.
        $this->assertSame('1//plain', $plainUser->fresh()->youtube_refresh_token);
        $this->assertSame('1//already', $encryptedUser->fresh()->youtube_refresh_token);
        $this->assertSame('ya29.chan-plain', $channel->fresh()->youtube_access_token);

        // Safe to run twice: nothing changes the second time.
        $before = DB::table('users')->orderBy('id')->get(['id', 'youtube_access_token', 'youtube_refresh_token'])->toArray();
        $this->migration()->up();
        $this->assertEquals($before, DB::table('users')->orderBy('id')->get(['id', 'youtube_access_token', 'youtube_refresh_token'])->toArray());

        // down() restores plaintext.
        $this->migration()->down();
        $this->assertSame('1//plain', $this->raw('users', $plainUser->id, 'youtube_refresh_token'));
        $this->assertSame('1//already', $this->raw('users', $encryptedUser->id, 'youtube_refresh_token'));
        $this->assertSame('ya29.chan-plain', $this->raw('channels', $channel->id, 'youtube_access_token'));
        $this->assertNull($this->raw('users', $nullUser->id, 'youtube_access_token'));
    }

    public function test_the_migration_covers_every_row_across_chunks(): void
    {
        $user = User::factory()->create();
        $rows = [];
        for ($i = 1; $i <= 450; $i++) {
            $rows[] = [
                'user_id' => $user->id, 'youtube_channel_id' => "UC{$i}",
                'youtube_access_token' => "ya29.{$i}", 'youtube_refresh_token' => $i % 3 === 0 ? null : "1//{$i}",
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('channels')->insert($rows);

        $this->migration()->up();

        $stored = DB::table('channels')->orderBy('id')->get(['youtube_channel_id', 'youtube_access_token', 'youtube_refresh_token']);
        $this->assertCount(450, $stored);
        foreach ($stored as $row) {
            $i = substr($row->youtube_channel_id, 2);
            $this->assertSame("ya29.{$i}", Crypt::decryptString($row->youtube_access_token));
            $i % 3 === 0
                ? $this->assertNull($row->youtube_refresh_token)
                : $this->assertSame("1//{$i}", Crypt::decryptString($row->youtube_refresh_token));
        }
    }
}
