<?php

namespace App\Modules\Email\Support;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Default-deny projections for the existing operator/access models; no raw model serialization. */
final class EmailApiProjection
{
    private const OPERATIONS = [
        'EmailHistoricalImportRun', 'EmailHistoricalImportItem', 'EmailProviderReconciliationRun',
        'EmailProviderReconciliationFolder', 'EmailProviderReconciliationItem', 'EmailCursorRebaselineRun',
        'EmailCanonicalCutoverRun', 'EmailCanonicalCutoverItem', 'EmailCanonicalCorrelationRun',
        'EmailCanonicalCorrelationCandidate', 'EmailCanonicalParityAttestation', 'EmailUnreadHandoverRun',
        'EmailUnreadHandoverItem', 'EmailMailboxDelegation', 'EmailBreakGlassAccess',
        'EmailMailboxAccessEvent', 'EmailTicketCorrelationConflict',
    ];

    private const FIELDS = [
        'active_slot', 'ambiguous_count', 'attempt_count', 'automation_attempt_count', 'automation_completed_at', 'automation_error_code', 'automation_last_attempt_at', 'automation_required', 'automation_rule_attempt_floor_id', 'automation_scope_error_code', 'automation_scope_unsafe', 'automation_scope_unsafe_at', 'automation_status', 'baseline_max_placement_id', 'baseline_placement_count', 'cursor_message_id', 'custom_flags_hash', 'custom_flags_json', 'delimiter', 'different_count', 'discovery_state', 'email_historical_import_run_id', 'email_provider_placement_finding_id', 'email_provider_reconciliation_folder_id', 'email_remote_operation_id', 'email_unread_handover_run_id', 'end_exists_count', 'end_folder_scope_hash', 'end_highest_modseq', 'end_supports_modseq', 'end_uid_next', 'end_uid_validity', 'evidence_bytes_processed', 'evidence_run_byte_cap', 'evidence_snapshot_byte_cap', 'expected_uid_validity', 'final_summary_automation_failed', 'final_summary_batch_count', 'final_summary_blocked', 'final_summary_complete_folder_count', 'final_summary_completed_at', 'final_summary_conflict_count', 'final_summary_error_count', 'final_summary_failed', 'final_summary_folder_cursor_id', 'final_summary_folder_through_id', 'final_summary_item_cursor_id', 'final_summary_item_through_id', 'final_summary_missing_count', 'final_summary_move_count', 'final_summary_stale', 'final_summary_started_at', 'final_summary_status', 'first_attempt_at', 'folder_name', 'frozen_active_placement_count', 'frozen_evidence_fingerprint', 'frozen_max_placement_id', 'frozen_scope_fingerprint', 'groups_processed', 'historical_baseline_attempt_count', 'historical_baseline_completed_at', 'historical_baseline_cursor_id', 'historical_baseline_error_code', 'historical_baseline_first_attempt_at', 'historical_baseline_frozen_at', 'historical_baseline_last_attempt_at', 'historical_baseline_max_id', 'historical_baseline_required', 'historical_baseline_status', 'identity_hash', 'import_policy', 'initial_scope_verified_at', 'inventory_hash', 'item_summary_batch_count', 'item_summary_completed_at', 'item_summary_conflict_count', 'item_summary_cursor_id', 'item_summary_missing_count', 'item_summary_move_count', 'item_summary_nonterminal', 'item_summary_started_at', 'item_summary_status', 'item_summary_through_id', 'kind', 'last_attempt_at', 'left_evidence_hash', 'local_folder_snapshot_batch_count', 'local_folder_snapshot_completed_at', 'local_folder_snapshot_count', 'local_folder_snapshot_cursor_id', 'local_folder_snapshot_hash', 'local_folder_snapshot_started_at', 'local_folder_snapshot_status', 'local_folder_snapshot_through_id', 'metadata_json', 'metadata_verification_batch_count', 'metadata_verification_completed_at', 'metadata_verification_count', 'metadata_verification_hash', 'metadata_verification_next_uid', 'metadata_verification_started_at', 'metadata_verification_status', 'next_placement_id', 'normal_interval_seconds', 'owner_notification_sent_at', 'pairs_processed', 'parent_path', 'placement_baseline_hash', 'placement_scan_hash', 'placement_snapshot_batch_count', 'placement_snapshot_completed_at', 'placement_snapshot_count', 'placement_snapshot_cursor_id', 'placement_snapshot_hash', 'placement_snapshot_purpose', 'placement_snapshot_started_at', 'placement_snapshot_status', 'placement_snapshot_through_id', 'placement_sync_version_after', 'placement_sync_version_before', 'possible_count', 'provider_answered', 'provider_configuration_version', 'provider_credential_version', 'provider_deleted', 'provider_draft', 'provider_flagged', 'provider_modseq', 'provider_runtime_fingerprint', 'provider_seen', 'provider_selectable', 'provider_snapshot_json', 'provider_sync_enabled', 'provider_time_cap_seconds', 'remote_id', 'result_placement_id', 'reviewed_by', 'right_evidence_hash', 'scan_started_at', 'scoped_evidence_bytes', 'scoped_message_count', 'security_notification_sent_at', 'snapshot_order', 'source_fingerprint', 'source_placement_id', 'special_use', 'start_exists_count', 'start_folder_scope_hash', 'start_highest_modseq', 'start_uid_next', 'start_uid_validity', 'strict_evidence', 'strong_count', 'supports_modseq', 'target_placement_id', 'total_evidence_bytes', 'verified_placement_count',
        'frozen_min_message_id', 'frozen_max_message_id', 'item_count', 'rolled_back_count', 'previewed_at', 'rolled_back_at',
        'reason_codes_json', 'left_email_account_id', 'right_email_account_id', 'group_size', 'reviewed_at',
        'old_uid_namespace_id', 'new_uid_namespace_id', 'uid_namespace_id', 'uid_validity', 'imap_uid', 'completed_at',
        'correlation_candidate_ids_json', 'previous_placement_pointers_json', 'previous_evidence_complete',
        'previous_mapped_at', 'previous_read_mode_row_exists', 'previous_read_mode_lock_version', 'parity_attestation_id',
        'id', 'public_id', 'account_id', 'folder_path', 'next_uid', 'scan_through_uid', 'email_provider_reconciliation_run_id', 'email_account_id', 'email_folder_id', 'email_message_id',
        'email_mailbox_placement_id', 'source_email_message_id', 'canonical_email_message_id',
        'email_canonical_cutover_run_id', 'email_canonical_correlation_run_id', 'run_id', 'parent_run_id',
        'requested_by', 'cancelled_by', 'created_by', 'updated_by', 'owner_id', 'delegate_id', 'actor_id',
        'target_user_id', 'affected_user_id', 'revoked_by', 'resolved_by', 'resolved_ticket_id',
        'status', 'phase', 'operation', 'run_kind', 'item_kind', 'item_key', 'component_key',
        'candidate_class', 'review_state', 'review_reason_code', 'reason_code', 'failure_code', 'error_code',
        'reason', 'revocation_reason', 'resolution_reason', 'event_type', 'event', 'operation_type',
        'resource_type', 'resource_id', 'source', 'provider', 'trigger', 'email_mailbox_delegation_id', 'email_break_glass_access_id',
        'account_scope_json', 'folder_scope_json', 'candidate_ticket_ids', 'blocker_codes_json',
        'limits_json', 'summary_json', 'counters_json', 'account_read_modes_json', 'proposed_read_mode',
        'previous_read_mode', 'read_mode', 'preview_fingerprint', 'scope_fingerprint', 'snapshot_fingerprint',
        'authorization_fingerprint', 'evidence_complete', 'evidence_fingerprint', 'inspected_by_current_user',
        'left_email_message_id', 'right_email_message_id', 'left_account_id', 'right_account_id',
        'source_message_ids_json', 'candidate_count', 'message_count', 'group_count', 'pair_count',
        'requested_cap', 'effective_cap', 'item_cap', 'message_cap', 'group_cap', 'pair_cap', 'per_group_cap',
        'date_from', 'date_to', 'uid_from', 'uid_to', 'old_uid_validity', 'observed_uid_validity',
        'observed_uid_next', 'old_live_start_uid', 'new_live_start_uid', 'old_placement_count',
        'retired_placement_count', 'matched_count', 'pending_count', 'already_present_count',
        'imported_count', 'skipped_count', 'failed_count', 'selected_count', 'applied_count',
        'already_unread_count', 'folder_count', 'complete_folder_count', 'batch_count', 'observed_count',
        'import_count', 'flag_change_count', 'missing_count', 'move_count', 'conflict_count', 'error_count',
        'requested_count', 'completed_count', 'processed_count', 'total_count', 'max_folders', 'uid_batch_size',
        'provider_binding_version', 'access_epoch', 'baseline_message_id', 'is_expanded',
        'can_view', 'can_organize', 'can_send', 'can_view_raw_source', 'can_view_content', 'can_search',
        'can_download_attachments', 'starts_at', 'expires_at', 'preview_expires_at', 'queued_at', 'started_at',
        'finished_at', 'last_progress_at', 'retry_at', 'cancellation_requested_at', 'applied_at', 'revoked_at',
        'resolved_at', 'detected_at', 'occurred_at', 'created_at', 'updated_at',
    ];

    public function value(mixed $value): mixed
    {
        if ($value instanceof LengthAwarePaginator) {
            return ['data' => $this->value(collect($value->items())), 'meta' => [
                'current_page' => $value->currentPage(), 'last_page' => $value->lastPage(),
                'per_page' => $value->perPage(), 'total' => $value->total()]];
        }
        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->value($item))->all();
        }
        if ($value instanceof EmailAccount) {
            return $value->only(['id', 'address', 'description', 'account_kind', 'owner_id', 'is_active']);
        }
        if ($value instanceof User) {
            return $value->only(['id', 'name', 'email', 'status']);
        }
        if ($value instanceof EmailMessage) {
            // Inspection eagerly loads only account id/address for display. Authorization needs
            // the current complete account, including activation/kind/owner and current grants.
            $account = EmailAccount::query()->find((int) $value->account_id);
            if (! $account || ! app(MailboxAccess::class)->canAccessAccount(auth()->user(), $account, MailboxAccess::VIEW)) {
                return null;
            }

            $data = $value->only(['id', 'account_id', 'subject', 'message_id', 'from_name', 'from_email',
                'to_json', 'cc_json', 'headers_json', 'in_reply_to', 'references', 'received_at',
                'size_bytes', 'checksum_sha1', 'body_text', 'body_html_sanitized']);
            if ($value->relationLoaded('attachments')) {
                $data['attachments'] = $value->attachments->map(fn ($attachment) => $attachment->only([
                    'id', 'message_id', 'filename', 'content_type', 'size_bytes', 'is_inline', 'cid', 'checksum_sha1',
                ]))->all();
            }
            if ($value->relationLoaded('placements')) {
                $data['placements'] = $value->placements->where('account_id', $value->account_id)
                    ->map(fn ($placement) => $placement->only(['id', 'account_id', 'email_folder_id', 'folder_path',
                        'provider_seen', 'provider_flagged', 'local_state', 'sync_status']))->values()->all();
            }

            return $data;
        }
        if ($value instanceof Model) {
            $name = class_basename($value);
            if ($name === 'EmailFolder') {
                return $value->only(['id', 'account_id', 'path', 'name', 'parent_path', 'delimiter', 'role', 'is_selectable', 'sync_enabled', 'sync_status', 'uid_validity', 'uid_next', 'live_start_uid', 'active_uid_namespace_id', 'highest_modseq', 'exists_count', 'unseen_count', 'last_synced_at', 'sync_error_code']);
            }
            if ($name === 'Ticket') {
                return $value->only(['id', 'ticket_key']);
            }
            if (! in_array($name, self::OPERATIONS, true)) {
                return $value->only(['id']);
            }
            $data = $this->value($value->only(array_intersect(self::FIELDS, array_keys($value->getAttributes()))));
            foreach (['folders', 'items', 'candidates', 'requester', 'cancelledBy', 'account', 'actor', 'affectedUser', 'delegate', 'creator', 'revoker', 'targetUser', 'requestedBy', 'folder'] as $relation) {
                if ($value->relationLoaded($relation)) {
                    $data[$relation] = $this->value($value->getRelation($relation));
                }
            }

            return $data;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/secret|password|ciphertext|(?:^|_)token|raw_path|storage_path|idempotency_?key|^authorization$|^cookie$|^auth_header$/i', $key)) {
                    continue;
                }
                $result[$key] = $this->value($item);
            }

            return $result;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
