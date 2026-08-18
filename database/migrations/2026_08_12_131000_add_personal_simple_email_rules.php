<?php

use App\Modules\Email\Models\EmailRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_rules')) {
            return;
        }

        Schema::table('email_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_rules', 'rule_kind')) {
                $table->string('rule_kind', 40)
                    ->default(EmailRule::KIND_ADMIN)
                    ->after('routing_phase')
                    ->index();
            }

            if (! Schema::hasColumn('email_rules', 'owner_id')) {
                $table->foreignId('owner_id')
                    ->nullable()
                    ->after('rule_kind')
                    ->constrained('user_management')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasTable('email_rule_versions')) {
            return;
        }

        Schema::table('email_rule_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_rule_versions', 'rule_kind')) {
                $table->string('rule_kind', 40)
                    ->default(EmailRule::KIND_ADMIN)
                    ->after('routing_phase')
                    ->index();
            }

            if (! Schema::hasColumn('email_rule_versions', 'owner_id')) {
                $table->foreignId('owner_id')
                    ->nullable()
                    ->after('rule_kind')
                    ->constrained('user_management')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_rule_versions')) {
            Schema::table('email_rule_versions', function (Blueprint $table): void {
                if (Schema::hasColumn('email_rule_versions', 'owner_id')) {
                    $table->dropForeign(['owner_id']);
                    $table->dropColumn('owner_id');
                }

                if (Schema::hasColumn('email_rule_versions', 'rule_kind')) {
                    $table->dropColumn('rule_kind');
                }
            });
        }

        if (! Schema::hasTable('email_rules')) {
            return;
        }

        Schema::table('email_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('email_rules', 'owner_id')) {
                $table->dropForeign(['owner_id']);
                $table->dropColumn('owner_id');
            }

            if (Schema::hasColumn('email_rules', 'rule_kind')) {
                $table->dropColumn('rule_kind');
            }
        });
    }
};
