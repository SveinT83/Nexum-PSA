# Tag System Documentation

## Overview
The Tag System is a flexible, polymorphic tagging module designed to allow various models across the system to be tagged with custom labels. It supports custom colors, descriptions, and tracks usage across different modules.

## Database Structure

### `tags` table
Stores the definition of each tag.
- `name`: The display name of the tag (unique).
- `slug`: URL-friendly version of the name.
- `color`: Hex color code for visual representation.
- `description`: Optional text explaining the tag's purpose.
- `active`: Boolean flag to enable/disable the tag.

### `taggables` table (Pivot)
A polymorphic pivot table that links tags to other models.
- `tag_id`: Foreign key to `tags`.
- `taggable_id`: The ID of the related model.
- `taggable_type`: The class name of the related model.
- `module`: (Optional) Helper column to track which high-level module the usage belongs to.

## Implementation Guide

### 1. Making a model "Taggable"
To allow a model to use tags, add the `MorphToMany` relationship:

```php
use App\Modules\Taxonomy\Models\Tag;

public function tags()
{
    return $this->morphToMany(Tag::class, 'taggable', 'taggables')
                ->withPivot('module')
                ->withTimestamps();
}
```

### 2. Reusable Tag Manager Component
There is a Livewire component available for managing tags on any model:

```blade
<livewire:system.tag-manager :model="$yourModel" module="your_module_name" />
```

This component handles adding, removing, and creating tags. Note that the model must be saved (exist in database) before tags can be attached.

### 3. Managing Tags (Admin)
The admin interface is located at `/tech/admin/system/tag`. 
- **Create**: Add new global tags with specific colors.
- **Edit**: Update names, colors, or deactivate tags.
- **Usage Stats**: View how many times tags are used by different model types (e.g., Articles, Tickets).

## Future Enhancements
- Hierarchy support (parent/child tags).
- Tag groups/categories.
- Auto-tagging rules based on content.
