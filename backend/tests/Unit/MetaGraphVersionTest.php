<?php

namespace Tests\Unit;

use App\Services\Social\Providers\FacebookProvider;
use Tests\TestCase;

/**
 * Facebook and Instagram share one Graph API release cycle, and Meta sunsets
 * each version ~2 years after release (v21.0 reached that in Oct 2026). One
 * shared default keeps both platforms on the same supported version; the
 * per-platform env vars still win when set.
 */
class MetaGraphVersionTest extends TestCase
{
    public function test_facebook_and_instagram_default_to_the_shared_meta_graph_version(): void
    {
        $shared = config('social.meta_graph_version');

        $this->assertSame('v25.0', $shared);
        $this->assertSame($shared, config('social.platforms.facebook.graph_version'));
        $this->assertSame($shared, config('social.platforms.instagram.graph_version'));
    }

    public function test_facebook_oauth_url_uses_the_shared_version(): void
    {
        config(['social.platforms.facebook.client_id' => 'id']);

        $url = (new FacebookProvider)->getAuthorizationUrl('https://app.test/callback', 'state-1');

        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
    }
}
