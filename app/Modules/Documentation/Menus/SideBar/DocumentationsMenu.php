<?php

namespace App\Modules\Documentation\Menus\SideBar;

use App\Modules\Taxonomy\Models\Category;

/**
 * Builds the Documentation workspace category menu.
 */
class DocumentationsMenu
{
    /**
     * Retrieves all active categories that have at least one documentation template.
     *
     * @return array A list of menu items for the sidebar.
     */
    public function DocumentationsMenu(): array
    {
        // Template categories are dynamic, while vendors and suppliers are fixed
        // Documentation-owned master data registers.
        $categories = Category::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereHas('templates')
                    ->orWhereIn('slug', ['vendors', 'suppliers']);
            })
            ->orderBy('name')
            ->get();

        // Default option to view all documentations
        $menu = [
            ['name' => 'All', 'route' => 'tech.documentations.index', 'params' => ['cat' => 'all']],
        ];

        // Add each category that has templatesManagement to the menu
        foreach ($categories as $category) {
            $menu[] = [
                'name' => $category->name,
                'route' => 'tech.documentations.index',
                'params' => ['cat' => $category->slug],
            ];
        }

        foreach ([['Vendors', 'vendors'], ['Suppliers', 'suppliers']] as [$name, $slug]) {
            if ($categories->contains('slug', $slug)) {
                continue;
            }

            $menu[] = [
                'name' => $name,
                'route' => 'tech.documentations.index',
                'params' => ['cat' => $slug],
            ];
        }

        return $menu;
    }
}
