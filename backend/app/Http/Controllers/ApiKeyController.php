<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group API Keys
 *
 * MCP API key management: one non-expiring key per user,
 * rotate-to-invalidate. The key is a Sanctum personal access token scoped to
 * the `mcp` ability, stored hashed; only a display hint is recoverable, so the
 * plaintext is returned exactly once from rotate(). The key authenticates the
 * MCP server (/api/mcp) and the posts/offers/tracking REST surface; these
 * management endpoints themselves require a login session, not a key.
 */
class ApiKeyController extends Controller
{
    private const TOKEN_NAME = 'mcp';

    private const KEY_PREFIX = 'vmx_';

    /** Abilities granted per access level. */
    private const ABILITIES = [
        'read' => ['mcp:read'],
        'full' => ['mcp:read', 'mcp:write'],
    ];

    public function show(Request $request): JsonResponse
    {
        $token = $request->user()->tokens()
            ->where('name', self::TOKEN_NAME)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'API key status retrieved',
            'data' => $token ? [
                'hint' => $token->key_hint,
                'access' => self::accessLevel($token->abilities ?? []),
                'created_at' => $token->created_at,
            ] : null,
        ]);
    }

    public function rotate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'access' => 'sometimes|string|in:read,full',
        ]);
        $access = $validated['access'] ?? 'full';

        $user = $request->user();

        $user->tokens()->where('name', self::TOKEN_NAME)->delete();

        // Sanctum's token string (entropy + crc32b checksum) so secret
        // scanners can validate leaked keys offline; vmx_ is our own prefix
        // rather than sanctum.token_prefix, which would also tag login tokens.
        $plain = self::KEY_PREFIX . $user->generateTokenString();
        $hint = substr($plain, 0, 8) . '...' . substr($plain, -5);

        $token = $user->tokens()->create([
            'name' => self::TOKEN_NAME,
            'token' => hash('sha256', $plain),
            'abilities' => self::ABILITIES[$access],
        ]);
        $token->forceFill(['key_hint' => $hint])->save();

        return response()->json([
            'success' => true,
            'message' => 'API key rotated. Copy it now — it will not be shown again.',
            'data' => [
                'key' => $plain,
                'hint' => $hint,
                'access' => $access,
                'created_at' => $token->created_at,
            ],
        ]);
    }

    /**
     * Map stored abilities back to an access level. The legacy `mcp` ability
     * and the presence of `mcp:write` both mean full access.
     */
    private static function accessLevel(array $abilities): string
    {
        return array_intersect(['mcp', 'mcp:write'], $abilities) ? 'full' : 'read';
    }
}
