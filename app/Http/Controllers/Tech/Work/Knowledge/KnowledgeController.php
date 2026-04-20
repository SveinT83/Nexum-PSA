<?php

namespace App\Http\Controllers\Tech\Work\Knowledge;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Models\System\Category;
use App\Models\Clients\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeController extends Controller
{
    public function index()
    {
        $articles = Article::with(['category', 'owner'])
            ->latest()
            ->paginate(20);

        return view('tech.knowledge.index', compact('articles'));
    }

    public function create()
    {
        $article = new Article();
        return view('tech.knowledge.create', compact('article'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ]);

        $article = new Article($validated);
        $article->owner_id = auth()->id();
        $article->created_by = auth()->id();
        $article->slug = Str::slug($request->title) . '-' . Str::random(5);
        // In a real app we would convert markdown to html here
        $article->body_html = $request->body_markdown;
        $article->save();

        return redirect()->route('tech.knowledge.show', $article->id)
            ->with('success', 'Article created successfully.');
    }

    public function show($id)
    {
        $article = Article::with(['category', 'owner', 'clientScope', 'creator', 'updater'])->findOrFail($id);
        $article->increment('view_count');

        return view('tech.knowledge.show', compact('article'));
    }

    public function edit($id)
    {
        $article = Article::findOrFail($id);
        return view('tech.knowledge.create', compact('article'));
    }

    public function update(Request $request, $id)
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ]);

        $article->fill($validated);
        $article->updated_by = auth()->id();
        if ($article->isDirty('title')) {
            $article->slug = Str::slug($request->title) . '-' . Str::random(5);
        }
        $article->body_html = $request->body_markdown;
        $article->save();

        return redirect()->route('tech.knowledge.show', $article->id)
            ->with('success', 'Article updated successfully.');
    }

    public function destroy($id)
    {
        $article = Article::findOrFail($id);
        $article->delete();

        return redirect()->route('tech.knowledge.index')
            ->with('success', 'Article deleted successfully.');
    }
}
