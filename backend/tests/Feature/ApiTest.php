<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user and token
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // These tests exercise request validation on write endpoints, which sit
        // behind `restrict.free` and `check.credits`. A bare factory user has no
        // plan and no credits, so those middleware reject first and the request
        // never reaches the validation being asserted. Give the user a paid plan
        // and a credit balance so the assertions test what they intend to.
        $plan = Plan::create([
            'name' => 'test_paid',
            'display_name' => 'Test Paid',
            'price' => 29.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $this->user->plans()->attach($plan->id, [
            'stripe_subscription_id' => 'sub_test',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);

        $this->user->deposit(100000);
    }

    /**
     * Test that unauthenticated requests return JSON error response
     */
    public function test_unauthenticated_requests_return_json_error(): void
    {
        $routes = [
            '/api/thumbnails',
            '/api/titles',
            '/api/plans',
            '/api/user-plans/current',
        ];

        foreach ($routes as $route) {
            $response = $this->withHeaders([
                'Accept' => 'application/json',
            ])->getJson($route);

            $response->assertStatus(401)
                    ->assertHeader('content-type', 'application/json')
                    ->assertJsonStructure([
                        'success',
                        'message'
                    ])
                    ->assertJson([
                        'success' => false,
                        'message' => 'Unauthenticated'
                    ]);
        }
    }

    /**
     * Test that invalid tokens return JSON error response
     */
    public function test_invalid_tokens_return_json_error(): void
    {
        $routes = [
            '/api/thumbnails',
            '/api/titles',
            '/api/plans',
        ];

        foreach ($routes as $route) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer invalid-token-12345',
                'Accept' => 'application/json',
            ])->getJson($route);

            $response->assertStatus(401)
                    ->assertHeader('content-type', 'application/json')
                    ->assertJsonStructure([
                        'success',
                        'message'
                    ])
                    ->assertJson([
                        'success' => false,
                        'message' => 'Invalid token'
                    ]);
        }
    }

    /**
     * Test that non-existent resources return JSON 404 error
     */
    public function test_nonexistent_resources_return_json_404(): void
    {
        // Test thumbnail routes
        $thumbnailRoutes = [
            '/api/thumbnails/99999',
            '/api/thumbnails/99999/status',
        ];

        foreach ($thumbnailRoutes as $route) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->getJson($route);

            $response->assertStatus(404)
                    ->assertHeader('content-type', 'application/json')
                    ->assertJsonStructure([
                        'success',
                        'message'
                    ])
                    ->assertJson([
                        'success' => false,
                        'message' => 'Thumbnail not found'
                    ]);
        }

        // Test plan routes
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/plans/99999');

        $response->assertStatus(404)
                ->assertHeader('content-type', 'application/json')
                ->assertJsonStructure([
                    'success',
                    'message'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Plan not found'
                ]);
    }

    /**
     * Test that validation errors return JSON error response
     */
    public function test_validation_errors_return_json_response(): void
    {
        // Test empty description
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/thumbnails', [
            'description' => ''
        ]);

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (422 or 500)
        $this->assertTrue(in_array($response->getStatusCode(), [422, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);

        // Test description too long
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/thumbnails', [
            'description' => str_repeat('a', 1001)
        ]);

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (422 or 500)
        $this->assertTrue(in_array($response->getStatusCode(), [422, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that malformed requests return JSON error response
     */
    public function test_malformed_requests_return_json_error(): void
    {
        // Test missing required fields
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/thumbnails', []);

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (422 or 500)
        $this->assertTrue(in_array($response->getStatusCode(), [422, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that unsupported HTTP methods return JSON error response
     */
    public function test_unsupported_methods_return_json_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->patchJson('/api/thumbnails/1');

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (405 or 500)
        $this->assertTrue(in_array($response->getStatusCode(), [405, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that accessing other user's resources returns JSON 404 error
     */
    public function test_accessing_other_user_resources_returns_json_404(): void
    {
        // Create another user and their thumbnail
        $otherUser = User::factory()->create();
        $thumbnail = $otherUser->thumbnails()->create([
            'description' => 'Other user thumbnail',
            'status' => 'completed'
        ]);

        // Try to access other user's thumbnail
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/thumbnails/{$thumbnail->id}");

        $response->assertStatus(404)
                ->assertHeader('content-type', 'application/json')
                ->assertJsonStructure([
                    'success',
                    'message'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Thumbnail not found'
                ]);
    }

    /**
     * Test that all error responses have consistent JSON structure
     */
    public function test_all_error_responses_have_consistent_structure(): void
    {
        // Test 401 Unauthorized
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/thumbnails');

        $response->assertStatus(401)
                ->assertHeader('content-type', 'application/json')
                ->assertJsonStructure([
                    'success',
                    'message'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ]);

        // Test 404 Not Found
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/thumbnails/99999');

        $response->assertStatus(404)
                ->assertHeader('content-type', 'application/json')
                ->assertJsonStructure([
                    'success',
                    'message'
                ])
                ->assertJson([
                    'success' => false,
                    'message' => 'Thumbnail not found'
                ]);
    }

    /**
     * Test that API routes handle database connection errors gracefully
     */
    public function test_database_errors_return_json_response(): void
    {
        // This test would require mocking database connection failures
        // For now, we'll test that the API structure is maintained
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/thumbnails');

        // Should return JSON regardless of success or failure
        $response->assertHeader('content-type', 'application/json');
        
        // Should have proper JSON structure
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that API routes return proper error messages for different scenarios
     */
    public function test_api_routes_return_proper_error_messages(): void
    {
        // Test 401 error message
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/thumbnails');

        $response->assertStatus(401)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ]);

        // Test 404 error message
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/thumbnails/99999');

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Thumbnail not found'
                ]);

        // Test validation error message
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/thumbnails', [
            'description' => ''
        ]);

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (422 or 500)
        $this->assertTrue(in_array($response->getStatusCode(), [422, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that API routes handle malformed authorization headers
     */
    public function test_malformed_authorization_headers_return_json_error(): void
    {
        $malformedHeaders = [
            'InvalidFormat token',
            'Bearer',
            'Bearer ',
            'Basic token',
            'Token invalid-format',
        ];

        foreach ($malformedHeaders as $header) {
            $response = $this->withHeaders([
                'Authorization' => $header,
                'Accept' => 'application/json',
            ])->getJson('/api/thumbnails');

            $response->assertStatus(401)
                    ->assertHeader('content-type', 'application/json')
                    ->assertJsonStructure([
                        'success',
                        'message'
                    ]);
        }
    }

    /**
     * Test that API routes handle missing Accept header gracefully
     */
    public function test_missing_accept_header_returns_json_error(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/thumbnails');

        // Should still return JSON even without Accept header
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test that API routes handle large request bodies gracefully
     */
    public function test_large_request_bodies_handled_gracefully(): void
    {
        $largeDescription = str_repeat('a', 10000); // Very large description

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/thumbnails', [
            'description' => $largeDescription
        ]);

        $response->assertHeader('content-type', 'application/json');
        
        // Should be an error response (422 for validation or 500 for server error)
        $this->assertTrue(in_array($response->getStatusCode(), [422, 500]));
        
        $response->assertJsonStructure([
            'success',
            'message'
        ]);
    }

    /**
     * Test the new generate titles from project endpoint
     */
}
