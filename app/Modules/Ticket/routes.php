<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Ticket\Controllers\Admin\AssignmentRuleAdminController;
use App\Modules\Ticket\Controllers\Admin\TicketAssignmentSettingsAdminController;
use App\Modules\Ticket\Controllers\Admin\TicketRuleExecutionController;
use App\Modules\Ticket\Controllers\Admin\TicketRuleReadController;
use App\Modules\Ticket\Controllers\Admin\TicketSettingsController;
use App\Modules\Ticket\Controllers\Portal\PortalTicketController;
use App\Modules\Ticket\Controllers\Tech\TicketAssignmentSettingsController;
use App\Modules\Ticket\Controllers\Tech\TicketController;
use App\Modules\Ticket\Controllers\Tech\TicketSlaReportController;
use App\Modules\Ticket\Controllers\Tech\TicketWorkflowController;
use Illuminate\Support\Facades\Route;

// A bounded compatibility entry also supports installations without the full Ticket parity loader.
if (($ticketRelationshipApiRoutes ?? false) === true) {
    if (! Route::has('api.v1.tickets.relationship-targets.index')) {
        Route::get('tickets/{ticket}/relationship-targets', [\App\Modules\Ticket\Controllers\Api\V1\TicketRelationshipApiController::class, 'targets'])
            ->name('tickets.relationship-targets.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.relationships.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':relationships.escalate'])
            ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.relationships.read')->defaults('parity_permissions', 'relationships.escalate')->defaults('parity_additional_permissions', ['ticket.view']);
        Route::post('tickets/{ticket}/relationships/escalate', [\App\Modules\Ticket\Controllers\Api\V1\TicketRelationshipApiController::class, 'escalate'])
            ->name('tickets.relationships.escalate')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.relationships.escalate', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':relationships.escalate'])
            ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.relationships.escalate')->defaults('parity_permissions', 'relationships.escalate')->defaults('parity_additional_permissions', ['ticket.view'])->defaults('parity_input_fields', ['idempotency_key', 'relationship_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
        Route::get('tickets/{ticket}/relationship-escalations', [\App\Modules\Ticket\Controllers\Api\V1\TicketRelationshipApiController::class, 'index'])
            ->name('tickets.relationship-escalations.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.relationships.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
            ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.relationships.read')->defaults('parity_permissions', 'ticket.view');
        Route::get('tickets/{ticket}/relationship-escalations/{escalation}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRelationshipApiController::class, 'show'])
            ->whereNumber('escalation')->name('tickets.relationship-escalations.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.relationships.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
            ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.relationships.read')->defaults('parity_permissions', 'ticket.view');
        Route::get('tickets/{ticket}/relationship-links', [\App\Modules\Ticket\Controllers\Api\V1\TicketRelationshipApiController::class, 'links'])
            ->name('tickets.relationship-links.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.relationships.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
            ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.relationships.read')->defaults('parity_permissions', 'ticket.view');
    }
    return;
}

// Full operational API registration remains isolated from browser and compatibility routes.
if (($tdpsaLoadingApiRoutes ?? false) === true) {
    Route::get('ticket-settings/documentation-requests', [\App\Modules\Ticket\Controllers\Api\V1\TicketFollowUpApiController::class, 'administration'])
        ->name('ticket-settings.documentation-requests.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.read')->defaults('parity_permissions', 'ticket.manage_settings');

    Route::get('tickets/{ticket}/workflow-reviews', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'reviews'])
        ->name('tickets.workflow-reviews.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/workflow-reviews/{review}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'review'])
        ->name('tickets.workflow-reviews.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/workflow-evidence', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'evidence'])
        ->name('tickets.workflow-evidence.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/workflow-evidence/{evidence}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'evidenceRecord'])
        ->name('tickets.workflow-evidence.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/quote-versions', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'quotes'])
        ->name('tickets.quote-versions.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.quotes.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.quotes.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/quote-versions/{version}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowReadbackApiController::class, 'quote'])
        ->name('tickets.quote-versions.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.quotes.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.quotes.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();

    Route::get('tickets/{ticket}/history', [\App\Modules\Ticket\Controllers\Api\V1\TicketHistoryApiController::class, 'index'])
        ->withTrashed()->name('tickets.history.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.activities.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.activities.read')->defaults('parity_permissions', 'ticket.view');
    Route::get('tickets/{ticket}/history/{event}', [\App\Modules\Ticket\Controllers\Api\V1\TicketHistoryApiController::class, 'show'])
        ->withTrashed()->name('tickets.history.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.activities.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.activities.read')->defaults('parity_permissions', 'ticket.view');

    $ticketRelationshipApiRoutes = true;
    require __FILE__;
    unset($ticketRelationshipApiRoutes);

    Route::get('tickets/{ticket}/email-relationships', [\App\Modules\Ticket\Controllers\Api\V1\TicketEmailDraftApiController::class, 'relationships'])
        ->name('tickets.email-relationships.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.read', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.email-drafts.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.email-drafts.read')->defaults('parity_permissions', 'ticket.view')->defaults('parity_additional_scopes', ['email.read']);
    Route::post('tickets/{ticket}/email-relationships/{relationship}/reply-draft', [\App\Modules\Ticket\Controllers\Api\V1\TicketEmailDraftApiController::class, 'prepare'])
        ->name('tickets.email-relationships.reply-draft')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.write', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.email-drafts.create', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.reply_customer'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.email-drafts.create')->defaults('parity_permissions', 'ticket.reply_customer')->defaults('parity_additional_scopes', ['email.drafts.write'])->defaults('parity_additional_permissions', ['ticket.view'])->defaults('parity_input_fields', ['idempotency_key', 'mode'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('tickets/{ticket}/email-communications/{communication}', [\App\Modules\Ticket\Controllers\Api\V1\TicketEmailDraftApiController::class, 'show'])
        ->name('tickets.email-communications.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':email.drafts.read', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.email-drafts.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.email-drafts.read')->defaults('parity_permissions', 'ticket.view')->defaults('parity_additional_scopes', ['email.drafts.read']);

    Route::get('tickets/{ticket}/documentation-requests', [\App\Modules\Ticket\Controllers\Api\V1\TicketFollowUpApiController::class, 'index'])
        ->name('tickets.documentation-requests.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.documentation.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.documentation.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/documentation-requests/{documentationRequest}', [\App\Modules\Ticket\Controllers\Api\V1\TicketFollowUpApiController::class, 'show'])
        ->name('tickets.documentation-requests.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.documentation.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.documentation.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::post('tickets/{ticket}/documentation-requests', [\App\Modules\Ticket\Controllers\Api\V1\TicketFollowUpApiController::class, 'store'])
        ->name('tickets.documentation-requests.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.documentation.request', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.documentation.request')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['idempotency_key', 'reason'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('tickets/reports/sla', [\App\Modules\Ticket\Controllers\Api\V1\TicketFollowUpApiController::class, 'sla'])
        ->name('tickets.reports.sla')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.reports.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':report.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.reports.read')->defaults('parity_permissions', 'report.view');

    Route::get('portal/tickets/context', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'context'])
        ->name('portal.tickets.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets/sites', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'sites'])
        ->name('portal.tickets.sites')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'index'])
        ->name('portal.tickets.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets/{ticket}', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'show'])
        ->name('portal.tickets.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::post('portal/tickets', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'store'])
        ->name('portal.tickets.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.create', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.create')->defaults('parity_permissions', 'active_customer_portal_membership')->defaults('parity_input_fields', ['description', 'idempotency_key', 'membership_id', 'site_id', 'subject'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('portal/tickets/{ticket}/messages', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'messages'])
        ->name('portal.tickets.messages.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets/{ticket}/messages/{message}', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'message'])
        ->name('portal.tickets.messages.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::post('portal/tickets/{ticket}/messages', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'reply'])
        ->name('portal.tickets.messages.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.reply', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.reply')->defaults('parity_permissions', 'active_customer_portal_membership')->defaults('parity_input_fields', ['body', 'idempotency_key', 'membership_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('portal/tickets/{ticket}/attachments', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'attachments'])
        ->name('portal.tickets.attachments.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets/{ticket}/attachments/{attachment}', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'attachment'])
        ->name('portal.tickets.attachments.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.read', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.read')->defaults('parity_permissions', 'active_customer_portal_membership');
    Route::get('portal/tickets/{ticket}/attachments/{attachment}/download', [\App\Modules\Ticket\Controllers\Api\V1\PortalTicketApiController::class, 'download'])
        ->name('portal.tickets.attachments.download')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':portal.tickets.attachments.download', \App\Modules\CustomerPortal\Middleware\RequireCustomerPortalApiContext::class])
        ->defaults('parity_domain', 'portal-ticket')->defaults('parity_scope', 'portal.tickets.attachments.download')->defaults('parity_permissions', 'active_customer_portal_membership');

    Route::get('tickets/merge-suggestions', [\App\Modules\Ticket\Controllers\Api\V1\TicketMergeApiController::class, 'suggestions'])
        ->name('tickets.merge-suggestions.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view');
    Route::post('tickets/merge', [\App\Modules\Ticket\Controllers\Api\V1\TicketMergeApiController::class, 'merge'])
        ->name('tickets.merge.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.merge', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.merge')->defaults('parity_permissions', 'ticket.update')->defaults('parity_conditional_authorization', ['Every current linked mailbox: native MailOrganize permission. No preview required; cross-client sources are allowed.'])->defaults('parity_input_fields', ['idempotency_key', 'reason', 'source_ticket_ids', 'target_ticket_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tickets/merge-suggestions/dismiss', [\App\Modules\Ticket\Controllers\Api\V1\TicketMergeApiController::class, 'dismiss'])
        ->name('tickets.merge-suggestions.dismiss')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.merge', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.merge')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['reason', 'ticket_ids'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('ticket-rule-executions', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleExecutionApiController::class, 'index'])
        ->name('ticket-rule-executions.index')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.executions.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_execution_view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.executions.read')->defaults('parity_permissions', 'ticket.rule_execution_view')->defaults('parity_additional_permissions', ['ticket.view']);
    Route::get('ticket-rule-executions/{run}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleExecutionApiController::class, 'show'])
        ->name('ticket-rule-executions.show')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.executions.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_execution_view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.executions.read')->defaults('parity_permissions', 'ticket.rule_execution_view')->defaults('parity_additional_permissions', ['ticket.view']);
    Route::post('ticket-rule-executions/{run}/actions/{actionResult}/retry', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleExecutionApiController::class, 'retry'])
        ->name('ticket-rule-executions.retry')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.retry', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_retry'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.retry')->defaults('parity_permissions', 'ticket.rule_retry')->defaults('parity_additional_permissions', ['ticket.view'])->defaults('parity_input_fields', ['idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('ticket-rule-executions/{run}/full-rerun/preview', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleExecutionApiController::class, 'previewRerun'])
        ->name('ticket-rule-executions.rerun.preview')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_preview', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.full-rerun', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_full_rerun'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.full-rerun')->defaults('parity_permissions', 'ticket.rule_full_rerun')->defaults('parity_additional_permissions', ['ticket.view', 'ticket.rule_preview'])->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('ticket-rule-executions/{run}/full-rerun', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleExecutionApiController::class, 'rerun'])
        ->name('ticket-rule-executions.rerun.store')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_preview', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.full-rerun', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_full_rerun'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.full-rerun')->defaults('parity_permissions', 'ticket.rule_full_rerun')->defaults('parity_additional_permissions', ['ticket.view', 'ticket.rule_preview'])->defaults('parity_input_fields', ['confirm_full_rerun', 'preview_receipt'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('ticket-rules/readiness', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'readiness'])
        ->name('ticket-rules.readiness')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::get('ticket-rules/context/{catalog}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'catalog'])
        ->name('ticket-rules.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::get('ticket-rules', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'index'])
        ->name('ticket-rules.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::get('ticket-rules/{rule}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'show'])
        ->name('ticket-rules.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::get('ticket-rules/{rule}/draft', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'draft'])
        ->name('ticket-rules.draft.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::post('ticket-rules/drafts', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'storeDraft'])
        ->name('ticket-rules.draft.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['creation_token', 'definition', 'description', 'name'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-rules/{rule}/draft', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'saveDraft'])
        ->name('ticket-rules.draft.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['definition', 'description', 'expected_draft_checksum', 'expected_no_draft', 'name', 'source_definition_checksum'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('ticket-rules/preview', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'preview'])
        ->name('ticket-rules.preview')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.preview', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_preview'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.preview')->defaults('parity_permissions', 'ticket.rule_preview')->defaults('parity_additional_permissions', ['ticket.view', 'ticket.manage_rules'])->defaults('parity_input_fields', ['context', 'definition', 'ticket_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-rules/{rule}/versions', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'versions'])
        ->name('ticket-rules.versions.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::get('ticket-rules/{rule}/versions/{version}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'version'])
        ->name('ticket-rules.versions.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.read')->defaults('parity_permissions', 'ticket.manage_rules');
    Route::post('ticket-rules/{rule}/publish', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'publish'])
        ->name('ticket-rules.publish')->middleware([\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.publish', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.rule_publish'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.publish')->defaults('parity_permissions', 'ticket.rule_publish')->defaults('parity_additional_permissions', ['ticket.manage_rules'])->defaults('parity_input_fields', ['expected_draft_checksum', 'idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('ticket-rules/legacy', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'legacyStore'])
        ->name('ticket-rules.legacy.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['actions', 'conditions', 'description', 'idempotency_key', 'is_active', 'name', 'stop_processing', 'weight'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-rules/{rule}/legacy', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'legacyUpdate'])
        ->name('ticket-rules.legacy.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['actions', 'conditions', 'description', 'idempotency_key', 'is_active', 'name', 'stop_processing', 'weight'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-rules/{rule}/enabled', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'enabled'])
        ->name('ticket-rules.enabled')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['definition_checksum', 'expected_enabled', 'idempotency_key', 'is_active', 'published_version_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-rules/{rule}', [\App\Modules\Ticket\Controllers\Api\V1\TicketRuleApiController::class, 'destroy'])
        ->withTrashed()->name('ticket-rules.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.rules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.rules.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('me/ticket-assignment', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'own'])
        ->name('ticket-assignment.own')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.profile.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.profile.read')->defaults('parity_permissions', 'ticket.view');
    Route::put('me/ticket-assignment', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'updateOwn'])
        ->name('ticket-assignment.own.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.profile.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.profile.update')->defaults('parity_permissions', 'ticket.view')->defaults('parity_input_fields', ['category_ids', 'is_assignable', 'max_open_tickets', 'notes', 'tag_ids'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('me/ticket-assignment/context/{catalog}', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'ownContext'])
        ->name('ticket-assignment.own.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.profile.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.profile.read')->defaults('parity_permissions', 'ticket.view');
    Route::get('me/ticket-assignment/history', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'ownHistory'])
        ->name('ticket-assignment.own.history')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.profile.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.profile.read')->defaults('parity_permissions', 'ticket.view');
    Route::get('ticket-assignment-profiles', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'profiles'])
        ->name('ticket-assignment.profiles.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-assignment-profiles/{profile}', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'profile'])
        ->name('ticket-assignment.profiles.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-assignment-profiles', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'storeProfile'])
        ->name('ticket-assignment.profiles.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['user_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-assignment-profiles/{profile}', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'updateProfile'])
        ->name('ticket-assignment.profiles.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['category_ids', 'is_assignable', 'max_open_tickets', 'notes', 'tag_ids'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-assignment-rules', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'rules'])
        ->name('ticket-assignment.rules.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-assignment-rules/{rule}', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'rule'])
        ->name('ticket-assignment.rules.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-assignment-rules', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'storeRule'])
        ->name('ticket-assignment.rules.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', ['action_value', 'conditions', 'description', 'idempotency_key', 'is_active', 'name', 'stop_processing', 'weight'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-assignment-rules/{rule}', [\App\Modules\Ticket\Controllers\Api\V1\TicketAssignmentApiController::class, 'destroyRule'])
        ->name('ticket-assignment.rules.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assignment.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_rules'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assignment.manage')->defaults('parity_permissions', 'ticket.manage_rules')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('ticket-settings', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'show'])
        ->name('ticket-settings.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::put('ticket-settings/default-email-account', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'email'])
        ->name('ticket-settings.email')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.update')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['email_account_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-settings/solution-policy', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'solution'])
        ->name('ticket-settings.solution')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.update')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['allow_internal_solution_notes'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-settings/portal-policy', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'portal'])
        ->name('ticket-settings.portal')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.update')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['default_customer_visibility'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-settings/quote-cost-policy', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'quote'])
        ->name('ticket-settings.quote')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.update')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['quote_required_cost_threshold'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-settings/merge-policy', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'merge'])
        ->name('ticket-settings.merge')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.update')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['ai_merge_enabled', 'ai_similarity_threshold', 'auto_merge_enabled'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-settings/context/{catalog}', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'context'])
        ->name('ticket-settings.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-settings/history', [\App\Modules\Ticket\Controllers\Api\V1\TicketSettingsApiController::class, 'history'])
        ->name('ticket-settings.history')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.settings.read')->defaults('parity_permissions', 'ticket.manage_settings');

    Route::get('ticket-queues', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'queues'])
        ->name('ticket-queues.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-queues/{queue}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'showQueue'])
        ->name('ticket-queues.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-queues', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'storeQueue'])
        ->name('ticket-queues.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['description', 'email_address', 'is_active', 'is_default', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-queues/{queue}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'updateQueue'])
        ->name('ticket-queues.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['description', 'email_address', 'is_active', 'is_default', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-queues/{queue}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'destroyQueue'])
        ->name('ticket-queues.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-types', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'types'])
        ->name('ticket-types.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-types/{type}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'showType'])
        ->name('ticket-types.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-types', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'storeType'])
        ->name('ticket-types.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['description', 'is_active', 'is_deletable', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-types/{type}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'updateType'])
        ->name('ticket-types.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['description', 'is_active', 'is_deletable', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-types/{type}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'destroyType'])
        ->name('ticket-types.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-statuses', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'statuses'])
        ->name('ticket-statuses.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-statuses/{status}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'showStatus'])
        ->name('ticket-statuses.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-statuses', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'storeStatus'])
        ->name('ticket-statuses.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['is_active', 'is_closed', 'is_default', 'name', 'slug', 'sort_order', 'state'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-statuses/{status}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'updateStatus'])
        ->name('ticket-statuses.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['is_active', 'is_closed', 'is_default', 'name', 'slug', 'sort_order', 'state'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-statuses/{status}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'destroyStatus'])
        ->name('ticket-statuses.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('ticket-priorities', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'priorities'])
        ->name('ticket-priorities.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::get('ticket-priorities/{priority}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'showPriority'])
        ->name('ticket-priorities.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.read')->defaults('parity_permissions', 'ticket.manage_settings');
    Route::post('ticket-priorities', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'storePriority'])
        ->name('ticket-priorities.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['is_active', 'is_default', 'level', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('ticket-priorities/{priority}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'updatePriority'])
        ->name('ticket-priorities.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', ['is_active', 'is_default', 'level', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('ticket-priorities/{priority}', [\App\Modules\Ticket\Controllers\Api\V1\TicketCatalogApiController::class, 'destroyPriority'])
        ->name('ticket-priorities.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.catalogs.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_settings'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.catalogs.manage')->defaults('parity_permissions', 'ticket.manage_settings')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('tickets/{ticket}/lifecycle-state', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'state'])
        ->name('tickets.lifecycle-state.show')->withTrashed()->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view');
    Route::post('tickets/{ticket}/read', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'read'])
        ->name('tickets.read-state.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read-state.write', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read-state.write')->defaults('parity_permissions', 'ticket.view')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tickets/{ticket}/messages/{message}/read', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'readMessage'])
        ->name('tickets.messages.read-state.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read-state.write', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read-state.write')->defaults('parity_permissions', 'ticket.view')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('tickets/{ticket}/assignment-preview', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'assignmentPreview'])
        ->name('tickets.assignment.preview')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assign', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.assign'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assign')->defaults('parity_permissions', 'ticket.assign');
    Route::post('tickets/{ticket}/assignment', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'assign'])
        ->name('tickets.assignment.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.assign', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.assign'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.assign')->defaults('parity_permissions', 'ticket.assign')->defaults('parity_input_fields', ['idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('tickets/{ticket}', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'delete'])
        ->name('tickets.destroy')->withTrashed()->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.delete', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.delete'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.delete')->defaults('parity_permissions', 'ticket.delete')->defaults('parity_input_fields', ['expected_snapshot'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tickets/{ticket}/not-ticket', [\App\Modules\Ticket\Controllers\Api\V1\TicketLifecycleController::class, 'notTicket'])
        ->name('tickets.not-ticket')->withTrashed()->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.classify', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.delete'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.classify')->defaults('parity_permissions', 'ticket.delete')->defaults('parity_input_fields', ['expected_snapshot'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::patch('tickets/{ticket}/time-entries/{timeEntry}', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'updateTime'])
        ->name('tickets.time-entries.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.time.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.register_time'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.time.update')->defaults('parity_permissions', 'ticket.register_time')->defaults('parity_input_fields', ['invoice_text', 'minutes', 'note', 'rate_key', 'work_date'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tickets/{ticket}/time-entries/invoice-text-suggestion', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'draftInvoiceText'])
        ->name('tickets.time-entries.invoice-text-suggestion')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.time.suggest', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.register_time'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.time.suggest')->defaults('parity_permissions', 'ticket.register_time')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.messages.read')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view')->defaults('parity_additional_scopes', ['tickets.messages.read', 'tickets.read'])->defaults('parity_additional_permissions', ['ticket.view'])->defaults('parity_input_fields', ['existing_text', 'rate_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::patch('tickets/{ticket}/cost-entries/{costEntry}', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'updateCost'])
        ->name('tickets.cost-entries.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.update')->defaults('parity_permissions', 'ticket.update')->defaults('parity_conditional_authorization', ['Release of storage reservation: native storage.release policy.'])->defaults('parity_input_fields', ['invoice_text', 'note', 'quantity'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('tickets/{ticket}/cost-entries/{costEntry}', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'releaseCost'])
        ->name('tickets.cost-entries.release')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.release', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.release')->defaults('parity_permissions', 'ticket.update')->defaults('parity_conditional_authorization', ['Storage reservation release: native storage.release policy.'])->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('tickets/{ticket}/cost-entries/{costEntry}/pick-preview', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'pickPreview'])
        ->name('tickets.cost-entries.pick-preview')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.pick', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.pick')->defaults('parity_permissions', 'ticket.update')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':storage.pick')->defaults('parity_additional_permissions', ['storage.pick']);
    Route::post('tickets/{ticket}/cost-entries/{costEntry}/pick', [\App\Modules\Ticket\Controllers\Api\V1\TicketLedgerController::class, 'pickCost'])
        ->name('tickets.cost-entries.pick')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.pick', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.pick')->defaults('parity_permissions', 'ticket.update')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':storage.pick')->defaults('parity_additional_permissions', ['storage.pick'])->defaults('parity_input_fields', ['preview_token'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('tickets/{ticket}/conversation', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'index'])
        ->name('tickets.conversation.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.messages.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.messages.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/conversation/{message}', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'show'])
        ->name('tickets.conversation.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.messages.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.messages.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/attachments', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'attachments'])
        ->name('tickets.attachments.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.messages.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.messages.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/attachments/{attachment}', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'attachment'])
        ->name('tickets.attachments.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.messages.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.messages.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/attachments/{attachment}/download', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'download'])
        ->name('tickets.attachments.download')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.attachments.download', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.attachments.download')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::post('tickets/{ticket}/internal-notes', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'storeNote'])
        ->name('tickets.internal-notes.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.notes.create', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.note_internal'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.notes.create')->defaults('parity_permissions', 'ticket.note_internal')->defaults('parity_input_fields', ['attachments', 'body', 'idempotency_key', 'mark_as_solution', 'notify_user_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tickets/{ticket}/messages/{message}/solution', [\App\Modules\Ticket\Controllers\Api\V1\TicketConversationController::class, 'solution'])
        ->name('tickets.messages.solution')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.solutions.mark', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.solutions.mark')->defaults('parity_permissions', 'ticket.view')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('tickets/capabilities', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'capabilities'])
        ->name('tickets.capabilities')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view|ticket.create|ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view|ticket.create|ticket.update');
    Route::get('tickets/context', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'context'])
        ->name('tickets.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view|ticket.create|ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view|ticket.create|ticket.update');
    Route::get('tickets/context/{catalog}', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'options'])
        ->name('tickets.context.options')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view|ticket.create|ticket.update'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view|ticket.create|ticket.update')->defaults('parity_conditional_authorization', ['storage-items catalog: tickets.costs.read and ticket.view.']);
    Route::get('tickets/{ticket}/context', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'recordContext'])
        ->name('tickets.context.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/time-rate-options', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'rates'])
        ->name('tickets.time-rate-options.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.time.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.time.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/activities', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'activities'])
        ->name('tickets.activities.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/time-entries', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'timeEntries'])
        ->name('tickets.time-entries.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.time.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.time.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/time-entries/{timeEntry}', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'timeEntry'])
        ->name('tickets.time-entries.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.time.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.time.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/cost-entries', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'costEntries'])
        ->name('tickets.cost-entries.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/cost-entries/{costEntry}', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'costEntry'])
        ->name('tickets.cost-entries.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/planned-lines', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'plannedLines'])
        ->name('tickets.planned-lines.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.costs.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.costs.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
    Route::get('tickets/{ticket}/tasks', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'tasks'])
        ->name('tickets.tasks.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'ticket.view')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view')->defaults('parity_additional_permissions', ['task.view'])->withTrashed();
    Route::get('tickets/{ticket}/schedule', [\App\Modules\Ticket\Controllers\Api\V1\TicketReadbackController::class, 'schedule'])
        ->name('tickets.schedule.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])
        ->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();

    // Existing public API compatibility routes share this authoritative module route owner.
Route::get('tickets', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'index'])
    ->name('tickets.index')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view');

Route::get('tickets/stale', [\App\Modules\Ticket\Controllers\Api\V1\StaleTicketController::class, 'index'])
    ->name('tickets.stale.index')
    ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,tickets.read')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view')->defaults('parity_coordinator_profile', 'pseudonymized');

Route::post('tickets', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'store'])
    ->name('tickets.store')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.create', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.create'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.create')->defaults('parity_permissions', 'ticket.create')->defaults('parity_conditional_authorization', ['customer_portal_visibility=published with a client: tickets.portal.publish and ticket.update.', 'Custom Fields: current API-write switch and field edit rights.'])->defaults('parity_input_fields', ['asset_id', 'category_id', 'channel', 'client_id', 'contact_id', 'custom_fields', 'customer_portal_visibility', 'description', 'impact', 'is_scheduled', 'owner_id', 'planned_end_at', 'planned_start_at', 'priority_id', 'queue_id', 'recurrence_ends_at', 'recurrence_rule', 'schedule_type', 'site_id', 'sla_mode', 'status_id', 'subject', 'tag_names', 'task_template_group_id', 'ticket_type_id', 'timezone', 'type', 'urgency', 'workflow_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::get('tickets/{ticket}', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'show'])
    ->withTrashed()
    ->name('tickets.show')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view');

Route::match(['put', 'patch'], 'tickets/{ticket}', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'update'])
    ->name('tickets.update')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.update')->defaults('parity_permissions', 'ticket.update')->defaults('parity_conditional_authorization', ['owner change: native ticket.assign policy.', 'status change: current transition and reopen gates.', 'Custom Fields: current API-write switch and field edit rights.'])->defaults('parity_input_fields', ['asset_id', 'category_id', 'client_id', 'contact_id', 'custom_fields', 'description', 'impact', 'is_scheduled', 'owner_id', 'planned_end_at', 'planned_start_at', 'priority_id', 'queue_id', 'recurrence_ends_at', 'recurrence_rule', 'schedule_type', 'site_id', 'sla_id', 'sla_mode', 'status_id', 'subject', 'tag_names', 'task_template_group_id', 'ticket_type_id', 'timezone', 'urgency'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::get('tickets/{ticket}/messages', [\App\Modules\Ticket\Controllers\Api\V1\TicketMessageController::class, 'index'])
    ->name('tickets.messages.index')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();

Route::post('tickets/{ticket}/external-messages', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'storeExternalMessage'])
    ->name('tickets.external-messages.store')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.update')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['author_email', 'author_name', 'body', 'external_id', 'metadata', 'occurred_at', 'source', 'subject', 'type', 'visibility'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::post('tickets/{ticket}/portal-visibility', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'publishPortal'])
    ->name('tickets.portal-visibility.store')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.portal.publish', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.portal.publish')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['portal_visible'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::post('tickets/{ticket}/messages', [\App\Modules\Ticket\Controllers\Api\V1\TicketController::class, 'storeMessage'])
    ->name('tickets.messages.store')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.reply_customer', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.reply_customer'])->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.reply_customer')->defaults('parity_permissions', 'ticket.reply_customer')->defaults('parity_input_fields', ['attachments', 'body', 'cc', 'idempotency_key', 'reply_contact_id', 'reply_intent', 'type'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::post('tickets/{ticket}/timer/start', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'startTimer'])
    ->name('tickets.timer.start')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.register_time')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.register_time')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/time-entries', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'storeTimeEntry'])
    ->name('tickets.time-entries.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.register_time')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.register_time')->defaults('parity_input_fields', ['invoice_text', 'minutes', 'note', 'rate_key', 'work_date'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/cost-entries', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'storeCostEntry'])
    ->name('tickets.cost-entries.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['cost_mode', 'currency', 'invoice_text', 'item_name', 'note', 'quantity', 'storage_item_id', 'unit_price_ex_vat'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::get('tickets/{ticket}/workflow-decisions', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'decisions'])
    ->name('tickets.workflow-decisions.show')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.view')->withTrashed();
Route::post('tickets/{ticket}/workflow-transitions/{transitionKey}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'transition'])
    ->name('tickets.workflow-transitions.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.update')->defaults('parity_input_fields', ['idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/workflow-escalations/{pathKey}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'escalate'])
    ->name('tickets.workflow-escalations.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.workflow_escalate')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.workflow_escalate')->defaults('parity_input_fields', ['allow_repeat', 'owner_id', 'reason'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/close', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'close'])
    ->name('tickets.close')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.close')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.close')->defaults('parity_input_fields', ['outcome', 'reason'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/planned-lines', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'storePlannedLine'])
    ->name('tickets.planned-lines.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.plan_cost')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.plan_cost')->defaults('parity_input_fields', ['description', 'downstream_type', 'line_type', 'name', 'quantity', 'section', 'sku', 'storage_item_id', 'unit', 'unit_cost_ex_vat', 'unit_price_ex_vat', 'vat_rate'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::delete('tickets/{ticket}/planned-lines/{plannedLine}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'destroyPlannedLine'])
    ->name('tickets.planned-lines.destroy')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.plan_cost')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.plan_cost')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/planned-lines/{plannedLine}/convert', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'convertPlannedLine'])
    ->name('tickets.planned-lines.convert')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.update|storage.reserve')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.update|storage.reserve')->defaults('parity_conditional_authorization', ['Storage-backed line: native storage.reserve policy. Manual line: ticket.update.'])->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/planned-lines/{plannedLine}/purchase', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'requestPurchase'])
    ->name('tickets.planned-lines.purchase')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':storage.purchase_manage')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'storage.purchase_manage')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/sales-quote', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'createQuote'])
    ->name('tickets.sales-quote.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':sales.quote_manage')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'sales.quote_manage')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/sales-quote/send', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'sendQuote'])
    ->name('tickets.sales-quote.send')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':sales.email_send')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'sales.email_send')->defaults('parity_input_fields', ['body', 'cc', 'reply_contact_id', 'subject'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/sales-quote/versions/{version}/void', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'voidAcceptedQuote'])
    ->name('tickets.sales-quote.void')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':sales.quote_manage')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'sales.quote_manage')->defaults('parity_input_fields', ['reason'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/messages/{message}/quote-versions/{version}/accept', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'acceptQuoteFromMessage'])
    ->name('tickets.sales-quote.accept-message')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.approval_record')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.approval_record')->defaults('parity_input_fields', ['comment', 'name'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/workflow-reviews', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'requestReview'])
    ->name('tickets.workflow-reviews.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.review_request')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.review_request')->defaults('parity_input_fields', ['assigned_reviewer_id', 'comment', 'gate_key', 'separation_of_duties'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/workflow-reviews/{review}/decision', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'decideReview'])
    ->name('tickets.workflow-reviews.decide')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.review_senior')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.review_senior')->defaults('parity_input_fields', ['comment', 'decision'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('tickets/{ticket}/workflow-evidence', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowActionController::class, 'classifyEvidence'])
    ->name('tickets.workflow-evidence.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.actions')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.evidence_classify')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.actions')->defaults('parity_permissions', 'ticket.evidence_classify')->defaults('parity_input_fields', ['comment', 'evidence_type', 'evidenced_at', 'scope_key', 'source_id', 'source_type', 'subject_name'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::get('ticket-workflow-catalog', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'catalog'])
    ->name('ticket-workflows.catalog')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.read')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.read')->defaults('parity_permissions', 'ticket.manage_workflows');
Route::get('ticket-workflows', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'index'])
    ->name('ticket-workflows.index')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.manage')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.manage')->defaults('parity_permissions', 'ticket.manage_workflows');
Route::post('ticket-workflows', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'store'])
    ->name('ticket-workflows.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.manage')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.manage')->defaults('parity_permissions', 'ticket.manage_workflows')->defaults('parity_input_fields', ['definition', 'description', 'is_active', 'is_default', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::get('ticket-workflows/{workflow}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'show'])
    ->name('ticket-workflows.show')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.manage')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.manage')->defaults('parity_permissions', 'ticket.manage_workflows');
Route::put('ticket-workflows/{workflow}', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'update'])
    ->name('ticket-workflows.update')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.manage')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.manage')->defaults('parity_permissions', 'ticket.manage_workflows')->defaults('parity_input_fields', ['definition', 'description', 'is_active', 'is_default', 'name', 'slug', 'sort_order'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('ticket-workflows/{workflow}/publish', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'publish'])
    ->name('ticket-workflows.publish')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.publish')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.workflow_publish')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.publish')->defaults('parity_permissions', 'ticket.manage_workflows')->defaults('parity_additional_permissions', ['ticket.workflow_publish'])->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('ticket-workflows/{workflow}/migration-preview', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'migrationPreview'])
    ->name('ticket-workflows.migration-preview')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.manage')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.workflow_migrate')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.manage')->defaults('parity_permissions', 'ticket.manage_workflows')->defaults('parity_additional_permissions', ['ticket.workflow_migrate', 'ticket.view'])->defaults('parity_input_fields', ['state_mapping', 'target_version_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
Route::post('ticket-workflows/{workflow}/migrations', [\App\Modules\Ticket\Controllers\Api\V1\TicketWorkflowDefinitionController::class, 'migrate'])
    ->name('ticket-workflows.migrations.store')
    ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.workflow.publish')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.manage_workflows')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.workflow_migrate')->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view')->defaults('parity_domain', 'ticket')->defaults('parity_scope', 'tickets.workflow.publish')->defaults('parity_permissions', 'ticket.manage_workflows')->defaults('parity_additional_permissions', ['ticket.workflow_migrate', 'ticket.view'])->defaults('parity_input_fields', ['state_mapping', 'target_version_id', 'ticket_ids'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);


    return;
}

if (($ticketPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/tickets')
        ->name('customer-portal.tickets.')
        ->group(function (): void {
            Route::get('/', [PortalTicketController::class, 'index'])->name('index');
            Route::get('/create', [PortalTicketController::class, 'create'])->name('create');
            Route::post('/', [PortalTicketController::class, 'store'])->name('store');
            Route::get('/{ticket}', [PortalTicketController::class, 'show'])->name('show');
            Route::post('/{ticket}/messages', [PortalTicketController::class, 'reply'])->name('messages.store');
            Route::get('/{ticket}/attachments/{attachment}/download', [PortalTicketController::class, 'downloadAttachment'])->name('attachments.download');
        });

    return;
}

Route::get('/reports/tickets/sla', [TicketSlaReportController::class, 'index'])
    ->name('reports.tickets.sla');

Route::get('/tickets', [TicketController::class, 'index'])
    ->name('tickets.index');

Route::get('/tickets/create', [TicketController::class, 'create'])
    ->name('tickets.create');

Route::get('/tickets/create/context', [TicketController::class, 'createContext'])
    ->name('tickets.create.context');

Route::get('/tickets/profile', [TicketAssignmentSettingsController::class, 'edit'])
    ->name('tickets.profile.edit');

Route::patch('/tickets/profile', [TicketAssignmentSettingsController::class, 'update'])
    ->name('tickets.profile.update');

Route::post('/tickets', [TicketController::class, 'store'])
    ->name('tickets.store');

Route::post('/tickets/merge', [TicketController::class, 'mergeSelected'])
    ->name('tickets.merge');

Route::post('/tickets/merge-suggestions/dismiss', [TicketController::class, 'dismissMergeSuggestion'])
    ->name('tickets.merge-suggestions.dismiss');

Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])
    ->name('tickets.edit');

Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
    ->withTrashed()
    ->name('tickets.show');

Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])
    ->name('tickets.update');

Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])
    ->name('tickets.close');

Route::post('/tickets/{ticket}/workflow/{transition}', [TicketController::class, 'transition'])
    ->name('tickets.workflow.transition');

Route::post('/tickets/{ticket}/workflow-v3/transitions/{transitionKey}', [TicketWorkflowController::class, 'transition'])
    ->name('tickets.workflow-v3.transition');
Route::post('/tickets/{ticket}/timer/start', [TicketWorkflowController::class, 'startTimer'])
    ->name('tickets.timer.start');

Route::post('/tickets/{ticket}/workflow-v3/escalations/{pathKey}', [TicketWorkflowController::class, 'escalate'])
    ->name('tickets.workflow-v3.escalate');
Route::post('/tickets/{ticket}/planned-lines', [TicketWorkflowController::class, 'storePlannedLine'])
    ->name('tickets.planned-lines.store');
Route::delete('/tickets/{ticket}/planned-lines/{plannedLine}', [TicketWorkflowController::class, 'destroyPlannedLine'])
    ->name('tickets.planned-lines.destroy');
Route::post('/tickets/{ticket}/planned-lines/{plannedLine}/convert', [TicketWorkflowController::class, 'convertPlannedLine'])
    ->name('tickets.planned-lines.convert');
Route::post('/tickets/{ticket}/planned-lines/{plannedLine}/purchase', [TicketWorkflowController::class, 'requestPurchase'])
    ->name('tickets.planned-lines.purchase');
Route::post('/tickets/{ticket}/sales-quote', [TicketWorkflowController::class, 'createQuote'])
    ->name('tickets.sales-quote.create');
Route::post('/tickets/{ticket}/sales-quote/send', [TicketWorkflowController::class, 'sendQuote'])
    ->name('tickets.sales-quote.send');
Route::post('/tickets/{ticket}/sales-quote/versions/{version}/void', [TicketWorkflowController::class, 'voidAcceptedQuote'])
    ->name('tickets.sales-quote.void');
Route::post('/tickets/{ticket}/messages/{message}/quote-versions/{version}/accept', [TicketWorkflowController::class, 'acceptQuoteFromMessage'])
    ->name('tickets.sales-quote.accept-message');
Route::post('/tickets/{ticket}/workflow-reviews', [TicketWorkflowController::class, 'requestReview'])
    ->name('tickets.workflow-reviews.store');
Route::post('/tickets/{ticket}/workflow-reviews/{review}/decision', [TicketWorkflowController::class, 'decideReview'])
    ->name('tickets.workflow-reviews.decide');
Route::post('/tickets/{ticket}/workflow-evidence', [TicketWorkflowController::class, 'classifyEvidence'])
    ->name('tickets.workflow-evidence.store');

Route::post('/tickets/{ticket}/documentation-request', [TicketController::class, 'requestDocumentation'])
    ->name('tickets.documentation-request');

Route::post('/tickets/{ticket}/messages', [TicketController::class, 'addMessage'])
    ->name('tickets.messages.store');

Route::post('/tickets/{ticket}/time-entries', [TicketController::class, 'storeTimeEntry'])
    ->name('tickets.time-entries.store');

Route::post('/tickets/{ticket}/time-entries/draft', [TicketController::class, 'draftTimeEntryInvoiceText'])
    ->name('tickets.time-entries.draft');

Route::patch('/tickets/{ticket}/time-entries/{timeEntry}', [TicketController::class, 'updateTimeEntry'])
    ->name('tickets.time-entries.update');

Route::post('/tickets/{ticket}/cost-entries', [TicketController::class, 'storeCostEntry'])
    ->name('tickets.cost-entries.store');

Route::patch('/tickets/{ticket}/cost-entries/{costEntry}', [TicketController::class, 'updateCostEntry'])
    ->name('tickets.cost-entries.update');

Route::delete('/tickets/{ticket}/cost-entries/{costEntry}', [TicketController::class, 'destroyCostEntry'])
    ->name('tickets.cost-entries.destroy');

Route::post('/tickets/{ticket}/cost-entries/{costEntry}/pick', [TicketController::class, 'pickCostEntry'])
    ->name('tickets.cost-entries.pick');

Route::post('/tickets/{ticket}/messages/{message}/read', [TicketController::class, 'markMessageRead'])
    ->name('tickets.messages.read');

Route::post('/tickets/{ticket}/messages/{message}/solution', [TicketController::class, 'markMessageSolution'])
    ->name('tickets.messages.solution');

Route::get('/tickets/{ticket}/attachments/{attachment}/download', [TicketController::class, 'downloadAttachment'])
    ->name('tickets.attachments.download');

Route::post('/tickets/{ticket}/read', [TicketController::class, 'markRead'])
    ->name('tickets.read');

Route::post('/tickets/{ticket}/portal-visibility', [TicketController::class, 'updatePortalVisibility'])
    ->name('tickets.portal-visibility.update');

Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
    ->name('tickets.assign');

Route::post('/tickets/{ticket}/email-relationships/{relationship}/reply', [TicketController::class, 'prepareEmailReply'])
    ->name('tickets.email-relationships.reply');

Route::post('/tickets/{ticket}/not-ticket', [TicketController::class, 'markNotTicket'])
    ->name('tickets.not-ticket');

Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
    ->name('tickets.destroy');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/tickets', [TicketSettingsController::class, 'index'])
        ->name('admin.settings.tickets');
    Route::post('/admin/settings/tickets/default-email-account', [TicketSettingsController::class, 'updateDefaultEmailAccount'])
        ->name('admin.settings.tickets.default-email-account.update');
    Route::post('/admin/settings/tickets/solution-policy', [TicketSettingsController::class, 'updateSolutionPolicy'])
        ->name('admin.settings.tickets.solution-policy.update');
    Route::post('/admin/settings/tickets/portal-policy', [TicketSettingsController::class, 'updatePortalPolicy'])
        ->name('admin.settings.tickets.portal-policy.update');
    Route::post('/admin/settings/tickets/quote-cost-policy', [TicketSettingsController::class, 'updateQuoteCostPolicy'])
        ->name('admin.settings.tickets.quote-cost-policy.update');
    Route::post('/admin/settings/tickets/merge-settings', [TicketSettingsController::class, 'updateMergeSettings'])
        ->name('admin.settings.tickets.merge-settings.update');
    Route::post('/admin/settings/tickets/queues', [TicketSettingsController::class, 'storeQueue'])
        ->name('admin.settings.tickets.queues.store');
    Route::put('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'updateQueue'])
        ->name('admin.settings.tickets.queues.update');
    Route::delete('/admin/settings/tickets/queues/{queue}', [TicketSettingsController::class, 'destroyQueue'])
        ->name('admin.settings.tickets.queues.destroy');
    Route::post('/admin/settings/tickets/types', [TicketSettingsController::class, 'storeType'])
        ->name('admin.settings.tickets.types.store');
    Route::put('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'updateType'])
        ->name('admin.settings.tickets.types.update');
    Route::delete('/admin/settings/tickets/types/{type}', [TicketSettingsController::class, 'destroyType'])
        ->name('admin.settings.tickets.types.destroy');

    // Ticket lifecycle and urgency records are managed inside the Ticket module settings surface.
    Route::post('/admin/settings/tickets/statuses', [TicketSettingsController::class, 'storeStatus'])
        ->name('admin.settings.tickets.statuses.store');
    Route::put('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'updateStatus'])
        ->name('admin.settings.tickets.statuses.update');
    Route::delete('/admin/settings/tickets/statuses/{status}', [TicketSettingsController::class, 'destroyStatus'])
        ->name('admin.settings.tickets.statuses.destroy');
    Route::post('/admin/settings/tickets/priorities', [TicketSettingsController::class, 'storePriority'])
        ->name('admin.settings.tickets.priorities.store');
    Route::put('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'updatePriority'])
        ->name('admin.settings.tickets.priorities.update');
    Route::delete('/admin/settings/tickets/priorities/{priority}', [TicketSettingsController::class, 'destroyPriority'])
        ->name('admin.settings.tickets.priorities.destroy');

    Route::get('/admin/settings/tickets/technicians', [TicketAssignmentSettingsAdminController::class, 'index'])
        ->name('admin.settings.tickets.technicians');
    Route::post('/admin/settings/tickets/technicians', [TicketAssignmentSettingsAdminController::class, 'store'])
        ->name('admin.settings.tickets.technicians.store');
    Route::get('/admin/settings/tickets/technicians/{profile}/edit', [TicketAssignmentSettingsAdminController::class, 'edit'])
        ->name('admin.settings.tickets.technicians.edit');
    Route::patch('/admin/settings/tickets/technicians/{profile}', [TicketAssignmentSettingsAdminController::class, 'update'])
        ->name('admin.settings.tickets.technicians.update');

    Route::get('/admin/settings/tickets/assignment-rules', [AssignmentRuleAdminController::class, 'index'])
        ->name('admin.settings.tickets.assignment-rules');
    Route::post('/admin/settings/tickets/assignment-rules', [AssignmentRuleAdminController::class, 'store'])
        ->name('admin.settings.tickets.assignment-rules.store');
    Route::delete('/admin/settings/tickets/assignment-rules/{rule}', [AssignmentRuleAdminController::class, 'destroy'])
        ->name('admin.settings.tickets.assignment-rules.destroy');

    Route::get('/admin/settings/tickets/rules', [TicketSettingsController::class, 'rules'])
        ->name('admin.settings.tickets.rules');
    Route::get('/admin/settings/tickets/rules/executions', [TicketRuleExecutionController::class, 'index'])
        ->name('admin.settings.tickets.rules.executions.index');
    Route::get('/admin/settings/tickets/rules/executions/{run}', [TicketRuleExecutionController::class, 'show'])
        ->name('admin.settings.tickets.rules.executions.show');
    Route::post('/admin/settings/tickets/rules/executions/{run}/actions/{actionResult}/retry', [TicketRuleExecutionController::class, 'retryAction'])
        ->name('admin.settings.tickets.rules.executions.actions.retry');
    Route::post('/admin/settings/tickets/rules/executions/{run}/full-rerun/preview', [TicketRuleExecutionController::class, 'previewFullRerun'])
        ->name('admin.settings.tickets.rules.executions.rerun.preview');
    Route::post('/admin/settings/tickets/rules/executions/{run}/full-rerun', [TicketRuleExecutionController::class, 'fullRerun'])
        ->name('admin.settings.tickets.rules.executions.rerun.store');
    Route::get('/admin/settings/tickets/rules/create', [TicketSettingsController::class, 'createRule'])
        ->name('admin.settings.tickets.rules.create');
    Route::post('/admin/settings/tickets/rules', [TicketSettingsController::class, 'storeRule'])
        ->name('admin.settings.tickets.rules.store');
    Route::get('/admin/settings/tickets/rules/{rule}', [TicketRuleReadController::class, 'show'])
        ->withTrashed()
        ->name('admin.settings.tickets.rules.show');
    Route::get('/admin/settings/tickets/rules/{rule}/edit', [TicketSettingsController::class, 'editRule'])
        ->name('admin.settings.tickets.rules.edit');
    Route::put('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'updateRule'])
        ->name('admin.settings.tickets.rules.update');
    Route::post('/admin/settings/tickets/rules/{rule}/toggle', [TicketSettingsController::class, 'toggleRule'])
        ->name('admin.settings.tickets.rules.toggle');
    Route::delete('/admin/settings/tickets/rules/{rule}', [TicketSettingsController::class, 'destroyRule'])
        ->name('admin.settings.tickets.rules.destroy');

    Route::get('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'workflows'])
        ->name('admin.settings.tickets.workflows');
    Route::get('/admin/settings/tickets/workflows/create', [TicketSettingsController::class, 'createWorkflow'])
        ->name('admin.settings.tickets.workflows.create');
    Route::post('/admin/settings/tickets/workflows', [TicketSettingsController::class, 'storeWorkflow'])
        ->name('admin.settings.tickets.workflows.store');
    Route::get('/admin/settings/tickets/workflows/{workflow}/edit', [TicketSettingsController::class, 'editWorkflow'])
        ->name('admin.settings.tickets.workflows.edit');
    Route::put('/admin/settings/tickets/workflows/{workflow}', [TicketSettingsController::class, 'updateWorkflow'])
        ->name('admin.settings.tickets.workflows.update');
    Route::post('/admin/settings/tickets/workflows/{workflow}/publish', [TicketSettingsController::class, 'publishWorkflow'])
        ->name('admin.settings.tickets.workflows.publish');
    Route::post('/admin/settings/tickets/workflows/{workflow}/migrate-tickets', [TicketSettingsController::class, 'migrateWorkflowTickets'])
        ->name('admin.settings.tickets.workflows.migrate-tickets');
});
