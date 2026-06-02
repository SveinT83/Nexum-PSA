<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesSetting;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EnsureSalesDefaults
{
    public const STATUSES = [
        'new_lead' => ['label' => 'New lead', 'probability' => 10],
        'contact_lead' => ['label' => 'Contact lead', 'probability' => 10],
        'contacted' => ['label' => 'Contacted', 'probability' => 20],
        'needs_discovery' => ['label' => 'Needs discovery', 'probability' => 30],
        'quote_ready' => ['label' => 'Quote ready', 'probability' => 40],
        'quote_sent' => ['label' => 'Quote sent', 'probability' => 50],
        'negotiation' => ['label' => 'Negotiation', 'probability' => 70],
        'won' => ['label' => 'Won', 'probability' => 100],
        'lost' => ['label' => 'Lost', 'probability' => 0],
        'not_qualified' => ['label' => 'Not qualified', 'probability' => 0],
        'no_quote_allowed' => ['label' => 'No quote allowed', 'probability' => 0],
        'follow_up_later' => ['label' => 'Follow up later', 'probability' => 10],
    ];

    public const TYPES = [
        'service_agreement' => 'Service agreement',
        'equipment_sale' => 'Equipment sale',
        'project' => 'Project',
        'renewal' => 'Renewal',
        'upsell' => 'Upsell / additional service',
        'other' => 'Other',
    ];

    public const NEXT_ACTIONS = [
        'call' => 'Call',
        'meeting' => 'Meeting',
        'email' => 'Send email',
        'quote_follow_up' => 'Quote follow-up',
        'discovery' => 'Discovery',
        'demo' => 'Demo',
        'proposal_review' => 'Proposal review',
        'follow_up_later' => 'Follow up later',
    ];

    public static function normalizeNextAction(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (array_key_exists($value, self::NEXT_ACTIONS)) {
            return $value;
        }

        foreach (self::NEXT_ACTIONS as $key => $label) {
            if (strtolower($value) === strtolower($label)) {
                return $key;
            }
        }

        return $value;
    }

    public static function nextActionLabel(?string $value): ?string
    {
        return self::NEXT_ACTIONS[$value] ?? $value;
    }


    public const PERMISSIONS = [
        'sales.view',
        'sales.manage',
        'sales.quote.send',
        'sales.quote.approve_discount',
        'sales.settings',
        'sales.admin',
    ];

    public function handle(): void
    {
        $this->setting('quote_expiry_days', 30);
        $this->setting('create_calendar_followups', true);
        $this->setting('quote_expiry_calendar_reminder_days', 3);
        $this->setting('default_followup_duration_minutes', 30);
        $this->setting('auto_create_onboarding_ticket', false);
        $this->setting('require_seller_instructions_for_onboarding', true);
        $this->ensurePermissions();
    }

    private function setting(string $key, mixed $value): void
    {
        SalesSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
    }

    private function ensurePermissions(): void
    {
        if (! class_exists(Permission::class) || ! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Role::query()->whereIn('name', ['Admin', 'Superuser'])->get() as $role) {
            $role->givePermissionTo(self::PERMISSIONS);
        }

        if ($tech = Role::query()->where('name', 'Tech')->first()) {
            $tech->givePermissionTo(['sales.view', 'sales.manage', 'sales.quote.send']);
        }
    }
}
