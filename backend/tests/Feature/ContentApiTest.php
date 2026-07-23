<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    /**
     * Authenticated request headers.
     */
    private function auth(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ], $extra);
    }

    public function test_content_endpoints_require_authentication(): void
    {
        $this->getJson('/api/contents')->assertStatus(401);
        $this->postJson('/api/contents', ['title' => 'x'])->assertStatus(401);
    }

    public function test_user_can_create_and_list_content(): void
    {
        $response = $this->withHeaders($this->auth())->postJson('/api/contents', [
            'title' => 'My first post',
            'body' => 'Hello world',
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'My first post')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.user_id', $this->user->id);

        $this->assertDatabaseHas('contents', [
            'title' => 'My first post',
            'user_id' => $this->user->id,
        ]);

        $this->withHeaders($this->auth())->getJson('/api/contents')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_create_content_validates_required_fields(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/contents', [
            'body' => 'no title',
            'status' => 'not-a-real-status',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'status']);
    }

    /**
     * Long-form text: a very large body must persist and round-trip intact.
     */
    public function test_content_handles_very_long_text_body(): void
    {
        // ~200k characters of long-form text. trim() avoids relying on the
        // framework's TrimStrings middleware (which strips edge whitespace).
        $longBody = trim(str_repeat('The quick brown fox jumps over the lazy dog. ', 4500));
        $this->assertGreaterThan(100_000, strlen($longBody));

        $response = $this->withHeaders($this->auth())->postJson('/api/contents', [
            'title' => 'Long read',
            'body' => $longBody,
        ]);

        $response->assertCreated();
        $id = $response->json('data.id');

        // Stored intact.
        $this->assertSame($longBody, Content::find($id)->body);

        // Returned intact on show.
        $this->withHeaders($this->auth())->getJson("/api/contents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.body', $longBody);
    }

    /**
     * Large file: a multi-megabyte upload is stored, metadata recorded, and downloadable.
     */
    public function test_content_handles_large_file_upload_and_download(): void
    {
        Storage::fake('local');

        // 12 MB fake binary file.
        $sizeKb = 12 * 1024;
        $file = UploadedFile::fake()->create('promo-video.mp4', $sizeKb, 'video/mp4');

        $response = $this->withHeaders($this->auth())->post('/api/contents', [
            'title' => 'Launch video',
            'body' => 'Watch our launch.',
            'media' => $file,
        ], $this->auth());

        $response->assertCreated()
            ->assertJsonPath('data.media_filename', 'promo-video.mp4')
            ->assertJsonPath('data.media_mime', 'video/mp4')
            ->assertJsonPath('data.media_size', $sizeKb * 1024);

        // media_path is hidden from API output but stored on disk.
        $content = Content::find($response->json('data.id'));
        $this->assertNotNull($content->media_path);
        Storage::disk('local')->assertExists($content->media_path);

        // Download streams the file back with original name.
        $download = $this->withHeaders($this->auth())->get("/api/contents/{$content->id}/media");
        $download->assertOk();
        $this->assertStringContainsString('promo-video.mp4', $download->headers->get('content-disposition'));
    }

    public function test_oversized_media_is_rejected(): void
    {
        Storage::fake('local');

        // 60 MB exceeds the 50 MB limit.
        $file = UploadedFile::fake()->create('huge.bin', 60 * 1024, 'application/octet-stream');

        $this->withHeaders($this->auth())->post('/api/contents', [
            'title' => 'Too big',
            'media' => $file,
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media']);
    }

    public function test_content_can_be_attached_to_an_owned_offer(): void
    {
        $offer = $this->makeOffer($this->user);

        $response = $this->withHeaders($this->auth())->postJson('/api/contents', [
            'title' => 'Promo for offer',
            'offer_id' => $offer->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.offer_id', $offer->id);

        // Filter by offer.
        $this->withHeaders($this->auth())->getJson("/api/contents?offer_id={$offer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_attach_content_to_another_users_offer(): void
    {
        $otherUser = User::factory()->create();
        $offer = $this->makeOffer($otherUser);

        $this->withHeaders($this->auth())->postJson('/api/contents', [
            'title' => 'Sneaky',
            'offer_id' => $offer->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);
    }

    public function test_user_can_update_content(): void
    {
        $content = Content::factory()->create(['user_id' => $this->user->id]);

        $this->withHeaders($this->auth())->putJson("/api/contents/{$content->id}", [
            'title' => 'Updated title',
            'status' => 'published',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.status', 'published');
    }

    public function test_user_can_delete_content_and_its_media(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('doc.pdf', 2048, 'application/pdf');

        $created = $this->withHeaders($this->auth())->post('/api/contents', [
            'title' => 'To delete',
            'media' => $file,
        ], $this->auth())->assertCreated();

        $content = Content::find($created->json('data.id'));
        Storage::disk('local')->assertExists($content->media_path);

        $this->withHeaders($this->auth())->deleteJson("/api/contents/{$content->id}")
            ->assertNoContent();

        $this->assertModelMissing($content);
        Storage::disk('local')->assertMissing($content->media_path);
    }

    public function test_user_cannot_access_another_users_content(): void
    {
        $otherUser = User::factory()->create();
        $foreign = Content::factory()->create(['user_id' => $otherUser->id]);

        // Not listed.
        $this->withHeaders($this->auth())->getJson('/api/contents')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Not viewable.
        $this->withHeaders($this->auth())->getJson("/api/contents/{$foreign->id}")
            ->assertStatus(404);

        // Not editable.
        $this->withHeaders($this->auth())->putJson("/api/contents/{$foreign->id}", ['title' => 'hijack'])
            ->assertStatus(404);

        // Not deletable.
        $this->withHeaders($this->auth())->deleteJson("/api/contents/{$foreign->id}")
            ->assertStatus(404);
    }

    /**
     * Create an Offer row directly (the Offer model maps to the legacy
     * tracking_events table).
     */
    private function makeOffer(User $owner): Offer
    {
        return Offer::create([
            'user_id' => $owner->id,
            'name' => 'Test offer',
            'offer_url' => 'https://example.com/offer',
            'conversion_value' => 100,
        ]);
    }
}
