<?php

namespace App\Livewire\Knowledge;

use App\Models\Knowledge\Article;
use App\Models\System\Category;
use App\Models\Clients\Client;
use Illuminate\Support\Str;
use Livewire\Component;

class ArticleForm extends Component
{
    public $article;
    public $categories;
    public $clients;

    // Form fields
    public $title;
    public $body_markdown;
    public $category_id;
    public $visibility = 'internal';
    public $status = 'published';
    public $client_scope_id;
    public $next_review_at;

    public function mount(Article $article)
    {
        $this->article = $article;
        $this->categories = Category::all();
        $this->clients = Client::all();

        if ($article->exists) {
            $this->title = $article->title;
            $this->body_markdown = $article->body_markdown;
            $this->category_id = $article->category_id;
            $this->visibility = $article->visibility;
            $this->status = $article->status;
            $this->client_scope_id = $article->client_scope_id;
            $this->next_review_at = $article->next_review_at ? $article->next_review_at->format('Y-m-d') : null;
        } else {
            // Defaults for new article
            $this->status = 'published';
            $this->visibility = 'internal';
            $this->next_review_at = now()->addYear()->format('Y-m-d');
        }
    }

    public function save()
    {
        $rules = [
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ];

        $validated = $this->validate($rules);

        $this->article->fill($validated);

        // Custom logic
        $this->article->body_html = $this->body_markdown; // In real app, parse markdown

        if (!$this->article->exists) {
            $this->article->owner_id = auth()->id();
            $this->article->created_by = auth()->id();
            $this->article->slug = Str::slug($this->title) . '-' . Str::random(5);
        } else {
            $this->article->updated_by = auth()->id();
            if ($this->article->isDirty('title')) {
                $this->article->slug = Str::slug($this->title) . '-' . Str::random(5);
            }
        }

        $this->article->save();

        session()->flash('success', $this->article->wasRecentlyCreated ? 'Article created successfully.' : 'Article updated successfully.');

        return redirect()->route('tech.knowledge.show', $this->article->id);
    }

    public function render()
    {
        return view('livewire.knowledge.article-form');
    }
}
