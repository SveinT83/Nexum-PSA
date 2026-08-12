<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
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
        'sales.quote.approve',
        'sales.quote.approve_discount',
        'sales.manage_settings',
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
        $this->setting('cpq_approval_policy', EvaluateSalesQuoteApproval::DEFAULT_POLICY);
        $this->ensureDefaultQuoteTemplate();
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

    private function ensureDefaultQuoteTemplate(): void
    {
        if (
            ! Schema::hasTable('sales_quote_templates')
            || ! Schema::hasTable('sales_quote_template_lines')
            || ! Schema::hasTable('sales_quote_template_acknowledgements')
        ) {
            return;
        }

        $template = SalesQuoteTemplate::withTrashed()
            ->where('template_key', 'QUOTE_TEMPLATES')
            ->first();

        if (! $template) {
            $template = SalesQuoteTemplate::query()->create([
                'template_key' => 'QUOTE_TEMPLATES',
                'name' => 'Quote Templates',
                'description' => 'Default reusable Sales quote template. Edit text, pricing, and lines before using it for a customer quote.',
                'is_active' => true,
                'target_type' => null,
                'customer_segment' => 'general',
                'intro_text' => 'Thank you for the opportunity. This quote describes the proposed solution, scope, and next steps.',
                'scope_text' => 'The final scope is based on the selected quote lines, quantities, and any written assumptions in this quote.',
                'assumptions_text' => 'Prices exclude VAT unless otherwise stated. Delivery depends on customer approval, available stock, and agreed scheduling.',
                'exclusions_text' => 'Work or products not listed in this quote are outside the included scope unless agreed in writing.',
                'next_steps_text' => 'Accept the quote to confirm the order. Nexum will then coordinate implementation and follow-up activities.',
                'seller_checklist' => [
                    'Confirm customer scope and quantities.',
                    'Replace default pricing with approved customer pricing.',
                    'Add required acknowledgements before sending.',
                ],
                'approval_policy_hints' => [
                    'Review margins and discounts before sending.',
                    'Confirm implementation owner after acceptance.',
                ],
            ]);
        }

        if ($template->trashed()) {
            return;
        }

        SalesQuoteTemplateLine::query()->firstOrCreate(
            [
                'template_id' => $template->id,
                'name' => 'Implementation',
            ],
            [
                'section' => 'implementation',
                'sort_order' => 10,
                'source_type' => 'custom',
                'downstream_type' => 'implementation',
                'billing_cadence' => 'one_time',
                'is_required' => true,
                'is_recommended' => true,
                'customer_selected_by_default' => true,
                'customer_quantity_editable' => false,
                'min_customer_quantity' => 1,
                'max_customer_quantity' => 1,
                'customer_label' => 'Implementation',
                'description' => 'Implementation and handover work for the accepted quote.',
                'quantity' => 1,
                'unit' => 'project',
                'unit_cost_ex_vat' => 0,
                'unit_price_ex_vat' => 0,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
                'source_snapshot' => [
                    'configured_source_type' => 'custom',
                    'configured_source_id' => null,
                    'seeded_default' => true,
                ],
            ],
        );

        SalesQuoteTemplateAcknowledgement::query()->firstOrCreate(
            [
                'template_id' => $template->id,
                'title' => 'Scope and pricing confirmed',
            ],
            [
                'body' => 'Customer confirms the selected scope, quantities, pricing, and assumptions in this quote.',
                'is_required' => true,
                'source_type' => 'quote_template',
                'source_id' => $template->id,
                'sort_order' => 10,
            ],
        );
    }
}
