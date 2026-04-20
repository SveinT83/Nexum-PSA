<?php

namespace App\Http\Controllers\Tech\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\System\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    public function index()
    {
        $tags = Tag::withCount('usages')->get();

        // Grupper bruk etter tabell/modell for å gi oversikt
        $usageStats = DB::table('taggables')
            ->select('taggable_type', DB::raw('count(*) as total'))
            ->groupBy('taggable_type')
            ->get();

        return view('tech.admin.system.tag.index', compact('tags', 'usageStats'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
        ]);

        Tag::create($validated);

        return redirect()->route('tech.admin.system.tag.index')
            ->with('success', 'Tag created successfully.');
    }

    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name,' . $tag->id,
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $tag->update($validated);

        return redirect()->route('tech.admin.system.tag.index')
            ->with('success', 'Tag updated successfully.');
    }

    public function destroy(Tag $tag)
    {
        // Vi sjekker kanskje om den er i bruk før sletting?
        // For nå tillater vi sletting (soft delete)
        $tag->delete();

        return redirect()->route('tech.admin.system.tag.index')
            ->with('success', 'Tag deleted.');
    }
}
