<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGES = 'email_live_projection_changes';

    private const PUBLICATIONS = 'email_live_projection_publications';

    private const DELIVERIES = 'email_live_projection_deliveries';

    private const USER_ACCESS = 'email_live_user_access_states';

    /**
     * Repair retry/recovery transitions without editing the already-applied
     * foundation migration. Runtime stays disabled until supervised rollout.
     */
    public function up(): void
    {
        if (config('email_live.enabled', false)) {
            throw new RuntimeException('Disable Email live invalidation before repairing publisher guards.');
        }

        foreach ([self::CHANGES, self::PUBLICATIONS, self::DELIVERIES, self::USER_ACCESS] as $table) {
            if (! DB::connection()->pretending() && ! Schema::hasTable($table)) {
                throw new RuntimeException("Email live publisher table {$table} is missing.");
            }
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported Email live guard database driver: {$driver}.");
        }

        $this->attestExistingRows();
        $this->replaceUpdateGuard(
            self::CHANGES,
            'em_live_change_contract_update',
            $this->changeUpdateContract(),
            $driver,
        );
        $this->replaceUpdateGuard(
            self::PUBLICATIONS,
            'em_live_publication_contract_update',
            $this->publicationUpdateContract(),
            $driver,
        );
        $this->replaceUpdateGuard(
            self::DELIVERIES,
            'em_live_delivery_contract_update',
            $this->deliveryUpdateContract(),
            $driver,
        );
        $this->replaceUpdateGuard(
            self::USER_ACCESS,
            'em_live_user_access_contract_update',
            $this->userAccessUpdateContract(),
            $driver,
        );
    }

    /** Guard repair is forward-only because old guards strand retry evidence. */
    public function down(): void {}

    private function attestExistingRows(): void
    {
        $contracts = [
            [self::CHANGES, "outer_row.publication_status in ('pending','running','published','sealed','blocked')"
                .' and outer_row.attempt_count between 0 and 3'
                .' and outer_row.version >= 1 and length(outer_row.idempotency_key) = 64'],
            [self::PUBLICATIONS, "outer_row.status in ('pending','running','sealed','blocked')"
                ." and outer_row.delivery_summary_status in ('waiting','pending','running','sealed','blocked')"
                .' and outer_row.attempt_count between 0 and 3'
                .' and outer_row.delivery_attempt_count between 0 and 3'
                .' and outer_row.candidate_cursor_id >= 0'],
            [self::DELIVERIES, "outer_row.status in ('pending','running','appended','suppressed','blocked')"
                .' and outer_row.attempt_count between 0 and 3'
                .' and outer_row.user_id >= 1 and outer_row.frozen_user_authorization_epoch >= 1'],
            [self::USER_ACCESS, "outer_row.recompute_status in ('sealed','pending','running','blocked')"
                .' and outer_row.authorization_epoch >= 1'
                .' and outer_row.content_ability_enable_generation >= 1'
                .' and outer_row.global_authorization_generation_seen >= 1'],
        ];

        foreach ($contracts as [$table, $contract]) {
            if (DB::table($table.' as outer_row')
                ->whereRaw("coalesce(({$contract}), 0) = 0")
                ->limit(1)
                ->exists()) {
                throw new RuntimeException("Malformed Email live publisher state in {$table}.");
            }
        }
    }

    private function changeUpdateContract(): string
    {
        $immutable = $this->sameColumns([
            'id',
            'stream_id',
            'version',
            'email_account_id',
            'idempotency_key',
            'change_types_json',
            'conversation_ids_json',
            'placement_ids_json',
            'conversation_id_count',
            'placement_id_count',
            'truncated',
            'available_at',
            'created_at',
        ]);
        $terminal = $this->sameColumns([
            'publication_status',
            'claim_token',
            'attempt_count',
            'last_attempt_at',
            'next_attempt_at',
            'published_at',
            'sealed_at',
            'retention_ready_at',
            'compact_delivery_count',
            'compact_appended_count',
            'compact_suppressed_count',
            'error_code',
        ]);
        $claim = "OLD.publication_status = 'pending' and NEW.publication_status = 'running'"
            .' and NEW.attempt_count = OLD.attempt_count + 1'
            .' and NEW.attempt_count between 1 and 3'
            .' and length(NEW.claim_token) = 64 and NEW.last_attempt_at is not null'
            .' and NEW.next_attempt_at is null and NEW.error_code is null';
        $retry = "OLD.publication_status = 'running' and NEW.publication_status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.claim_token is null'
            .' and NEW.next_attempt_at is not null'
            ." and NEW.error_code in ('email_live_append_failed','email_live_transport_failed')";
        $published = "OLD.publication_status = 'running' and NEW.publication_status = 'published'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.claim_token is null'
            .' and NEW.published_at is not null and NEW.sealed_at is null'
            .' and NEW.next_attempt_at is null and NEW.error_code is null';
        $sealed = "OLD.publication_status in ('running','published') and NEW.publication_status = 'sealed'"
            .' and NEW.claim_token is null and NEW.sealed_at is not null'
            .' and NEW.retention_ready_at is not null and NEW.error_code is null'
            .' and NEW.compact_delivery_count = NEW.compact_appended_count + NEW.compact_suppressed_count'
            .' and ((OLD.publication_status = \'published\''
            .' and exists(select 1 from email_live_projection_streams as acknowledged_stream'
            .' where acknowledged_stream.id = NEW.stream_id'
            .' and acknowledged_stream.stream_type = \'user\''
            .' and acknowledged_stream.acknowledged_version >= NEW.version))'
            .' or (OLD.publication_status = \'running\''
            .' and exists(select 1 from email_live_projection_publications as sealed_publication'
            .' where sealed_publication.source_change_id = NEW.id'
            .' and sealed_publication.delivery_summary_status = \'sealed\''
            .' and sealed_publication.delivery_count = NEW.compact_delivery_count'
            .' and sealed_publication.delivery_appended_count = NEW.compact_appended_count'
            .' and sealed_publication.delivery_suppressed_count = NEW.compact_suppressed_count)))';
        $blocked = "OLD.publication_status in ('pending','running') and NEW.publication_status = 'blocked'"
            .' and NEW.claim_token is null and NEW.attempt_count = 3'
            .' and NEW.published_at is null and NEW.sealed_at is null'
            ." and NEW.error_code in ('email_live_append_failed','email_live_transport_failed','email_live_attempts_exhausted')";

        return "({$immutable})"
            ." and NEW.publication_status in ('pending','running','published','sealed','blocked')"
            .' and NEW.attempt_count between OLD.attempt_count and 3'
            ." and ((OLD.publication_status in ('sealed','blocked') and ({$terminal}))"
            ." or ({$claim}) or ({$retry}) or ({$published}) or ({$sealed}) or ({$blocked}))";
    }

    private function publicationUpdateContract(): string
    {
        $frozen = $this->sameColumns([
            'id',
            'source_change_id',
            'source_stream_id',
            'source_stream_type',
            'email_account_id',
            'source_at',
            'frozen_owner_user_id',
            'account_audience_generation',
            'global_active_user_generation',
            'global_content_audience_generation',
            'global_content_ability_generation',
            'grant_through_id',
            'delegation_through_id',
            'break_glass_through_id',
            'active_user_through_id',
            'created_at',
        ]);
        $candidateColumns = [
            'phase',
            'candidate_cursor_id',
            'status',
            'claim_token',
            'page_through_id',
            'page_row_count',
            'attempt_count',
            'page_count',
            'last_attempt_at',
            'next_attempt_at',
            'completed_at',
            'error_code',
        ];
        $summaryColumns = [
            'delivery_summary_status',
            'delivery_through_id',
            'delivery_cursor_id',
            'delivery_count',
            'delivery_appended_count',
            'delivery_suppressed_count',
            'delivery_claim_token',
            'delivery_page_through_id',
            'delivery_page_row_count',
            'delivery_attempt_count',
            'delivery_page_count',
            'delivery_last_attempt_at',
            'delivery_next_attempt_at',
            'delivery_sealed_at',
            'delivery_error_code',
        ];
        $sameCandidate = $this->sameColumns($candidateColumns);
        $sameSummary = $this->sameColumns($summaryColumns);
        $sameSummaryAfterStart = $this->sameColumns(array_values(array_diff(
            $summaryColumns,
            ['delivery_summary_status', 'delivery_through_id'],
        )));
        $through = $this->publicationPhaseThrough('OLD.');
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.candidate_cursor_id'
            .' and NEW.attempt_count = OLD.attempt_count + 1 and NEW.attempt_count between 1 and 3'
            .' and NEW.page_count = OLD.page_count and length(NEW.claim_token) = 64'
            .' and NEW.page_through_id between OLD.candidate_cursor_id and '.$through
            .' and NEW.page_row_count between 0 and 100 and NEW.last_attempt_at is not null'
            .' and NEW.next_attempt_at is null and NEW.error_code is null';
        $page = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.page_count = OLD.page_count + 1'
            .' and NEW.claim_token is null and NEW.page_through_id is null and NEW.page_row_count is null'
            .' and NEW.next_attempt_at is null and NEW.error_code is null'
            .' and ((NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.page_through_id)'
            .' or (NEW.candidate_cursor_id = 0 and '.$this->nextPhaseContract().'))';
        $retry = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.candidate_cursor_id'
            .' and NEW.attempt_count = OLD.attempt_count and NEW.page_count = OLD.page_count'
            .' and NEW.claim_token is null and NEW.page_through_id is null and NEW.page_row_count is null'
            .' and NEW.next_attempt_at is not null'
            ." and NEW.error_code = 'email_live_candidate_page_failed'";
        $seal = "OLD.status = 'running' and NEW.status = 'sealed' and NEW.phase = 'sealed'"
            ." and OLD.phase in ('break_glass','active_users')"
            .' and OLD.page_through_id = '.$through
            .' and NEW.candidate_cursor_id = 0 and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1 and NEW.claim_token is null'
            .' and NEW.page_through_id is null and NEW.page_row_count is null'
            .' and NEW.completed_at is not null and NEW.error_code is null'
            ." and NEW.delivery_summary_status = 'pending' and NEW.delivery_through_id >= 0";
        $block = "OLD.status in ('pending','running') and NEW.status = 'blocked'"
            .' and NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.candidate_cursor_id'
            .' and NEW.attempt_count = 3 and NEW.page_count = OLD.page_count'
            .' and NEW.claim_token is null and NEW.page_through_id is null and NEW.page_row_count is null'
            .' and NEW.completed_at is not null'
            ." and NEW.error_code in ('email_live_candidate_page_failed','email_live_append_failed','email_live_attempts_exhausted')";
        $candidateTransition = "((OLD.status in ('sealed','blocked') and ({$sameCandidate}))"
            ." or ({$claim}) or ({$page}) or ({$retry}) or ({$seal}) or ({$block}))";

        $summaryClaim = "OLD.delivery_summary_status = 'pending' and NEW.delivery_summary_status = 'running'"
            .' and NEW.delivery_cursor_id = OLD.delivery_cursor_id'
            .' and NEW.delivery_count = OLD.delivery_count'
            .' and NEW.delivery_appended_count = OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count = OLD.delivery_suppressed_count'
            .' and NEW.delivery_attempt_count = OLD.delivery_attempt_count + 1'
            .' and length(NEW.delivery_claim_token) = 64'
            .' and NEW.delivery_page_through_id between OLD.delivery_cursor_id and OLD.delivery_through_id'
            .' and NEW.delivery_page_row_count between 0 and 100'
            .' and NEW.delivery_last_attempt_at is not null';
        $summaryCommit = "OLD.delivery_summary_status = 'running'"
            ." and NEW.delivery_summary_status in ('pending','sealed')"
            .' and NEW.delivery_cursor_id = OLD.delivery_page_through_id'
            .' and NEW.delivery_count = OLD.delivery_count + OLD.delivery_page_row_count'
            .' and NEW.delivery_appended_count >= OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count >= OLD.delivery_suppressed_count'
            .' and NEW.delivery_count = NEW.delivery_appended_count + NEW.delivery_suppressed_count'
            .' and NEW.delivery_claim_token is null and NEW.delivery_page_through_id is null'
            .' and NEW.delivery_page_row_count is null'
            .' and NEW.delivery_page_count = OLD.delivery_page_count + 1'
            ." and (NEW.delivery_summary_status <> 'sealed'"
            .' or (NEW.delivery_cursor_id = OLD.delivery_through_id and NEW.delivery_sealed_at is not null))';
        $summaryBlock = "OLD.delivery_summary_status in ('pending','running')"
            ." and NEW.delivery_summary_status = 'blocked'"
            .' and NEW.delivery_cursor_id = OLD.delivery_cursor_id'
            .' and NEW.delivery_count = OLD.delivery_count'
            .' and NEW.delivery_appended_count = OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count = OLD.delivery_suppressed_count'
            .' and NEW.delivery_attempt_count = 3 and NEW.delivery_claim_token is null'
            ." and NEW.delivery_error_code = 'email_live_attempts_exhausted'";
        $summaryTransition = "(({$sameSummary}) or ({$summaryClaim})"
            ." or ({$summaryCommit}) or ({$summaryBlock}))";
        $summaryStart = "OLD.delivery_summary_status = 'waiting'"
            ." and NEW.delivery_summary_status = 'pending'"
            .' and NEW.delivery_through_id = coalesce((select max(delivery.id)'
            .' from email_live_projection_deliveries as delivery'
            .' where delivery.publication_id = NEW.id), 0)'
            ." and ({$sameSummaryAfterStart})";

        return "({$frozen}) and NEW.attempt_count between OLD.attempt_count and 3"
            .' and NEW.delivery_attempt_count between OLD.delivery_attempt_count and 3'
            .' and NEW.delivery_cursor_id >= OLD.delivery_cursor_id'
            .' and NEW.delivery_count = NEW.delivery_appended_count + NEW.delivery_suppressed_count'
            ." and (({$candidateTransition} and ({$sameSummary}))"
            ." or (({$sameCandidate}) and {$summaryTransition})"
            ." or (({$seal}) and ({$summaryStart})))";
    }

    private function deliveryUpdateContract(): string
    {
        $immutable = $this->sameColumns([
            'id',
            'publication_id',
            'source_change_id',
            'user_id',
            'frozen_user_authorization_epoch',
            'created_at',
        ]);
        $terminal = $this->sameColumns([
            'authority_kind',
            'authority_id',
            'authority_enable_generation',
            'content_authority_path_id',
            'frozen_content_authority_generation',
            'derived_change_id',
            'derived_stream_id',
            'status',
            'claim_token',
            'attempt_count',
            'last_attempt_at',
            'next_attempt_at',
            'completed_at',
            'error_code',
        ]);
        $authorityNull = 'NEW.authority_kind is null and NEW.authority_id is null'
            .' and NEW.authority_enable_generation is null and NEW.content_authority_path_id is null'
            .' and NEW.frozen_content_authority_generation is null';
        $authorityPositive = "NEW.authority_kind in ('owner','grant','delegation','break_glass','active_user')"
            .' and NEW.authority_id >= 1 and NEW.authority_enable_generation >= 1'
            .' and NEW.content_authority_path_id >= 1 and NEW.frozen_content_authority_generation >= 1';
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and NEW.attempt_count = OLD.attempt_count + 1 and NEW.attempt_count between 1 and 3'
            .' and length(NEW.claim_token) = 64 and NEW.last_attempt_at is not null'
            .' and NEW.next_attempt_at is null and NEW.error_code is null'
            ." and ({$authorityNull})";
        $retry = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.claim_token is null'
            .' and NEW.next_attempt_at is not null'
            ." and NEW.error_code = 'email_live_append_failed' and ({$authorityNull})";
        $appended = "OLD.status = 'running' and NEW.status = 'appended'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.claim_token is null'
            .' and NEW.derived_change_id >= 1 and NEW.derived_stream_id >= 1'
            .' and NEW.completed_at is not null and NEW.error_code is null'
            ." and ({$authorityPositive})"
            .' and exists(select 1 from email_live_user_content_authority_paths as content_path'
            .' join email_live_projection_publications as source_publication'
            .' on source_publication.id = NEW.publication_id'
            .' where content_path.id = NEW.content_authority_path_id'
            .' and content_path.user_id = NEW.user_id and content_path.enabled = 1'
            .' and content_path.enable_generation = NEW.frozen_content_authority_generation'
            .' and content_path.enable_generation <= source_publication.global_content_ability_generation'
            .' and content_path.enabled_at <= source_publication.source_at)';
        $suppressed = "OLD.status = 'running' and NEW.status = 'suppressed'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.claim_token is null'
            .' and NEW.derived_change_id is null and NEW.derived_stream_id is null'
            .' and NEW.completed_at is not null'
            ." and NEW.error_code in ('email_live_currently_unauthorized','email_live_source_path_ineligible','email_live_duplicate_candidate')"
            ." and ({$authorityNull})";
        $blocked = "OLD.status in ('pending','running') and NEW.status = 'blocked'"
            .' and NEW.attempt_count = 3 and NEW.claim_token is null'
            .' and NEW.derived_change_id is null and NEW.derived_stream_id is null'
            .' and NEW.completed_at is not null'
            ." and NEW.error_code in ('email_live_append_failed','email_live_attempts_exhausted')"
            ." and ({$authorityNull})";

        return "({$immutable}) and NEW.attempt_count between OLD.attempt_count and 3"
            ." and ((OLD.status in ('appended','suppressed','blocked') and ({$terminal}))"
            ." or ({$claim}) or ({$retry}) or ({$appended}) or ({$suppressed}) or ({$blocked}))";
    }

    /**
     * Preserve the original bounded recompute state machine while permitting
     * one sealed receipt echo to advance the bounded-refresh timestamp.
     */
    private function userAccessUpdateContract(): string
    {
        $identity = $this->sameColumns(['id', 'user_id', 'created_at']);
        $generation = 'NEW.authorization_epoch between OLD.authorization_epoch'
            .' and OLD.authorization_epoch + 1'
            .' and NEW.content_ability_enable_generation between OLD.content_ability_enable_generation'
            .' and OLD.content_ability_enable_generation + 1'
            .' and NEW.global_authorization_generation_seen >= OLD.global_authorization_generation_seen'
            .' and exists(select 1 from email_live_global_authority_states as global_authority'
            .' where global_authority.id = 1'
            .' and NEW.global_authorization_generation_seen <= global_authority.authorization_generation'
            .' and NEW.content_ability_enable_generation <= global_authority.content_ability_generation)';
        $claim = "OLD.recompute_status = 'pending' and NEW.recompute_status = 'running'"
            .' and NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.recompute_cursor_id'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count + 1'
            .' and NEW.page_count = OLD.page_count';
        $commit = "OLD.recompute_status = 'running' and NEW.recompute_status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and ((NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.page_through_id)'
            ." or (OLD.recompute_phase = 'delegations'"
            .' and OLD.page_through_id = OLD.delegation_through_id'
            ." and NEW.recompute_phase = 'break_glass' and NEW.recompute_cursor_id = 0))";
        $seal = "OLD.recompute_status = 'running' and NEW.recompute_status = 'sealed'"
            ." and OLD.recompute_phase = 'break_glass'"
            .' and OLD.page_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id';
        $block = "OLD.recompute_status = 'running' and NEW.recompute_status = 'blocked'"
            .' and NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.recompute_cursor_id'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count and NEW.attempt_count = 3'
            .' and NEW.page_count = OLD.page_count';
        $reset = "OLD.recompute_status in ('sealed','pending','running')"
            ." and NEW.recompute_status = 'pending' and NEW.recompute_phase = 'delegations'"
            .' and NEW.recompute_cursor_id = 0 and NEW.attempt_count = 0 and NEW.page_count = 0'
            .' and (NEW.authorization_epoch = OLD.authorization_epoch + 1'
            .' or NEW.content_ability_enable_generation = OLD.content_ability_enable_generation + 1)'
            .' and ('.$this->userAccessFrozenHighWaterContract('NEW.').')';
        $blocked = "OLD.recompute_status = 'blocked'"
            .' and '.$this->sameColumns([
                'authorization_epoch',
                'content_ability_enable_generation',
                'global_authorization_generation_seen',
                'next_boundary_at',
                'last_bounded_refresh_at',
                'recompute_status',
                'recompute_phase',
                'delegation_through_id',
                'break_glass_through_id',
                'recompute_cursor_id',
                'recompute_boundary_at',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'attempt_count',
                'page_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
                'updated_at',
            ]);
        $sealedNoop = "OLD.recompute_status = 'sealed' and NEW.recompute_status = 'sealed'"
            .' and '.$this->sameColumns([
                'authorization_epoch',
                'content_ability_enable_generation',
                'global_authorization_generation_seen',
                'next_boundary_at',
                'last_bounded_refresh_at',
                'recompute_status',
                'recompute_phase',
                'delegation_through_id',
                'break_glass_through_id',
                'recompute_cursor_id',
                'recompute_boundary_at',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'attempt_count',
                'page_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
                'updated_at',
            ]);
        $refresh = "OLD.recompute_status = 'sealed' and NEW.recompute_status = 'sealed'"
            .' and NEW.last_bounded_refresh_at is not null'
            .' and (OLD.last_bounded_refresh_at is null'
            .' or NEW.last_bounded_refresh_at >= OLD.last_bounded_refresh_at)'
            .' and NEW.updated_at >= OLD.updated_at'
            .' and '.$this->sameColumns([
                'authorization_epoch',
                'content_ability_enable_generation',
                'global_authorization_generation_seen',
                'next_boundary_at',
                'recompute_status',
                'recompute_phase',
                'delegation_through_id',
                'break_glass_through_id',
                'recompute_cursor_id',
                'recompute_boundary_at',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'attempt_count',
                'page_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
            ]);

        return $this->userAccessContract('NEW.')." and ({$identity}) and ({$generation})"
            ." and (({$claim}) or ({$commit}) or ({$seal}) or ({$block})"
            ." or ({$reset}) or ({$blocked}) or ({$sealedNoop}) or ({$refresh}))";
    }

    private function userAccessContract(string $prefix): string
    {
        return "{$prefix}user_id >= 1 and {$prefix}authorization_epoch >= 1"
            ." and {$prefix}content_ability_enable_generation >= 1"
            ." and {$prefix}global_authorization_generation_seen >= 1"
            ." and {$prefix}delegation_through_id >= 0 and {$prefix}break_glass_through_id >= 0"
            ." and {$prefix}recompute_cursor_id >= 0 and {$prefix}attempt_count between 0 and 3"
            ." and {$prefix}recompute_status in ('sealed','pending','running','blocked')"
            ." and (({$prefix}recompute_status = 'sealed' and {$prefix}recompute_phase is null"
            ." and {$prefix}claim_token is null and {$prefix}page_through_id is null"
            ." and {$prefix}page_row_count is null and {$prefix}recompute_boundary_at is null"
            ." and {$prefix}completed_at is not null and {$prefix}error_code is null)"
            ." or ({$prefix}recompute_status = 'pending'"
            ." and {$prefix}recompute_phase in ('delegations','break_glass')"
            ." and {$prefix}claim_token is null and {$prefix}page_through_id is null"
            ." and {$prefix}page_row_count is null and {$prefix}recompute_boundary_at is not null"
            ." and {$prefix}completed_at is null"
            ." and ({$prefix}error_code is null or {$prefix}error_code = 'email_live_access_recompute_failed'))"
            ." or ({$prefix}recompute_status = 'running'"
            ." and {$prefix}recompute_phase in ('delegations','break_glass')"
            ." and length({$prefix}claim_token) = 64 and {$prefix}page_through_id is not null"
            ." and {$prefix}page_through_id >= {$prefix}recompute_cursor_id"
            ." and {$prefix}page_through_id <= case {$prefix}recompute_phase"
            ." when 'delegations' then {$prefix}delegation_through_id"
            ." else {$prefix}break_glass_through_id end"
            ." and {$prefix}page_row_count between 0 and 100"
            ." and {$prefix}attempt_count between 1 and 3 and {$prefix}last_attempt_at is not null"
            ." and {$prefix}recompute_boundary_at is not null"
            ." and {$prefix}completed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}recompute_status = 'blocked' and {$prefix}claim_token is null"
            ." and {$prefix}page_through_id is null and {$prefix}page_row_count is null"
            ." and {$prefix}attempt_count = 3 and {$prefix}completed_at is not null"
            ." and {$prefix}error_code in ('email_live_access_recompute_failed','email_live_attempts_exhausted')))";
    }

    private function userAccessFrozenHighWaterContract(string $prefix): string
    {
        return "{$prefix}delegation_through_id = coalesce((select max(candidate.id)"
            .' from email_mailbox_delegations as candidate'
            ." where candidate.delegate_id = {$prefix}user_id), 0)"
            ." and {$prefix}break_glass_through_id = coalesce((select max(candidate.id)"
            .' from email_break_glass_accesses as candidate'
            ." where candidate.actor_id = {$prefix}user_id), 0)";
    }

    private function publicationPhaseThrough(string $prefix): string
    {
        return "case {$prefix}phase"
            ." when 'owner' then case when {$prefix}frozen_owner_user_id is null then 0 else 1 end"
            ." when 'grants' then {$prefix}grant_through_id"
            ." when 'delegations' then {$prefix}delegation_through_id"
            ." when 'break_glass' then {$prefix}break_glass_through_id"
            ." when 'active_users' then {$prefix}active_user_through_id"
            ." else {$prefix}candidate_cursor_id end";
    }

    private function nextPhaseContract(): string
    {
        return "((OLD.phase = 'owner' and NEW.phase = 'grants')"
            ." or (OLD.phase = 'grants' and NEW.phase = 'delegations')"
            ." or (OLD.phase = 'delegations' and NEW.phase = 'break_glass'))";
    }

    /** @param list<string> $columns */
    private function sameColumns(array $columns): string
    {
        return collect($columns)
            ->map(fn (string $column): string => $this->sameColumn($column))
            ->implode(' and ');
    }

    private function sameColumn(string $column): string
    {
        return "((NEW.{$column} = OLD.{$column})"
            ." or (NEW.{$column} is null and OLD.{$column} is null))";
    }

    private function replaceUpdateGuard(
        string $table,
        string $trigger,
        string $contract,
        string $driver,
    ): void {
        $quotedTrigger = $driver === 'sqlite' ? '"'.$trigger.'"' : '`'.$trigger.'`';
        $quotedTable = $driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`';
        DB::unprepared("drop trigger if exists {$quotedTrigger}");

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger {$quotedTrigger} before update on {$quotedTable} "
                ."for each row begin if coalesce(({$contract}), 0) = 0 then "
                ."signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end",
            );

            return;
        }

        DB::unprepared(
            "create trigger {$quotedTrigger} before update on {$quotedTable} "
            ."when coalesce(({$contract}), 0) = 0 begin "
            ."select raise(abort, 'email_live_contract_invalid'); end",
        );
    }
};
