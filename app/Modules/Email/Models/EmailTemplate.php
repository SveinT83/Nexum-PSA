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
    | Version 1 assumes a small set of seeded templates, typically one template
    | per outbound use such as ticket_reply. Future routing can choose templates
    | by client, language, brand, queue, or workflow rule without changing the
    | storage model.
    |
    */
    protected $fillable = [
        'scope',
        'key',
        'name',
        'subject',
        'body_html',
        'body_text',
        'variables',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const SCOPES = [
        'tickets' => 'Tickets',
        'system' => 'System notifications',
        'sales' => 'Sales',
        'alerts' => 'Alerts',
    ];
}
