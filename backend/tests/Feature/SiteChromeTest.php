<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The API host's browser pages carry the SPA landing header and footer. */
class SiteChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_register_carry_the_site_header_and_footer(): void
    {
        config(['app.frontend_url' => 'https://viewsmax.test']);

        foreach (['/login', '/register'] as $path) {
            $page = $this->get($path)->assertOk();
            $page->assertSee('class="site-nav"', false)->assertSee('class="site-foot"', false);
            $page->assertSee('https://viewsmax.test/#pricing', false);
            $page->assertSee(url('/docs'), false);
            foreach (['/claude', '/claude-code', '/claude-cowork', '/chatgpt', '/codex', '/cursor', '/openclaw', '/hermes'] as $agent) {
                $page->assertSee('https://viewsmax.test'.$agent.'"', false);
            }
            $page->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('register').'"', false);
            $page->assertSee('Archivo:wght@700;800;900', false);
        }
    }
}
