<?php

namespace App\Modules\Knowledge\Livewire;

use App\Models\Clients\Client;
use App\Models\Knowledge\Article;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Knowledge\Actions\UpdateArticle;
use Livewire\Component;

/**
 * Livewire form for creating and editing knowledge articles.
 *
 * The component owns UI state only: field values, select options, validation,
 * and the save event. Persistence is delegated to StoreArticle/UpdateArticle so
 * the same business behavior is shared with the standard controller routes.
 */
class ArticleForm extends Component
{
    /** The article being created or edited. */
    public Article $article;

    /** Category options shown in the form. */
    public $categories;

    /** Client options used when visibility is client-wide. */
    public $clients;

    public ?string $title = null;
    public ?string $body_markdown = null;
    public $category_id = null;
    public string $visibility = 'internal';
    public string $status = 'published';
    public $client_scope_id = null;
    public ?string $next_review_at = null;

    /**
     * Hydrate the form from an existing article or apply sensible defaults.
     */
    public function mount(Article $article): void
    {
        $this->article = $article;
        $this->categories = Category::orderBy('name')->get();
        $this->clients = Client::where('active', true)->orderBy('name')->get();

        if ($article->exists) {
            $this->title = $article->title;
            $this->body_markdown = $article->body_markdown;
            $this->category_id = $article->category_id;
            $this->visibility = $article->visibility;
            $this->status = $article->status;
            $this->client_scope_id = $article->client_scope_id;
            $this->next_review_at = $article->next_review_at?->format('Y-m-d');

            return;
        }

        $this->status = 'published';
        $this->visibility = 'internal';
        $this->next_review_at = now()->addYear()->format('Y-m-d');
    }

    /**
     * Validate and persist the article.
     *
     * Store/update actions are resolved from Laravel's container here so their
     * dependencies stay injectable while the Livewire method remains simple.
     */
    public function save()
    {
        $validated = $this->validate($this->rules());

        if ($this->article->exists) {
            app(UpdateArticle::class)->handle($this->article, $validated);
            $message = 'Article updated successfully.';
        } else {
            $this->article = app(StoreArticle::class)->handle($validated);
            $message = 'Article created successfully.';
        }

        session()->flash('success', $message);

        return redirect()->route('tech.knowledge.show', $this->article);
    }

    /**
     * Validation rules shared by create and edit modes.
     */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ];
    }

    /**
     * Render the module-local Livewire view.
     */
    public function render()
    {
        return view('knowledge::Livewire.article-form');
    }
}
