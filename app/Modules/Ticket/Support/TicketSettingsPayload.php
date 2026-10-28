<?php

namespace App\Modules\Ticket\Support;

/** Fixed setting contracts are shared by browser and API; arbitrary setting keys are never accepted. */
class TicketSettingsPayload
{
    public static function email(): array
    {
        return ['email_account_id' => ['nullable', 'integer', 'exists:email_accounts,id']];
    }

    public static function solution(): array
    {
        return ['allow_internal_solution_notes' => ['nullable', 'boolean']];
    }

    public static function portal(): array
    {
        return ['default_customer_visibility' => ['required', 'string', 'in:'.implode(',', array_keys(TicketPortalPolicy::visibilityOptions()))]];
    }

    public static function quoteCost(): array
    {
        return ['quote_required_cost_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99']];
    }

    public static function merge(): array
    {
        return ['auto_merge_enabled' => ['nullable', 'boolean'], 'ai_merge_enabled' => ['nullable', 'boolean'], 'ai_similarity_threshold' => ['required', 'integer', 'min:70', 'max:100']];
    }
}
