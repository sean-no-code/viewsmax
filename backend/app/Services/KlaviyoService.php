<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KlaviyoService
{
    protected $apiKey;
    protected $apiUrl;
    protected $listId;

    public function __construct()
    {
        $this->apiKey = config('services.klaviyo.api_key');
        $this->apiUrl = config('services.klaviyo.api_url');
        $this->listId = config('services.klaviyo.list_id');
    }

    public function configured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Ask Klaviyo to delete a profile and everything it holds about it (GDPR
     * erasure, UserErasure). Klaviyo runs the deletion as a job; a 202 means
     * it was accepted. Never throws: false = refused or unreachable.
     */
    public function deleteProfile(string $email): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $url = rtrim($this->apiUrl ?: 'https://a.klaviyo.com', '/').'/api/data-privacy-deletion-jobs';
        $payload = [
            'data' => [
                'type' => 'data-privacy-deletion-job',
                'attributes' => [
                    'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $email]]],
                ],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Klaviyo-API-Key '.$this->apiKey,
                'Revision' => '2024-02-15',
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
            ])->post($url, $payload);

            if ($response->successful()) {
                return true;
            }
            Log::error("Klaviyo refused the deletion job for {$email}: ".$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error("Klaviyo deletion job for {$email} failed: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Subscribe a user profile to a list.
     *
     * @param string $email
     * @return bool
     */
    public function subscribeProfile(string $email): bool
    {
        if (empty($this->apiKey) || empty($this->listId)) {
            Log::warning('Klaviyo API key or List ID not configured.');
            return false;
        }

        $url = rtrim($this->apiUrl, '/') . '/api/profile-subscription-bulk-create-jobs';

        $payload = [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [
                            [
                                'type' => 'profile',
                                'attributes' => [
                                    'email' => $email,
                                    'subscriptions' => [
                                        'email' => [
                                            'marketing' => [
                                                'consent' => 'SUBSCRIBED'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'relationships' => [
                    'list' => [
                        'data' => [
                            'type' => 'list',
                            'id' => $this->listId
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Klaviyo-API-Key ' . $this->apiKey,
                'Revision' => '2024-02-15', // Using a recent stable revision, adjust if necessary
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json'
            ])->post($url, $payload);

            if ($response->successful()) {
                Log::info("Successfully subscribed {$email} to Klaviyo list {$this->listId}");
                return true;
            } else {
                Log::error("Failed to subscribe {$email} to Klaviyo: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception in Klaviyo subscription for {$email}: " . $e->getMessage());
            return false;
        }
    }
}
