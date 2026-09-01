<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Modules\Documentation\Menus\SideBar\DocumentationsMenu;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationsMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentations_menu_returns_items_in_correct_order(): void
    {
        // Create a dynamic category with a template
        $category = Category::query()->create([
            'name' => 'Custom Category',
            'slug' => 'custom-category',
            'type' => 'documentation',
            'is_active' => true,
        ]);

        DocumentationTemplate::query()->create([
            'category_id' => $category->id,
            'name' => 'Test Template',
            'fields' => [],
            'is_active' => true,
        ]);

        $menu = (new DocumentationsMenu())->DocumentationsMenu();

        // Expected order: Shipping Carriers, Suppliers, Vendors, Header, All, Custom Category
        $this->assertEquals('Shipping Carriers', $menu[0]['name']);
        $this->assertEquals('Suppliers', $menu[1]['name']);
        $this->assertEquals('Vendors', $menu[2]['name']);
        $this->assertEquals('Documentation categories', $menu[3]['name']);
        $this->assertTrue($menu[3]['is_header'] ?? false);
        $this->assertEquals('All', $menu[4]['name']);
        $this->assertEquals('Custom Category', $menu[5]['name']);
    }

    public function test_documentations_menu_always_includes_header_and_all(): void
    {
        // No dynamic categories created
        $menu = (new DocumentationsMenu())->DocumentationsMenu();

        // Expected order: Shipping Carriers, Suppliers, Vendors, Header, All
        $this->assertEquals('Shipping Carriers', $menu[0]['name']);
        $this->assertEquals('Suppliers', $menu[1]['name']);
        $this->assertEquals('Vendors', $menu[2]['name']);
        $this->assertEquals('Documentation categories', $menu[3]['name']);
        $this->assertEquals('All', $menu[4]['name']);
    }
}
