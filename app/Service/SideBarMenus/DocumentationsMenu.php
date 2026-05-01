<?php

namespace App\Service\SideBarMenus;

use App\Models\System\Category;

/**
     * This class generates the sidebar menu for documentation.
     * It is "smart" in that it only displays categories that actually have documentation templatesManagement associated with them.
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
        // Get categories that have the 'templatesManagement' relation (DocumentationTemplate)
        $categories = Category::has('templates')
            ->where('is_active', true)
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

        return $menu;
    }
}
