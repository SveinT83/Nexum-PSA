<?php

namespace App\Modules\Taxonomy\Controllers\Admin;

use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['name', 'color', 'usages', 'status'];

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }

        $tags = Tag::withCount('usages')
            ->when($sort === 'name', fn ($query) => $query->orderBy('name', $direction))
            ->when($sort === 'color', fn ($query) => $query->orderBy('color', $direction)->orderBy('name'))
            ->when($sort === 'usages', fn ($query) => $query->orderBy('usages_count', $direction)->orderBy('name'))
            ->when($sort === 'status', fn ($query) => $query->orderBy('active', $direction)->orderBy('name'))
            ->get();

        // Grupper bruk etter tabell/modell for å gi oversikt
        $usageStats = DB::table('taggables')
            ->select('taggable_type', DB::raw('count(*) as total'))
            ->groupBy('taggable_type')
            ->get();

        return view('taxonomy::Admin.Tag.index', compact('tags', 'usageStats', 'sort', 'direction'));
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
