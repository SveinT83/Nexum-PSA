<?php

namespace App\Modules\Signal\Support;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Signal\Models\Signal;
use Illuminate\Http\Request;

/** Direct API execution uses the current caller, never a configured privileged impersonation. */
class SignalApiExecutionPolicy
{
    public function inspect(Request $request, Signal $signal, array $action): array
    {
        $type = $action['type'] ?? '';
        $permissions = match ($type) {
            'marketing_suppress_contact_email', 'tag_contact' => ['contact.update'],
            'tag_client' => ['client.update'],
            'sales_follow_up' => ['sales.opportunity_manage'],
            'ticket_follow_up' => ['ticket.create'],
            'task_follow_up' => ['task.create'],
            'portal_invitation' => ['customer_portal.invite'],
            'storage_supplier_order_import' => ['storage.purchase_import_execute'],
            'webhook' => ['signal.webhook.manage'],
            'emit_signal' => [],
            default => ['signal.action.execute'],
        };
        $external = in_array($type, ['webhook', 'portal_invitation', 'storage_supplier_order_import'], true);
        $abilities = ['signals.actions.execute', ...($external ? ['signals.providers.execute'] : [])];
        $reasons = [];
        foreach ($permissions as $permission) {
            if (! app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor($request->user(), $permission)) {
                $reasons[] = 'Missing user permission: '.$permission;
            }
        }
        foreach ($abilities as $ability) {
            if (! $request->user()->tokenCan($ability)) {
                $reasons[] = 'Missing token ability: '.$ability;
            }
        }
        if ($external && ! config('signal.api_external_execution_enabled', false)) {
            $reasons[] = 'External API execution is disabled.';
        }
        foreach (['actor_id', 'creator_id'] as $field) {
            if (filled($action[$field] ?? null) && (int) $action[$field] !== (int) $request->user()->id) {
                $reasons[] = 'The action selects another actor.';
            }
        }
        $siteId = $action['site_id'] ?? data_get($signal->payload, 'matched_site_id');
        if ($siteId && ! ClientSite::whereKey($siteId)->where('client_id', $signal->client_id)->exists()) {
            $reasons[] = 'The action site is outside the Signal client.';
        }
        if ($type === 'ticket_follow_up') {
            $contactId = $action['contact_id'] ?? data_get($signal->payload, 'matched_client_user_id') ?? $signal->contact?->clientUser?->id;
            if ($contactId && ! ClientUser::whereKey($contactId)->where('active', true)
                ->whereHas('site', fn ($q) => $q->where('client_id', $signal->client_id))->exists()) {
                $reasons[] = 'The Ticket contact is outside the Signal client.';
            }
        }
        if ($type === 'storage_supplier_order_import') {
            $messageId = data_get($signal->payload, 'email_message_id') ?: $signal->source_id;
            $message = EmailMessage::find($messageId);
            if (! $message || ! self::canReadSource($request, $message)) {
                $reasons[] = 'The current actor cannot access the canonical source Email.';
            }
        }

        return ['allowed' => $reasons === [], 'permissions' => $permissions, 'abilities' => $abilities,
            'external' => $external, 'reasons' => $reasons];
    }

    /** Use the decision query only: preview must not write unread epochs or access events. */
    public static function canReadSource(Request $request, EmailMessage $message): bool
    {
        $message->loadMissing('account');

        return $message->hasActiveProviderPlacement() && $message->account
            && app(\App\Modules\Email\Services\ResolveMailboxAccessDecision::class)
                ->resolve($request->user(), $message->account, MailboxAccess::VIEW)->allowed;
    }

    public function authorize(Request $request, Signal $signal, array $action): void
    {
        $check = $this->inspect($request, $signal, $action);
        if (! $check['allowed']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['action' => $check['reasons']]);
        }
        if (($action['type'] ?? null) === 'storage_supplier_order_import') {
            $request->attributes->set('signal_source_email_ids', array_values(array_unique([
                ...$request->attributes->get('signal_source_email_ids', []),
                (int) (data_get($signal->payload, 'email_message_id') ?: $signal->source_id),
            ])));
        }
        SignalApiAccess::requirePermissions($request, $check['permissions']);
        foreach ($check['abilities'] as $ability) {
            SignalApiAccess::requireAbility($request, $ability);
        }
    }
}
