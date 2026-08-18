<?php

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->string('account_kind', 20)
                ->default(EmailAccount::KIND_SHARED)
                ->after('description')
                ->index();
            $table->foreignId('owner_id')
                ->nullable()
                ->after('account_kind')
                ->constrained('user_management')
                ->nullOnDelete();
            $table->boolean('ticket_ingress_enabled')
                ->default(true)
                ->after('defaults_for')
                ->index();
        });

        Schema::create('email_account_user_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id')
                ->constrained('email_accounts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_organize')->default(false);
            $table->boolean('can_send')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'user_id'], 'email_account_user_grants_unique');
            $table->index(['user_id', 'can_view'], 'email_account_user_grants_view_index');
            $table->index(['user_id', 'can_organize'], 'email_account_user_grants_organize_index');
            $table->index(['user_id', 'can_send'], 'email_account_user_grants_send_index');
        });

        Schema::create('email_rule_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_rule_id')
                ->constrained('email_rules')
                ->cascadeOnDelete();
            $table->foreignId('email_account_id')
                ->constrained('email_accounts')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['email_rule_id', 'email_account_id'], 'email_rule_accounts_unique');
        });

        $this->backfillExistingMailboxAccess();
        $this->backfillExistingRuleAccounts();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_rule_accounts');
        Schema::dropIfExists('email_account_user_grants');

        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->dropForeign(['owner_id']);
            $table->dropColumn([
                'account_kind',
                'owner_id',
                'ticket_ingress_enabled',
            ]);
        });
    }

    private function backfillExistingMailboxAccess(): void
    {
        $now = now();
        $accounts = DB::table('email_accounts')->pluck('id');
        $users = DB::table((new User)->getTable())
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($accounts as $accountId) {
            foreach ($users as $userId) {
                DB::table('email_account_user_grants')->insert([
                    'email_account_id' => $accountId,
                    'user_id' => $userId,
                    'can_view' => true,
                    'can_organize' => true,
                    'can_send' => false,
                    'granted_by' => null,
                    'granted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function backfillExistingRuleAccounts(): void
    {
        $now = now();
        $accounts = DB::table('email_accounts')
            ->where('account_kind', '!=', EmailAccount::KIND_PERSONAL)
            ->where('ticket_ingress_enabled', true)
            ->pluck('id');

        if ($accounts->isEmpty()) {
            return;
        }

        foreach (DB::table('email_rules')->pluck('id') as $ruleId) {
            foreach ($accounts as $accountId) {
                DB::table('email_rule_accounts')->insert([
                    'email_rule_id' => $ruleId,
                    'email_account_id' => $accountId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
