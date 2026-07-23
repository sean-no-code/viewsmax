<?php

namespace App\Http\Controllers;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Brands
 *
 * Named groups of connected accounts. Selecting a brand in the composer
 * auto-selects every account in it. Members may come from either account
 * store: social_accounts (X, Instagram, ...) or legacy connections
 * (YouTube, TikTok).
 */
class BrandController extends Controller
{
    /**
     * List the authenticated user's brands with their member accounts.
     */
    public function index()
    {
        $brands = Auth::user()->brands()
            ->with(['socialAccounts', 'connections'])
            ->orderBy('name')
            ->get();

        return BrandResource::collection($brands)->additional(['success' => true]);
    }

    /**
     * Create a brand.
     *
     * @bodyParam name string required Brand name, unique per user. Example: Acme
     * @bodyParam social_account_ids integer[] Ids from the social accounts store. Example: [1, 2]
     * @bodyParam connection_ids integer[] Ids from the legacy connections store (YouTube/TikTok). Example: [3]
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $brand = Auth::user()->brands()->create(['name' => $data['name']]);
        $this->syncMembers($brand, $data);

        return response()->json([
            'success' => true,
            'message' => 'Brand created.',
            'data' => new BrandResource($brand->load(['socialAccounts', 'connections'])),
        ], 201);
    }

    /**
     * Update a brand. Member lists are replaced only when their key is present,
     * so a rename-only payload never wipes membership.
     */
    public function update(Request $request, string $id)
    {
        $brand = Auth::user()->brands()->findOrFail($id);
        $data = $this->validatePayload($request, $brand);

        $brand->update(['name' => $data['name']]);
        $this->syncMembers($brand, $data);

        return response()->json([
            'success' => true,
            'message' => 'Brand updated.',
            'data' => new BrandResource($brand->fresh(['socialAccounts', 'connections'])),
        ]);
    }

    /**
     * Delete a brand. Member accounts are untouched; posts keep existing but
     * lose their brand link.
     */
    public function destroy(string $id)
    {
        Auth::user()->brands()->findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted.',
        ]);
    }

    private function validatePayload(Request $request, ?Brand $brand = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('brands')->where('user_id', Auth::id())->ignore($brand?->id),
            ],
            'social_account_ids' => 'sometimes|array',
            'social_account_ids.*' => 'integer',
            'connection_ids' => 'sometimes|array',
            'connection_ids.*' => 'integer',
        ]);

        if (array_key_exists('social_account_ids', $data)) {
            $data['social_account_ids'] = $this->ownedIds(
                'social_account_ids',
                $data['social_account_ids'],
                Auth::user()->socialAccounts(),
            );
        }
        if (array_key_exists('connection_ids', $data)) {
            $data['connection_ids'] = $this->ownedIds(
                'connection_ids',
                $data['connection_ids'],
                Auth::user()->connections(),
            );
        }

        return $data;
    }

    /**
     * Dedupe the requested ids and reject any that don't belong to the user.
     */
    private function ownedIds(string $field, array $ids, $ownedQuery): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $owned = $ownedQuery->whereIn('id', $ids)->pluck('id')->all();
        $unknown = array_diff($ids, $owned);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $field => 'Unknown account id: '.implode(', ', $unknown),
            ]);
        }

        return $ids;
    }

    /**
     * sync() against the shared pivot is safe per store: SQL three-valued
     * logic means "social_account_id NOT IN (...)" never matches the rows
     * where that column is NULL (the connection rows), and vice versa.
     */
    private function syncMembers(Brand $brand, array $data): void
    {
        if (array_key_exists('social_account_ids', $data)) {
            $brand->socialAccounts()->sync($data['social_account_ids']);
        }
        if (array_key_exists('connection_ids', $data)) {
            $brand->connections()->sync($data['connection_ids']);
        }
    }
}
