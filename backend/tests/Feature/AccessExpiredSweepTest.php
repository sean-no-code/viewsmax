<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccessActive;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every way in, not a sample: once the free window has closed with no plan,
 * every authenticated REST route (bar the billing allowlist) and every MCP
 * tool answers with the "upgrade to a paid subscription" denial. Catches a
 * route added outside the gated group or a tool that skips SafeCallTool.
 */
class AccessExpiredSweepTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://app.example.com/']);
        $this->user = User::factory()->expired()->create();
    }

    /** @return list<Route> */
    private function authenticatedApiRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('api.auth', $route->gatherMiddleware(), true))
            ->values()
            ->all();
    }

    public function test_every_authenticated_api_route_runs_the_access_gate(): void
    {
        $ungated = collect($this->authenticatedApiRoutes())
            ->reject(fn (Route $route) => in_array('access.active', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $ungated, 'Authenticated routes missing access.active');
    }

    public function test_every_gated_api_route_tells_an_expired_user_to_upgrade(): void
    {
        // Implicit model binding runs in the 'api' group ahead of the gate, so a
        // placeholder id would 404 before access.active is reached. With a real
        // record binding succeeds and the gate answers; skip it to test the gate.
        $this->withoutMiddleware(SubstituteBindings::class);
        $token = $this->user->createToken('mobile-app')->plainTextToken;
        $message = UserAccess::denial($this->user)->message;
        $failures = [];
        $checked = 0;

        foreach ($this->authenticatedApiRoutes() as $route) {
            $uri = $this->fillParameters($route);
            if (Str::is(EnsureAccessActive::ALLOWED_PATTERNS, $uri)) {
                continue;
            }

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, '/'.$uri);
            $checked++;

            if ($response->status() !== 403 || $response->json('code') !== UserAccess::ACCESS_EXPIRED
                || $response->json('message') !== $message) {
                $failures[] = "{$method} /{$uri} -> {$response->status()} ".Str::limit($response->getContent(), 120);
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $failures, 'Routes that did not answer access_expired');
    }

    public function test_the_billing_allowlist_still_answers_an_expired_user(): void
    {
        $token = $this->user->createToken('mobile-app')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/profile')->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/plans')->assertOk();
    }

    public function test_every_mcp_tool_tells_an_expired_user_to_upgrade(): void
    {
        $plain = 'vmx_'.$this->user->generateTokenString();
        $this->user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $plain), 'abilities' => ['mcp']]);
        $rpc = fn (string $method, array $params = []) => $this
            ->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $tools = collect($rpc('tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertGreaterThan(20, $tools->count());

        $message = UserAccess::denial($this->user)->message;
        $failures = $tools->reject(function (string $name) use ($rpc, $message) {
            $result = $rpc('tools/call', ['name' => $name, 'arguments' => []])->json('result');

            return ($result['isError'] ?? null) === false && ($result['content'][0]['text'] ?? null) === $message;
        })->values()->all();

        $this->assertSame([], $failures, 'Tools that did not answer with the upgrade message');
    }

    /** A concrete URI for the route, honouring any where() constraints on its parameters. */
    private function fillParameters(Route $route): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($route) {
            $pattern = $route->wheres[$m[1]] ?? null;
            if ($pattern === null) {
                return '1';
            }

            $candidates = ['1', ...explode('|', trim($pattern, '()')), 'youtube', 'x'];

            return collect($candidates)->first(fn ($c) => preg_match('#^'.$pattern.'$#', $c)) ?? '1';
        }, $route->uri());
    }
}
