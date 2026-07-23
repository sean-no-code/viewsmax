<?php

namespace App\Http\Controllers;

use App\Models\LibraryComponent;
use App\Models\LibraryComponentTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LibraryComponentController extends Controller
{
    public function index()
    {
        $components = LibraryComponent::where('user_id', Auth::id())
            ->with('tags')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $components
        ]);
    }

    public function store(Request $request)
    {
        $types = implode(',', LibraryComponent::getTypes());
        $request->validate([
            'type' => 'required|string|in:' . $types,
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);

        $component = LibraryComponent::create([
            'user_id' => Auth::id(),
            'type' => $request->type,
            'title' => $request->title,
            'body' => $request->body,
        ]);

        // Sync tags if provided
        if ($request->has('tags') && is_array($request->tags)) {
            $tagIds = [];
            foreach ($request->tags as $tagName) {
                $tag = LibraryComponentTag::firstOrCreate(['name' => trim($tagName)]);
                $tagIds[] = $tag->id;
            }
            $component->tags()->sync($tagIds);
        }

        // Reload with tags
        $component->load('tags');

        return response()->json([
            'success' => true,
            'data' => $component
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $component = LibraryComponent::where('user_id', Auth::id())->findOrFail($id);

        $types = implode(',', LibraryComponent::getTypes());
        $request->validate([
            'type' => 'sometimes|string|in:' . $types,
            'title' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);

        $component->update($request->only(['type', 'title', 'body']));

        // Sync tags if provided
        if ($request->has('tags') && is_array($request->tags)) {
            $tagIds = [];
            foreach ($request->tags as $tagName) {
                $tag = LibraryComponentTag::firstOrCreate(['name' => trim($tagName)]);
                $tagIds[] = $tag->id;
            }
            $component->tags()->sync($tagIds);
        }

        // Reload with tags
        $component->load('tags');

        return response()->json([
            'success' => true,
            'data' => $component
        ]);
    }

    public function destroy($id)
    {
        $component = LibraryComponent::where('user_id', Auth::id())->findOrFail($id);
        $component->delete();

        return response()->json([
            'success' => true,
            'message' => 'Component deleted'
        ]);
    }
}
