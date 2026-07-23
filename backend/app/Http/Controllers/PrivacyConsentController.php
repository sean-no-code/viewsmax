<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PrivacyConsentController extends Controller
{
    /**
     * Check if user has consented to privacy policy
     * 
     * Supports payload format:
     * {
     *   "user_id": "2",
     *   "policy_version": "2025-09-01"
     * }
     */
    public function check(Request $request)
    {
        try {
            $user = Auth::user();

            // If payload is provided, validate it
            if ($request->has('user_id') && $request->has('policy_version')) {
                $validator = Validator::make($request->all(), [
                    'user_id' => 'required|string|numeric',
                    'policy_version' => 'required|string|max:50',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

                // Verify the user_id matches the authenticated user
                if ($user->id != $request->user_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User ID mismatch'
                    ], 403);
                }

                $requestedVersion = $request->policy_version;
            } else {
                $requestedVersion = null;
            }

            $hasConsented = $user->privacy_consent_at !== null;
            $consentVersion = $user->privacy_consent_version;
            
            // Check if user has consented to the specific policy version
            $hasConsentedToVersion = $hasConsented && $consentVersion === $requestedVersion;
            
            // Check if consent is up to date (same version as requested)
            $isConsentUpToDate = $hasConsented && $consentVersion === $requestedVersion;

            $responseData = [
                'has_consented' => $hasConsented,
                'consent_date' => $user->privacy_consent_at?->toISOString(),
                'consent_version' => $consentVersion,
                'ip_address' => $user->privacy_consent_ip,
                'user_agent' => $user->privacy_consent_user_agent,
            ];

            // Add version-specific information if payload was provided
            if ($requestedVersion !== null) {
                $responseData['requested_version'] = $requestedVersion;
                $responseData['has_consented_to_version'] = $hasConsentedToVersion;
                $responseData['is_consent_up_to_date'] = $isConsentUpToDate;
                $responseData['consent_status'] = $isConsentUpToDate ? 'current' : ($hasConsented ? 'outdated' : 'none');
            }

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Privacy consent check failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check privacy consent status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record user's privacy consent
     * 
     * Supports two payload formats:
     * 
     * New format:
     * {
     *   "user_id": "2",
     *   "policy_version": "2025-09-01",
     *   "consented_at": "2025-09-12T10:29:47.867Z"
     * }
     * 
     * Legacy format:
     * {
     *   "consent": true,
     *   "version": "2025-09-01"
     * }
     */
    public function record(Request $request)
    {
        // Check if this is the new payload format
        if ($request->has('user_id') && $request->has('policy_version') && $request->has('consented_at')) {
            // New payload format: {user_id, policy_version, consented_at}
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|string|numeric',
                'policy_version' => 'required|string|max:50',
                'consented_at' => 'required|date|before_or_equal:now',
            ]);
        } else {
            // Legacy payload format: {consent, version}
            $validator = Validator::make($request->all(), [
                'consent' => 'required|boolean',
                'version' => 'required|string|max:50',
            ]);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();

            // Handle new payload format
            if ($request->has('user_id') && $request->has('policy_version') && $request->has('consented_at')) {
                // Verify the user_id matches the authenticated user
                if ($user->id != $request->user_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User ID mismatch'
                    ], 403);
                }

                // Record the consent with the provided timestamp
                try {
                    $consentedAt = \Carbon\Carbon::parse($request->consented_at);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid date format for consented_at',
                        'error' => 'Date must be in a valid ISO 8601 format'
                    ], 422);
                }
                
                $user->update([
                    'privacy_consent_at' => $consentedAt,
                    'privacy_consent_version' => $request->policy_version,
                    'privacy_consent_ip' => $request->ip(),
                    'privacy_consent_user_agent' => $request->userAgent(),
                ]);

                Log::info('Privacy consent recorded (new format)', [
                    'user_id' => $user->id,
                    'policy_version' => $request->policy_version,
                    'consented_at' => $consentedAt->toISOString(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Privacy consent recorded successfully',
                    'data' => [
                        'user_id' => $user->id,
                        'consent_date' => $user->privacy_consent_at->toISOString(),
                        'consent_version' => $user->privacy_consent_version,
                    ]
                ]);
            } else {
                // Handle legacy payload format
                if (!$request->consent) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Consent must be given to proceed'
                    ], 400);
                }

                // Record the consent
                $user->update([
                    'privacy_consent_at' => now(),
                    'privacy_consent_version' => $request->version,
                    'privacy_consent_ip' => $request->ip(),
                    'privacy_consent_user_agent' => $request->userAgent(),
                ]);

                Log::info('Privacy consent recorded (legacy format)', [
                    'user_id' => $user->id,
                    'version' => $request->version,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Privacy consent recorded successfully',
                    'data' => [
                        'consent_date' => $user->privacy_consent_at->toISOString(),
                        'consent_version' => $user->privacy_consent_version,
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Privacy consent recording failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record privacy consent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Withdraw privacy consent (GDPR compliance)
     */
    public function withdraw(Request $request)
    {
        try {
            $user = Auth::user();

            // Clear consent data
            $user->update([
                'privacy_consent_at' => null,
                'privacy_consent_version' => null,
                'privacy_consent_ip' => null,
                'privacy_consent_user_agent' => null,
            ]);

            // Also clear any YouTube OAuth tokens as they require consent
            $user->update([
                'youtube_access_token' => null,
                'youtube_refresh_token' => null,
                'youtube_token_expires_at' => null,
            ]);

            Log::info('Privacy consent withdrawn', [
                'user_id' => $user->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Privacy consent withdrawn successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Privacy consent withdrawal failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to withdraw privacy consent',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
