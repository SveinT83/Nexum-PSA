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

        // Start with the primary documentation pages
        $menu = [
            [
                'name' => 'Shipping Carriers',
                'route' => 'tech.documentations.shipping-carriers.index',
                'params' => [],
            ],
        ];

        foreach ([['Suppliers', 'suppliers'], ['Vendors', 'vendors']] as [$name, $slug]) {
            $menu[] = [
                'name' => $name,
                'route' => 'tech.documentations.index',
                'params' => ['cat' => $slug],
            ];
        }

        // Add Documentation Categories header
        $menu[] = ['name' => 'Documentation categories', 'is_header' => true];

        // Add 'All' under categories
        $menu[] = ['name' => 'All', 'route' => 'tech.documentations.index', 'params' => ['cat' => 'all']];

        $dynamicCategories = $categories->filter(fn ($c) => ! in_array($c->slug, ['vendors', 'suppliers'], true));

        foreach ($dynamicCategories as $category) {
            $menu[] = [
                'name' => $category->name,
                'route' => 'tech.documentations.index',
                'params' => ['cat' => $category->slug],
            ];
        }

        return $menu;
    }
}
