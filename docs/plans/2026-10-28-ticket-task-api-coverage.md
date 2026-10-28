# Ticket/Task browser-to-API coverage

Current-Main candidate audit, 2026-10-28. See the linked JSON matrix for every HTTP alias, scope, current actor permission, success schema, relevant test file and independent read-back route.

130 browser rows, 63 Livewire public methods, 215 API route rows / 217 HTTP operations. HEAD aliases reuse the GET contract. Read means a GET or rendering/lookup; write means CRUD or an explicit state setter; action means a domain command, generation, preview or external effect. Transient Livewire form changes use the same structured payload when saved through the listed APIs.

Task settings HTML initializes defaults on first open; the API exposes that effect explicitly through task-settings.initialize. Form create/edit pages use context, catalogs and record reads. The Task Docs link returns actual Markdown through tasks/docs. Named human operational checks remain Pending; these mappings do not assert provider delivery or production availability.

[Machine-readable evidence](2026-10-20-ticket-task-api-gap-matrix.json).

## Browser routes

| Browser route | Kind | API counterparts |
|---|---|---|
| customer-portal.tickets.index | read | portal.tickets.index |
| customer-portal.tickets.create | read | portal.tickets.context, portal.tickets.sites |
| customer-portal.tickets.store | write | portal.tickets.store |
| customer-portal.tickets.show | read | portal.tickets.show, portal.tickets.messages.index, portal.tickets.attachments.index |
| customer-portal.tickets.messages.store | action | portal.tickets.messages.store |
| customer-portal.tickets.attachments.download | read | portal.tickets.attachments.download |
| tech.admin.settings.tasks | read | task-settings.show, task-settings.context, task-settings.initialize |
| tech.admin.settings.tasks.update | write | task-settings.update |
| tech.admin.task-templates.index | read | task-templates.index |
| tech.admin.task-templates.create | read | task-templates.context, task-templates.targets |
| tech.admin.task-templates.store | write | task-templates.store |
| tech.admin.task-templates.show | read | task-templates.show |
| tech.admin.task-templates.edit | read | task-templates.show, task-templates.items.index, task-templates.context, task-schedules.index |
| tech.admin.task-templates.update | write | task-templates.update |
| tech.admin.task-templates.destroy | write | task-templates.destroy |
| tech.admin.task-templates.items.store | write | task-templates.items.store |
| tech.admin.task-templates.items.update | write | task-templates.items.update |
| tech.admin.task-templates.items.destroy | write | task-templates.items.destroy |
| tech.admin.task-templates.schedules.store | write | task-schedules.store |
| tech.admin.task-templates.schedules.update | write | task-schedules.update |
| tech.admin.task-templates.schedules.toggle | write | task-schedules.active |
| tech.admin.task-templates.schedules.destroy | write | task-schedules.destroy |
| tech.admin.task-templates.schedules.run | action | task-schedules.run |
| tech.task-templates.choose | read | task-templates.index, task-templates.targets, task-templates.context |
| tech.task-templates.preview | read | task-templates.preview |
| tech.task-templates.apply | action | task-templates.apply |
| tech.tasks.index | read | tasks.index |
| tech.tasks.create | read | tasks.context, tasks.context.options |
| tech.tasks.ai-suggest | action | tasks.ai-suggestions.store |
| tech.tasks.store | write | tasks.store |
| tech.tasks.docs | read | tasks.docs |
| tech.tasks.edit | read | tasks.show, tasks.context.show, tasks.context.options |
| tech.tasks.show | read | tasks.show, tasks.activities.index, tasks.time-entries.index, tasks.children.index, tasks.dependencies.index |
| tech.tasks.update | write | tasks.update |
| tech.tasks.status.update | write | tasks.status.update |
| tech.tasks.assign | write | tasks.assignee.update |
| tech.tasks.time-entries.store | write | tasks.time-entries.store |
| tech.tasks.complete | action | tasks.complete |
| tech.tasks.checklist.toggle | write | tasks.checklist.update |
| tech.reports.tickets.sla | read | tickets.reports.sla |
| tech.tickets.index | read | tickets.index |
| tech.tickets.create | read | tickets.context, tickets.context.options |
| tech.tickets.create.context | read | tickets.context, tickets.context.options |
| tech.tickets.profile.edit | read | ticket-assignment.own, ticket-assignment.own.context |
| tech.tickets.profile.update | write | ticket-assignment.own.update |
| tech.tickets.store | write | tickets.store |
| tech.tickets.merge | action | tickets.merge.store |
| tech.tickets.merge-suggestions.dismiss | action | tickets.merge-suggestions.dismiss |
| tech.tickets.edit | read | tickets.show, tickets.context.show, tickets.context.options, tickets.schedule.show |
| tech.tickets.show | read | tickets.show, tickets.conversation.index, tickets.attachments.index, tickets.activities.index, tickets.history.index, tickets.time-entries.index, tickets.cost-entries.index, tickets.planned-lines.index, tickets.tasks.index, tickets.schedule.show, tickets.workflow-decisions.show, tickets.workflow-reviews.index, tickets.workflow-evidence.index, tickets.quote-versions.index, tickets.email-relationships.index, tickets.relationship-links.index, tickets.documentation-requests.index |
| tech.tickets.update | write | tickets.update |
| tech.tickets.close | action | tickets.close |
| tech.tickets.workflow.transition | action | tickets.workflow-decisions.show, tickets.workflow-transitions.store |
| tech.tickets.workflow-v3.transition | action | tickets.workflow-decisions.show, tickets.workflow-transitions.store |
| tech.tickets.timer.start | action | tickets.timer.start |
| tech.tickets.workflow-v3.escalate | action | tickets.workflow-escalations.store |
| tech.tickets.planned-lines.store | write | tickets.planned-lines.store |
| tech.tickets.planned-lines.destroy | write | tickets.planned-lines.destroy |
| tech.tickets.planned-lines.convert | action | tickets.planned-lines.convert |
| tech.tickets.planned-lines.purchase | action | tickets.planned-lines.purchase |
| tech.tickets.sales-quote.create | action | tickets.sales-quote.store |
| tech.tickets.sales-quote.send | action | tickets.sales-quote.send |
| tech.tickets.sales-quote.void | action | tickets.sales-quote.void |
| tech.tickets.sales-quote.accept-message | action | tickets.sales-quote.accept-message |
| tech.tickets.workflow-reviews.store | action | tickets.workflow-reviews.store |
| tech.tickets.workflow-reviews.decide | action | tickets.workflow-reviews.decide |
| tech.tickets.workflow-evidence.store | action | tickets.workflow-evidence.store |
| tech.tickets.documentation-request | action | tickets.documentation-requests.store |
| tech.tickets.messages.store | action | tickets.messages.store, tickets.internal-notes.store |
| tech.tickets.time-entries.store | write | tickets.time-entries.store |
| tech.tickets.time-entries.draft | action | tickets.time-entries.invoice-text-suggestion |
| tech.tickets.time-entries.update | write | tickets.time-entries.update |
| tech.tickets.cost-entries.store | write | tickets.cost-entries.store |
| tech.tickets.cost-entries.update | write | tickets.cost-entries.update |
| tech.tickets.cost-entries.destroy | write | tickets.cost-entries.release |
| tech.tickets.cost-entries.pick | action | tickets.cost-entries.pick-preview, tickets.cost-entries.pick |
| tech.tickets.messages.read | action | tickets.messages.read-state.store |
| tech.tickets.messages.solution | action | tickets.messages.solution |
| tech.tickets.attachments.download | read | tickets.attachments.download |
| tech.tickets.read | action | tickets.read-state.store |
| tech.tickets.portal-visibility.update | write | tickets.portal-visibility.store |
| tech.tickets.assign | action | tickets.assignment.preview, tickets.assignment.store |
| tech.tickets.email-relationships.reply | action | tickets.email-relationships.reply-draft, tickets.email-communications.show |
| tech.tickets.not-ticket | action | tickets.not-ticket |
| tech.tickets.destroy | write | tickets.lifecycle-state.show, tickets.destroy |
| tech.admin.settings.tickets | read | ticket-settings.show, ticket-settings.context, ticket-queues.index, ticket-types.index, ticket-statuses.index, ticket-priorities.index |
| tech.admin.settings.tickets.default-email-account.update | write | ticket-settings.email |
| tech.admin.settings.tickets.solution-policy.update | write | ticket-settings.solution |
| tech.admin.settings.tickets.portal-policy.update | write | ticket-settings.portal |
| tech.admin.settings.tickets.quote-cost-policy.update | write | ticket-settings.quote |
| tech.admin.settings.tickets.merge-settings.update | write | ticket-settings.merge |
| tech.admin.settings.tickets.queues.store | write | ticket-queues.store |
| tech.admin.settings.tickets.queues.update | write | ticket-queues.update |
| tech.admin.settings.tickets.queues.destroy | write | ticket-queues.destroy |
| tech.admin.settings.tickets.types.store | write | ticket-types.store |
| tech.admin.settings.tickets.types.update | write | ticket-types.update |
| tech.admin.settings.tickets.types.destroy | write | ticket-types.destroy |
| tech.admin.settings.tickets.statuses.store | write | ticket-statuses.store |
| tech.admin.settings.tickets.statuses.update | write | ticket-statuses.update |
| tech.admin.settings.tickets.statuses.destroy | write | ticket-statuses.destroy |
| tech.admin.settings.tickets.priorities.store | write | ticket-priorities.store |
| tech.admin.settings.tickets.priorities.update | write | ticket-priorities.update |
| tech.admin.settings.tickets.priorities.destroy | write | ticket-priorities.destroy |
| tech.admin.settings.tickets.technicians | read | ticket-assignment.profiles.index |
| tech.admin.settings.tickets.technicians.store | write | ticket-assignment.profiles.store |
| tech.admin.settings.tickets.technicians.edit | read | ticket-assignment.profiles.show |
| tech.admin.settings.tickets.technicians.update | write | ticket-assignment.profiles.update |
| tech.admin.settings.tickets.assignment-rules | read | ticket-assignment.rules.index |
| tech.admin.settings.tickets.assignment-rules.store | write | ticket-assignment.rules.store |
| tech.admin.settings.tickets.assignment-rules.destroy | write | ticket-assignment.rules.destroy |
| tech.admin.settings.tickets.rules | read | ticket-rules.index, ticket-rules.readiness |
| tech.admin.settings.tickets.rules.executions.index | read | ticket-rule-executions.index |
| tech.admin.settings.tickets.rules.executions.show | read | ticket-rule-executions.show |
| tech.admin.settings.tickets.rules.executions.actions.retry | action | ticket-rule-executions.retry |
| tech.admin.settings.tickets.rules.executions.rerun.preview | action | ticket-rule-executions.rerun.preview |
| tech.admin.settings.tickets.rules.executions.rerun.store | action | ticket-rule-executions.rerun.store |
| tech.admin.settings.tickets.rules.create | read | ticket-rules.context, ticket-rules.readiness |
| tech.admin.settings.tickets.rules.store | write | ticket-rules.legacy.store, ticket-rules.draft.store |
| tech.admin.settings.tickets.rules.show | read | ticket-rules.show, ticket-rules.versions.index |
| tech.admin.settings.tickets.rules.edit | read | ticket-rules.show, ticket-rules.draft.show, ticket-rules.context |
| tech.admin.settings.tickets.rules.update | write | ticket-rules.legacy.update, ticket-rules.draft.update |
| tech.admin.settings.tickets.rules.toggle | action | ticket-rules.enabled |
| tech.admin.settings.tickets.rules.destroy | write | ticket-rules.destroy |
| tech.admin.settings.tickets.workflows | read | ticket-workflows.index |
| tech.admin.settings.tickets.workflows.create | read | ticket-workflows.catalog |
| tech.admin.settings.tickets.workflows.store | write | ticket-workflows.store |
| tech.admin.settings.tickets.workflows.edit | read | ticket-workflows.show, ticket-workflows.catalog |
| tech.admin.settings.tickets.workflows.update | write | ticket-workflows.update |
| tech.admin.settings.tickets.workflows.publish | action | ticket-workflows.publish |
| tech.admin.settings.tickets.workflows.migrate-tickets | action | ticket-workflows.migration-preview, ticket-workflows.migrations.store |

## Livewire actions

| Component and method | Kind / effect | API counterparts |
|---|---|---|
| RuleBuilder::mount | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.readiness, ticket-rules.context, ticket-rules.show, ticket-rules.draft.show |
| RuleBuilder::hydrate | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.readiness, ticket-rules.context, ticket-rules.show, ticket-rules.draft.show |
| RuleBuilder::addGroup | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::removeGroup | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::moveGroup | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::addCondition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::removeCondition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::moveCondition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::addAction | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::removeAction | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::moveAction | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::setActionType | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::setTrigger | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::updated | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::saveDraft | write; persisted draft | ticket-rules.draft.store, ticket-rules.draft.update |
| RuleBuilder::preview | action; non-persisting domain preview | ticket-rules.preview |
| RuleBuilder::publish | action; save draft and publish immutable version | ticket-rules.draft.store, ticket-rules.draft.update, ticket-rules.publish |
| RuleBuilder::publicationReady | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.readiness, ticket-rules.context, ticket-rules.show, ticket-rules.draft.show |
| RuleBuilder::publicationBlockedReasons | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.readiness, ticket-rules.context, ticket-rules.show, ticket-rules.draft.show |
| RuleBuilder::render | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-rules.readiness, ticket-rules.context, ticket-rules.show, ticket-rules.draft.show |
| WorkflowEditor::mount | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addState | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addStateAfter | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::openState | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeState | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::setInitial | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addAction | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeAction | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addTransition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeTransition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addTransitionTrigger | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeTransitionTrigger | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addEscalation | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeEscalation | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addRequirementGroup | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeRequirementGroup | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::addRequirementCondition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::removeRequirementCondition | write; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| WorkflowEditor::render | read; transient form state or rendering; persisted outcome uses the listed typed form API | ticket-workflows.catalog, ticket-workflows.show, ticket-workflows.store, ticket-workflows.update |
| TaskChecklistEditor::mount | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskChecklistEditor::addItem | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskChecklistEditor::removeItem | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskChecklistEditor::checklistText | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskChecklistEditor::applyAiChecklist | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskChecklistEditor::render | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.store, tasks.update, tasks.checklist.update |
| TaskFormContext::mount | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::updatedClientSearch | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::updatedSiteSearch | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::updatedTicketSearch | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::openClientPicker | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::openSitePicker | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::openTicketPicker | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::selectClient | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::selectSite | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::selectTicket | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::clearTicket | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::applyAiContext | write; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::clientResults | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::siteResults | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::ticketResults | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::parentOptions | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::ticketRateOptions | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |
| TaskFormContext::render | read; transient form state or rendering; persisted outcome uses the listed typed form API | tasks.context, tasks.context.options, tasks.store, tasks.update |

## API operations

| HTTP operation | Kind | Token scopes | Relevant tests | Read-back routes |
|---|---|---|---|---|
| GET /api/v1/ticket-settings/documentation-requests | read | tickets.settings.read | TicketFollowUpApiTest | ticket-settings.documentation-requests.index |
| GET /api/v1/tickets/{ticket}/workflow-reviews | read | tickets.workflow.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.workflow-reviews.index |
| GET /api/v1/tickets/{ticket}/workflow-reviews/{review} | read | tickets.workflow.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.workflow-reviews.show |
| GET /api/v1/tickets/{ticket}/workflow-evidence | read | tickets.workflow.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.workflow-evidence.index |
| GET /api/v1/tickets/{ticket}/workflow-evidence/{evidence} | read | tickets.workflow.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.workflow-evidence.show |
| GET /api/v1/tickets/{ticket}/quote-versions | read | tickets.quotes.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.quote-versions.index |
| GET /api/v1/tickets/{ticket}/quote-versions/{version} | read | tickets.quotes.read | TicketApiParityBoundaryTest, TicketReadbackApiTest | tickets.quote-versions.show |
| GET /api/v1/tickets/{ticket}/history | read | tickets.activities.read | TicketReadbackApiTest | tickets.history.index |
| GET /api/v1/tickets/{ticket}/history/{event} | read | tickets.activities.read | TicketReadbackApiTest | tickets.history.show |
| GET /api/v1/tickets/{ticket}/relationship-targets | read | tickets.relationships.read | TicketRelationshipApiTest | tickets.relationship-targets.index |
| POST /api/v1/tickets/{ticket}/relationships/escalate | action | tickets.relationships.escalate | TicketRelationshipApiTest | tickets.relationship-escalations.show, tickets.relationship-links.index |
| GET /api/v1/tickets/{ticket}/relationship-escalations | read | tickets.relationships.read | TicketRelationshipApiTest | tickets.relationship-escalations.index |
| GET /api/v1/tickets/{ticket}/relationship-escalations/{escalation} | read | tickets.relationships.read | TicketRelationshipApiTest | tickets.relationship-escalations.show |
| GET /api/v1/tickets/{ticket}/relationship-links | read | tickets.relationships.read | TicketRelationshipApiTest | tickets.relationship-links.index |
| GET /api/v1/tickets/{ticket}/email-relationships | read | tickets.email-drafts.read, email.read | TicketEmailDraftApiTest | tickets.email-relationships.index |
| POST /api/v1/tickets/{ticket}/email-relationships/{relationship}/reply-draft | action | tickets.email-drafts.create, email.drafts.write | TicketEmailDraftApiTest | tickets.email-communications.show |
| GET /api/v1/tickets/{ticket}/email-communications/{communication} | read | tickets.email-drafts.read, email.drafts.read | TicketEmailDraftApiTest | tickets.email-communications.show |
| GET /api/v1/tickets/{ticket}/documentation-requests | read | tickets.documentation.read | TicketFollowUpApiTest | tickets.documentation-requests.index |
| GET /api/v1/tickets/{ticket}/documentation-requests/{documentationRequest} | read | tickets.documentation.read | TicketFollowUpApiTest | tickets.documentation-requests.show |
| POST /api/v1/tickets/{ticket}/documentation-requests | write | tickets.documentation.request | TicketFollowUpApiTest | tickets.documentation-requests.show |
| GET /api/v1/tickets/reports/sla | read | tickets.reports.read | TicketFollowUpApiTest | tickets.reports.sla |
| GET /api/v1/portal/tickets/context | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.context |
| GET /api/v1/portal/tickets/sites | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.sites |
| GET /api/v1/portal/tickets | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.index |
| GET /api/v1/portal/tickets/{ticket} | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.show |
| POST /api/v1/portal/tickets | write | portal.tickets.create | PortalTicketApiTest |  |
| GET /api/v1/portal/tickets/{ticket}/messages | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.messages.index |
| GET /api/v1/portal/tickets/{ticket}/messages/{message} | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.messages.show |
| POST /api/v1/portal/tickets/{ticket}/messages | action | portal.tickets.reply | PortalTicketApiTest |  |
| GET /api/v1/portal/tickets/{ticket}/attachments | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.attachments.index |
| GET /api/v1/portal/tickets/{ticket}/attachments/{attachment} | read | portal.tickets.read | PortalTicketApiTest | portal.tickets.attachments.show |
| GET /api/v1/portal/tickets/{ticket}/attachments/{attachment}/download | read | portal.tickets.attachments.download | PortalTicketApiTest | portal.tickets.attachments.download |
| GET /api/v1/tickets/merge-suggestions | read | tickets.read | TicketLifecycleApiTest | tickets.merge-suggestions.index |
| POST /api/v1/tickets/merge | action | tickets.merge | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.history.index |
| POST /api/v1/tickets/merge-suggestions/dismiss | action | tickets.merge | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.history.index |
| GET /api/v1/ticket-rule-executions | read | tickets.rules.executions.read | TicketRuleApiTest, TicketRuleRetryAndFullRerunTest | ticket-rule-executions.index |
| GET /api/v1/ticket-rule-executions/{run} | read | tickets.rules.executions.read | TicketRuleApiTest, TicketRuleRetryAndFullRerunTest | ticket-rule-executions.show |
| POST /api/v1/ticket-rule-executions/{run}/actions/{actionResult}/retry | action | tickets.rules.retry | TicketRuleApiTest, TicketRuleRetryAndFullRerunTest | ticket-rule-executions.show |
| POST /api/v1/ticket-rule-executions/{run}/full-rerun/preview | action | tickets.rules.full-rerun | TicketRuleApiTest, TicketRuleRetryAndFullRerunTest | ticket-rule-executions.show |
| POST /api/v1/ticket-rule-executions/{run}/full-rerun | action | tickets.rules.full-rerun | TicketRuleApiTest, TicketRuleRetryAndFullRerunTest | ticket-rule-executions.show |
| GET /api/v1/ticket-rules/readiness | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.readiness |
| GET /api/v1/ticket-rules/context/{catalog} | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.context |
| GET /api/v1/ticket-rules | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.index |
| GET /api/v1/ticket-rules/{rule} | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show |
| GET /api/v1/ticket-rules/{rule}/draft | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.draft.show |
| POST /api/v1/ticket-rules/drafts | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| PUT /api/v1/ticket-rules/{rule}/draft | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| POST /api/v1/ticket-rules/preview | action | tickets.rules.preview | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| GET /api/v1/ticket-rules/{rule}/versions | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.versions.index |
| GET /api/v1/ticket-rules/{rule}/versions/{version} | read | tickets.rules.read | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.versions.show |
| POST /api/v1/ticket-rules/{rule}/publish | action | tickets.rules.publish | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| POST /api/v1/ticket-rules/legacy | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| PUT /api/v1/ticket-rules/{rule}/legacy | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| PUT /api/v1/ticket-rules/{rule}/enabled | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| DELETE /api/v1/ticket-rules/{rule} | write | tickets.rules.manage | TicketRuleApiTest, TicketRuleCatalogMutationTest | ticket-rules.show, ticket-rules.draft.show, ticket-rules.versions.show |
| GET /api/v1/me/ticket-assignment | read | tickets.profile.read | TicketAdministrationApiTest | ticket-assignment.own |
| PUT /api/v1/me/ticket-assignment | write | tickets.profile.update | TicketAdministrationApiTest | ticket-assignment.own, ticket-assignment.own.history, ticket-assignment.profiles.show, ticket-assignment.rules.show |
| GET /api/v1/me/ticket-assignment/context/{catalog} | read | tickets.profile.read | TicketAdministrationApiTest | ticket-assignment.own.context |
| GET /api/v1/me/ticket-assignment/history | read | tickets.profile.read | TicketAdministrationApiTest | ticket-assignment.own.history |
| GET /api/v1/ticket-assignment-profiles | read | tickets.assignment.read | TicketAdministrationApiTest | ticket-assignment.profiles.index |
| GET /api/v1/ticket-assignment-profiles/{profile} | read | tickets.assignment.read | TicketAdministrationApiTest | ticket-assignment.profiles.show |
| POST /api/v1/ticket-assignment-profiles | write | tickets.assignment.manage | TicketAdministrationApiTest | ticket-assignment.own, ticket-assignment.own.history, ticket-assignment.profiles.show, ticket-assignment.rules.show |
| PUT /api/v1/ticket-assignment-profiles/{profile} | write | tickets.assignment.manage | TicketAdministrationApiTest | ticket-assignment.own, ticket-assignment.own.history, ticket-assignment.profiles.show, ticket-assignment.rules.show |
| GET /api/v1/ticket-assignment-rules | read | tickets.assignment.read | TicketAdministrationApiTest | ticket-assignment.rules.index |
| GET /api/v1/ticket-assignment-rules/{rule} | read | tickets.assignment.read | TicketAdministrationApiTest | ticket-assignment.rules.show |
| POST /api/v1/ticket-assignment-rules | write | tickets.assignment.manage | TicketAdministrationApiTest | ticket-assignment.own, ticket-assignment.own.history, ticket-assignment.profiles.show, ticket-assignment.rules.show |
| DELETE /api/v1/ticket-assignment-rules/{rule} | write | tickets.assignment.manage | TicketAdministrationApiTest | ticket-assignment.own, ticket-assignment.own.history, ticket-assignment.profiles.show, ticket-assignment.rules.show |
| GET /api/v1/ticket-settings | read | tickets.settings.read | TicketAdministrationApiTest | ticket-settings.show |
| PUT /api/v1/ticket-settings/default-email-account | write | tickets.settings.update | TicketAdministrationApiTest | ticket-settings.show, ticket-settings.history |
| PUT /api/v1/ticket-settings/solution-policy | write | tickets.settings.update | TicketAdministrationApiTest | ticket-settings.show, ticket-settings.history |
| PUT /api/v1/ticket-settings/portal-policy | write | tickets.settings.update | TicketAdministrationApiTest | ticket-settings.show, ticket-settings.history |
| PUT /api/v1/ticket-settings/quote-cost-policy | write | tickets.settings.update | TicketAdministrationApiTest | ticket-settings.show, ticket-settings.history |
| PUT /api/v1/ticket-settings/merge-policy | write | tickets.settings.update | TicketAdministrationApiTest | ticket-settings.show, ticket-settings.history |
| GET /api/v1/ticket-settings/context/{catalog} | read | tickets.settings.read | TicketAdministrationApiTest | ticket-settings.context |
| GET /api/v1/ticket-settings/history | read | tickets.settings.read | TicketAdministrationApiTest | ticket-settings.history |
| GET /api/v1/ticket-queues | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-queues.index |
| GET /api/v1/ticket-queues/{queue} | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-queues.show |
| POST /api/v1/ticket-queues | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| PUT /api/v1/ticket-queues/{queue} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| DELETE /api/v1/ticket-queues/{queue} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| GET /api/v1/ticket-types | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-types.index |
| GET /api/v1/ticket-types/{type} | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-types.show |
| POST /api/v1/ticket-types | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| PUT /api/v1/ticket-types/{type} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| DELETE /api/v1/ticket-types/{type} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| GET /api/v1/ticket-statuses | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-statuses.index |
| GET /api/v1/ticket-statuses/{status} | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-statuses.show |
| POST /api/v1/ticket-statuses | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| PUT /api/v1/ticket-statuses/{status} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| DELETE /api/v1/ticket-statuses/{status} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| GET /api/v1/ticket-priorities | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-priorities.index |
| GET /api/v1/ticket-priorities/{priority} | read | tickets.catalogs.read | TicketAdministrationApiTest | ticket-priorities.show |
| POST /api/v1/ticket-priorities | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| PUT /api/v1/ticket-priorities/{priority} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| DELETE /api/v1/ticket-priorities/{priority} | write | tickets.catalogs.manage | TicketAdministrationApiTest | ticket-queues.show, ticket-types.show, ticket-statuses.show, ticket-priorities.show |
| GET /api/v1/tickets/{ticket}/lifecycle-state | read | tickets.read | TicketLifecycleApiTest | tickets.lifecycle-state.show |
| POST /api/v1/tickets/{ticket}/read | action | tickets.read-state.write | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.messages.index |
| POST /api/v1/tickets/{ticket}/messages/{message}/read | action | tickets.read-state.write | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.messages.index |
| GET /api/v1/tickets/{ticket}/assignment-preview | read | tickets.assign | TicketLifecycleApiTest | tickets.assignment.preview |
| POST /api/v1/tickets/{ticket}/assignment | action | tickets.assign | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.messages.index |
| DELETE /api/v1/tickets/{ticket} | write | tickets.delete | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.messages.index |
| POST /api/v1/tickets/{ticket}/not-ticket | action | tickets.classify | TicketLifecycleApiTest | tickets.lifecycle-state.show, tickets.show, tickets.messages.index |
| PATCH /api/v1/tickets/{ticket}/time-entries/{timeEntry} | write | tickets.time.update | TicketLedgerApiTest | tickets.time-entries.show, tickets.cost-entries.show |
| POST /api/v1/tickets/{ticket}/time-entries/invoice-text-suggestion | action | tickets.time.suggest, tickets.messages.read, tickets.read | TicketLedgerApiTest | tickets.time-entries.show, tickets.cost-entries.show |
| PATCH /api/v1/tickets/{ticket}/cost-entries/{costEntry} | write | tickets.costs.update | TicketLedgerApiTest | tickets.time-entries.show, tickets.cost-entries.show |
| DELETE /api/v1/tickets/{ticket}/cost-entries/{costEntry} | write | tickets.costs.release | TicketLedgerApiTest | tickets.time-entries.show, tickets.cost-entries.show |
| GET /api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick-preview | read | tickets.costs.pick | TicketLedgerApiTest | tickets.cost-entries.pick-preview |
| POST /api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick | action | tickets.costs.pick | TicketLedgerApiTest | tickets.time-entries.show, tickets.cost-entries.show |
| GET /api/v1/tickets/{ticket}/conversation | read | tickets.messages.read | TicketConversationApiTest | tickets.conversation.index |
| GET /api/v1/tickets/{ticket}/conversation/{message} | read | tickets.messages.read | TicketConversationApiTest | tickets.conversation.show |
| GET /api/v1/tickets/{ticket}/attachments | read | tickets.messages.read | TicketConversationApiTest | tickets.attachments.index |
| GET /api/v1/tickets/{ticket}/attachments/{attachment} | read | tickets.messages.read | TicketConversationApiTest | tickets.attachments.show |
| GET /api/v1/tickets/{ticket}/attachments/{attachment}/download | read | tickets.attachments.download | TicketConversationApiTest | tickets.attachments.download |
| POST /api/v1/tickets/{ticket}/internal-notes | write | tickets.notes.create | TicketConversationApiTest | tickets.conversation.show, tickets.attachments.show |
| POST /api/v1/tickets/{ticket}/messages/{message}/solution | action | tickets.solutions.mark | TicketConversationApiTest | tickets.conversation.show, tickets.attachments.show |
| GET /api/v1/tickets/capabilities | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.capabilities |
| GET /api/v1/tickets/context | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.context |
| GET /api/v1/tickets/context/{catalog} | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.context.options |
| GET /api/v1/tickets/{ticket}/context | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.context.show |
| GET /api/v1/tickets/{ticket}/time-rate-options | read | tickets.time.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.time-rate-options.index |
| GET /api/v1/tickets/{ticket}/activities | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.activities.index |
| GET /api/v1/tickets/{ticket}/time-entries | read | tickets.time.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.time-entries.index |
| GET /api/v1/tickets/{ticket}/time-entries/{timeEntry} | read | tickets.time.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.time-entries.show |
| GET /api/v1/tickets/{ticket}/cost-entries | read | tickets.costs.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.cost-entries.index |
| GET /api/v1/tickets/{ticket}/cost-entries/{costEntry} | read | tickets.costs.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.cost-entries.show |
| GET /api/v1/tickets/{ticket}/planned-lines | read | tickets.costs.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.planned-lines.index |
| GET /api/v1/tickets/{ticket}/tasks | read | tasks.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.tasks.index |
| GET /api/v1/tickets/{ticket}/schedule | read | tickets.read | TicketReadbackApiTest, TicketApiParityBoundaryTest | tickets.schedule.show |
| GET /api/v1/tickets | read | tickets.read | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.index |
| GET /api/v1/tickets/stale | read | tickets.read | TicketModuleTest | tickets.stale.index |
| POST /api/v1/tickets | write | tickets.create | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| GET /api/v1/tickets/{ticket} | read | tickets.read | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show |
| PUT /api/v1/tickets/{ticket} | write | tickets.update | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| PATCH /api/v1/tickets/{ticket} | write | tickets.update | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| GET /api/v1/tickets/{ticket}/messages | read | tickets.read | TicketMessageReadApiTest | tickets.messages.index |
| POST /api/v1/tickets/{ticket}/external-messages | write | tickets.update | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| POST /api/v1/tickets/{ticket}/portal-visibility | action | tickets.portal.publish | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| POST /api/v1/tickets/{ticket}/messages | write | tickets.reply_customer | TicketModuleTest, TicketLifecycleApiTest, TicketExternalMessageApiTest, TicketCustomerCompletionApiTest | tickets.show, tickets.history.index |
| POST /api/v1/tickets/{ticket}/timer/start | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/time-entries | write | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/cost-entries | write | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| GET /api/v1/tickets/{ticket}/workflow-decisions | read | tickets.workflow.read | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/workflow-transitions/{transitionKey} | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/workflow-escalations/{pathKey} | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/close | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/planned-lines | write | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| DELETE /api/v1/tickets/{ticket}/planned-lines/{plannedLine} | write | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/planned-lines/{plannedLine}/convert | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/planned-lines/{plannedLine}/purchase | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/sales-quote | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/sales-quote/send | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/sales-quote/versions/{version}/void | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/messages/{message}/quote-versions/{version}/accept | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/workflow-reviews | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/workflow-reviews/{review}/decision | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| POST /api/v1/tickets/{ticket}/workflow-evidence | action | tickets.actions | TicketWorkflowV3Test, TicketCustomerCompletionApiTest, TicketModuleTest | tickets.show, tickets.time-entries.show, tickets.cost-entries.show, tickets.planned-lines.index, tickets.quote-versions.show, tickets.workflow-reviews.show, tickets.workflow-evidence.show, tickets.workflow-decisions.show |
| GET /api/v1/ticket-workflow-catalog | read | tickets.workflow.read | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.catalog |
| GET /api/v1/ticket-workflows | read | tickets.workflow.manage | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.index |
| POST /api/v1/ticket-workflows | write | tickets.workflow.manage | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show, tickets.show |
| GET /api/v1/ticket-workflows/{workflow} | read | tickets.workflow.manage | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show |
| PUT /api/v1/ticket-workflows/{workflow} | write | tickets.workflow.manage | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show, tickets.show |
| POST /api/v1/ticket-workflows/{workflow}/publish | action | tickets.workflow.publish | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show, tickets.show |
| POST /api/v1/ticket-workflows/{workflow}/migration-preview | action | tickets.workflow.manage | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show, tickets.show |
| POST /api/v1/ticket-workflows/{workflow}/migrations | action | tickets.workflow.publish | TicketApiParityBoundaryTest, TicketWorkflowV3Test | ticket-workflows.show, tickets.show |
| GET /api/v1/task-settings/history | read | tasks.settings.read | TaskScheduleSettingsApiTest | task-settings.history |
| GET /api/v1/task-templates/{template}/history | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.history |
| GET /api/v1/task-settings | read | tasks.settings.read | TaskScheduleSettingsApiTest | task-settings.show |
| PUT /api/v1/task-settings | write | tasks.settings.update | TaskScheduleSettingsApiTest | task-settings.show, task-settings.history |
| GET /api/v1/task-settings/context/{catalog} | read | tasks.settings.read | TaskScheduleSettingsApiTest | task-settings.context |
| POST /api/v1/task-settings/initialize | action | tasks.settings.update | TaskScheduleSettingsApiTest | task-settings.show, task-settings.history |
| GET /api/v1/task-templates/{template}/schedules | read | tasks.schedules.read | TaskScheduleSettingsApiTest | task-schedules.index |
| GET /api/v1/task-templates/{template}/schedules/{schedule} | read | tasks.schedules.read | TaskScheduleSettingsApiTest | task-schedules.show |
| POST /api/v1/task-templates/{template}/schedules | write | tasks.schedules.manage | TaskScheduleSettingsApiTest | task-schedules.show, task-schedules.runs |
| PUT /api/v1/task-templates/{template}/schedules/{schedule} | write | tasks.schedules.manage | TaskScheduleSettingsApiTest | task-schedules.show, task-schedules.runs |
| PATCH /api/v1/task-templates/{template}/schedules/{schedule}/active | write | tasks.schedules.manage | TaskScheduleSettingsApiTest | task-schedules.show, task-schedules.runs |
| DELETE /api/v1/task-templates/{template}/schedules/{schedule} | write | tasks.schedules.manage | TaskScheduleSettingsApiTest | task-schedules.show, task-schedules.runs |
| GET /api/v1/task-templates/{template}/schedules/{schedule}/run-preview | read | tasks.schedules.read | TaskScheduleSettingsApiTest | task-schedules.preview |
| POST /api/v1/task-templates/{template}/schedules/{schedule}/run | action | tasks.schedules.run | TaskScheduleSettingsApiTest | task-schedules.show, task-schedules.runs |
| GET /api/v1/task-templates/{template}/schedules/{schedule}/runs | read | tasks.schedules.read | TaskScheduleSettingsApiTest | task-schedules.runs |
| GET /api/v1/task-templates | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.index |
| GET /api/v1/task-templates/{template} | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show |
| POST /api/v1/task-templates | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| PUT /api/v1/task-templates/{template} | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| DELETE /api/v1/task-templates/{template} | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| GET /api/v1/task-templates/{template}/items | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.items.index |
| GET /api/v1/task-templates/{template}/items/{item} | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.items.show |
| POST /api/v1/task-templates/{template}/items | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| PUT /api/v1/task-templates/{template}/items/{item} | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| DELETE /api/v1/task-templates/{template}/items/{item} | write | tasks.templates.manage | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| GET /api/v1/task-templates/{template}/preview | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.preview |
| POST /api/v1/task-templates/{template}/apply | action | tasks.templates.apply | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.show, task-templates.items.show, task-templates.runs.show, task-templates.runs.tasks, task-templates.history |
| GET /api/v1/task-template-targets | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.targets |
| GET /api/v1/task-templates/{template}/runs | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.runs.index |
| GET /api/v1/task-templates/{template}/runs/{run} | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.runs.show |
| GET /api/v1/task-templates/{template}/runs/{run}/tasks | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.runs.tasks |
| GET /api/v1/task-templates/context/{catalog} | read | tasks.templates.read | TaskTemplateApiTest, TaskTemplateApplicationTest | task-templates.context |
| PATCH /api/v1/tasks/{task}/assignee | write | tasks.assign | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| PATCH /api/v1/tasks/{task}/status | write | tasks.status.update | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| PUT /api/v1/tasks/{task}/checklist/{item} | write | tasks.checklist.update | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| POST /api/v1/tasks/{task}/time-entries | action | tasks.time.create | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| POST /api/v1/tasks/{task}/complete | action | tasks.complete | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| POST /api/v1/tasks/ai-suggestions | action | tasks.ai.suggest, tickets.read | TaskOperationApiTest | tasks.show, tasks.activities.index, tasks.time-entries.index |
| GET /api/v1/tasks/docs | read | tasks.read | TaskReadbackApiTest | tasks.docs |
| GET /api/v1/tasks/capabilities | read | tasks.read | TaskReadbackApiTest | tasks.capabilities |
| GET /api/v1/tasks/context | read | tasks.read | TaskReadbackApiTest | tasks.context |
| GET /api/v1/tasks/context/{catalog} | read | tasks.read | TaskReadbackApiTest | tasks.context.options |
| GET /api/v1/tasks/{task}/context | read | tasks.read | TaskReadbackApiTest | tasks.context.show |
| GET /api/v1/tasks/{task}/activities | read | tasks.read | TaskReadbackApiTest | tasks.activities.index |
| GET /api/v1/tasks/{task}/time-entries | read | tasks.time.read | TaskReadbackApiTest | tasks.time-entries.index |
| GET /api/v1/tasks/{task}/children | read | tasks.read | TaskReadbackApiTest | tasks.children.index |
| GET /api/v1/tasks/{task}/dependencies | read | tasks.read | TaskReadbackApiTest | tasks.dependencies.index |
| GET /api/v1/tasks | read | tasks.read | TaskFormApiTest, TaskModuleTest | tasks.index |
| GET /api/v1/tasks/stale | read | tasks.read | TaskReadbackApiTest, TaskModuleTest | tasks.stale.index |
| POST /api/v1/tasks | write | tasks.create | TaskFormApiTest, TaskModuleTest | tasks.show |
| GET /api/v1/tasks/{task} | read | tasks.read | TaskFormApiTest, TaskModuleTest | tasks.show |
| PUT /api/v1/tasks/{task} | write | tasks.update | TaskFormApiTest, TaskModuleTest | tasks.show |
| PATCH /api/v1/tasks/{task} | write | tasks.update | TaskFormApiTest, TaskModuleTest | tasks.show |
