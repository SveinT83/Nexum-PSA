<?php

namespace App\Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Open the fanout runtime only after Laravel recorded the complete additive
 * migration. Table existence alone is unsafe on MySQL/MariaDB because DDL
 * auto-commit can leave a partial table behind after a failed migration.
 */
final class InboundEmailNotificationFanoutReadiness
{
    public const MIGRATION = '2026_08_16_118500_add_durable_inbound_notification_fanout';

    private ?bool $resolved = null;

    public function ready(): bool
    {
        return $this->resolved ??= $this->resolve();
    }

    private function resolve(): bool
    {
        try {
            $repository = (string) config('database.migrations.table', 'migrations');
            if ($repository === ''
                || ! DB::table($repository)->where('migration', self::MIGRATION)->exists()) {
                return false;
            }

            $required = [
                'notification_inbound_email_fanouts' => [
                    'id',
                    'email_message_id',
                    'source_email_message_id',
                    'email_account_id',
                    'email_provider_reconciliation_item_id',
                    'automation_claim_token',
                    'notification_setting_through_id',
                    'notification_setting_cursor_id',
                    'owner_candidate_processed',
                    'owner_priority_reserved',
                    'status',
                    'claim_token',
                    'page_setting_through_id',
                    'page_setting_row_count',
                    'page_owner_pending',
                    'page_owner_candidate_included',
                    'page_attempt_count',
                    'page_count',
                    'last_attempt_at',
                    'completed_at',
                    'error_code',
                ],
                'notification_inbound_external_deliveries' => [
                    'id',
                    'inbound_notification_fanout_id',
                    'canonical_payload_hash',
                    'status',
                    'claim_token',
                    'attempt_count',
                    'last_attempt_at',
                    'completed_at',
                    'error_code',
                ],
                'notification_inbound_ticket_message_repairs' => [
                    'id',
                    'status',
                    'through_id',
                    'cursor_id',
                    'claim_token',
                    'page_through_id',
                    'page_row_count',
                    'page_count',
                    'last_attempt_at',
                    'completed_at',
                    'error_code',
                ],
                'ticket_messages' => [
                    'source_inbound_email_message_id',
                    'inbound_email_message_id',
                ],
            ];
            $present = $this->columnMap(array_keys($required));

            foreach ($required as $table => $columns) {
                if (! $this->hasColumns($present, $table, $columns)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, array<string, true>>
     */
    private function columnMap(array $tables): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $prefix = $connection->getTablePrefix();
        $physicalToLogical = [];
        foreach ($tables as $table) {
            $physicalToLogical[strtolower($prefix.$table)] = strtolower($table);
        }
        $present = array_fill_keys(array_values($physicalToLogical), []);

        if ($driver === 'sqlite') {
            foreach ($physicalToLogical as $physical => $logical) {
                $quoted = str_replace("'", "''", $physical);
                foreach (DB::select("pragma table_info('{$quoted}')") as $column) {
                    $present[$logical][strtolower((string) $column->name)] = true;
                }
            }

            return $present;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [];
        }

        $rows = DB::table('information_schema.columns')
            ->where('table_schema', $connection->getDatabaseName())
            ->whereIn('table_name', array_keys($physicalToLogical))
            ->get(['table_name', 'column_name']);
        foreach ($rows as $column) {
            $logical = $physicalToLogical[strtolower((string) $column->table_name)] ?? null;
            if ($logical !== null) {
                $present[$logical][strtolower((string) $column->column_name)] = true;
            }
        }

        return $present;
    }

    /**
     * @param  array<string, array<string, true>>  $present
     * @param  array<int, string>  $columns
     */
    private function hasColumns(array $present, string $table, array $columns): bool
    {
        $tableColumns = $present[strtolower($table)] ?? [];

        return collect($columns)->every(
            fn (string $column): bool => isset($tableColumns[strtolower($column)]),
        );
    }
}
