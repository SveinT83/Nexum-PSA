<?php

namespace App\Http\Controllers\Tech\Admin\System;

use App\Http\Controllers\Controller;
use App\Models\System\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Class CategoryController
 *
 * Handles administrative management of system categories.
 * Supports a unified "list, form, edit" UI for managing categories,
 * including hierarchical (parent/child) relationships and status toggling.
 *
 * @package App\Http\Controllers\Tech\Admin\System
 */
class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     * Includes logic to prepare data for a unified all-in-one UI.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        // Fetch all categories with their parent/child relationships
        $categories = Category::with(['parent', 'children'])
            ->withCount(['templates', 'services', 'children']) // Track usage in both modules and child categories
            ->get();

        // Get parent categories for selection in the form
        $parentCategories = Category::whereNull('parent_id')->get();

        return view('tech.admin.system.category.index', compact('categories', 'parentCategories'));
    }

    /**
     * Store a newly created category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $name = $request->input('name');
        $slug = Str::slug($name);

        // Check for duplicate slug before creating
        if (Category::where('slug', $slug)->exists()) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'A category with this name already exists (duplicate slug: ' . $slug . ').');
        }

        $data = $request->only(['name', 'type', 'parent_id', 'description']);
        $data['slug'] = $slug;
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        Category::create($data);

        return redirect()->route('tech.admin.system.category.index')
            ->with('success', 'Category created successfully.');
    }

    /**
     * Update the specified category in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\System\Category  $category
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:categories,id|different:id', // Cannot be its own parent
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $name = $request->input('name');
        $slug = Str::slug($name);

        // Check for duplicate slug if the name has changed
        if ($category->name !== $name) {
            if (Category::where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Another category with this name already exists (duplicate slug: ' . $slug . ').');
            }
        }

        $data = $request->only(['name', 'type', 'parent_id', 'description']);
        $data['slug'] = $slug;
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : false;

        $category->update($data);
        return redirect()->route('tech.admin.system.category.index')
            ->with('success', 'Category updated successfully.');
    }

    /**
     * Remove the specified category from storage (soft delete).
     *
     * @param  \App\Models\System\Category  $category
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Category $category)
    {
        // Prevent deletion if the category has linked services
        if ($category->services()->exists()) {
            return redirect()->route('tech.admin.system.category.index')
                ->with('error', 'Cannot delete category: It is currently linked to one or more services.');
        }

        // Prevent deletion if the category has linked documentation templates
        if ($category->templates()->exists()) {
            return redirect()->route('tech.admin.system.category.index')
                ->with('error', 'Cannot delete category: It is currently linked to one or more documentation templates.');
        }

        // Prevent deletion if the category has sub-categories
        if ($category->children()->exists()) {
            return redirect()->route('tech.admin.system.category.index')
                ->with('error', 'Cannot delete category: It has active sub-categories.');
        }

        // Perform soft delete
        $category->delete();

        return redirect()->route('tech.admin.system.category.index')
            ->with('success', 'Category deleted successfully.');
    }
}
