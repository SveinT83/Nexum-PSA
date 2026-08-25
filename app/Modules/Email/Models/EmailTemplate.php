<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Outbound template model
    |--------------------------------------------------------------------------
    |
    | Templates are intentionally stored in the Email module because they are
    | rendered and sent by the outbound email flow, even when edited from the
    | global Templates hub.
    |
    | Body HTML is content owned by the template. The surrounding document is
    | either generated from live company branding or stored as an intentional
    | custom layout. This explicit state prevents ordinary copy edits from
    | accidentally freezing future branding changes.
    |
    */
    protected $fillable = [
        'scope',
        'key',
        'name',
        'subject',
        'body_html',
        'body_text',
        'layout_mode',
        'layout_html',
        'variables',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const LAYOUT_BRANDING = 'branding';

    public const LAYOUT_CUSTOM = 'custom';

    public const LAYOUT_MODES = [
        self::LAYOUT_BRANDING => 'Branding managed',
        self::LAYOUT_CUSTOM => 'Custom HTML',
    ];

    public const SCOPES = [
        'tickets' => 'Tickets',
        'system' => 'System notifications',
        'sales' => 'Sales',
        'marketing' => 'Marketing',
        'alerts' => 'Alerts',
    ];

    public function usesCustomLayout(): bool
    {
        return $this->layout_mode === self::LAYOUT_CUSTOM;
    }
}
