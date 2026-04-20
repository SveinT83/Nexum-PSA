<?php

namespace App\Livewire\System;

use App\Models\System\Tag;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class TagManager extends Component
{
    public $model;
    public $module;
    public $search = '';
    public $showSuggestions = false;

    // Remove $listeners = ['tagAdded' => '$refresh', 'tagRemoved' => '$refresh'];
    // Livewire 3 handles it differently and $refresh isn't strictly necessary if we update model state.

    public function mount(Model $model, $module = null)
    {
        $this->model = $model;
        $this->module = $module ?? $this->inferModule();
    }

    protected function inferModule()
    {
        $class = get_class($this->model);
        if (str_contains($class, 'Knowledge')) return 'knowledge';
        if (str_contains($class, 'Client')) return 'crm';
        return 'general';
    }

    public function addTag($tagId)
    {
        if (!$this->model->exists) {
             return;
        }

        if (!$this->model->tags()->where('tags.id', $tagId)->exists()) {
            $this->model->tags()->attach($tagId, ['module' => $this->module]);
        }

        $this->search = '';
        $this->showSuggestions = false;
        $this->model->load('tags');
    }

    public function removeTag($tagId)
    {
        $this->model->tags()->detach($tagId);
        $this->model->load('tags');
    }

    public function createAndAddTag()
    {
        if (empty(trim($this->search))) return;

        $tag = Tag::firstOrCreate(
            ['name' => trim($this->search)],
            ['color' => '#6c757d']
        );

        $this->addTag($tag->id);
    }

    public function render()
    {
        $query = Tag::query()->where('active', true);

        if (!empty($this->search)) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        $suggestions = (!empty($this->search)) ? $query->limit(5)->get() : collect();
        $attachedTags = $this->model->exists ? $this->model->tags : collect();

        return view('livewire.system.tag-manager', [
            'suggestions' => $suggestions,
            'attachedTags' => $attachedTags,
        ]);
    }
}
