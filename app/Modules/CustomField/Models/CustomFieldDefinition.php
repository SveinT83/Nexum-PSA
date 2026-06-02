<?php

namespace App\Modules\CustomField\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomFieldDefinition extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_URL = 'url';

    public const SUPPORTED_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_NUMBER,
        self::TYPE_DATE,
        self::TYPE_DATETIME,
        self::TYPE_SELECT,
        self::TYPE_MULTISELECT,
        self::TYPE_CHECKBOX,
        self::TYPE_EMAIL,
        self::TYPE_PHONE,
        self::TYPE_URL,
    ];

    protected $fillable = [
        'model_type',
        'key',
        'label',
        'field_type',
        'help_text',
        'options',
        'default_value',
        'sort_order',
        'visible_in_ui',
        'editable_in_ui',
        'editable_via_api',
        'searchable',
        'unique_per_model',
        'required',
        'admin_only',
        'view_permission',
        'edit_permission',
        'active',
    ];

    protected $casts = [
        'options' => 'array',
        'default_value' => 'array',
        'visible_in_ui' => 'boolean',
        'editable_in_ui' => 'boolean',
        'editable_via_api' => 'boolean',
        'searchable' => 'boolean',
        'unique_per_model' => 'boolean',
        'required' => 'boolean',
        'admin_only' => 'boolean',
        'active' => 'boolean',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
