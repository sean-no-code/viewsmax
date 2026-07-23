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
