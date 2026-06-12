<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_list_id');
                $table->foreignId('email_account_id')->nullable();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status', 50)->default('draft')->index();
                $table->timestamp('starts_at')->nullable()->index();
                $table->unsignedInteger('batch_size')->nullable();
                $table->unsignedInteger('send_interval_minutes')->nullable();
                $table->boolean('track_opens')->default(true);
                $table->boolean('track_clicks')->default(true);
                $table->foreignId('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('created_by')->nullable();
                $table->foreignId('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('marketing_list_id', 'mc_list_fk')->references('id')->on('marketing_lists')->cascadeOnDelete();
                $table->foreign('email_account_id', 'mc_email_account_fk')->references('id')->on('email_accounts')->nullOnDelete();
                $table->foreign('approved_by', 'mc_approved_by_fk')->references('id')->on('user_management')->nullOnDelete();
                $table->foreign('created_by', 'mc_created_by_fk')->references('id')->on('user_management')->nullOnDelete();
                $table->foreign('updated_by', 'mc_updated_by_fk')->references('id')->on('user_management')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('marketing_campaign_emails')) {
            Schema::create('marketing_campaign_emails', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id');
                $table->foreignId('email_template_id');
                $table->unsignedInteger('sequence_order')->default(1);
                $table->string('status', 50)->default('active')->index();
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->unsignedInteger('delay_minutes')->default(0);
                $table->string('subject_override')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['marketing_campaign_id', 'sequence_order'], 'marketing_campaign_emails_order_unique');
                $table->foreign('marketing_campaign_id', 'mce_campaign_fk')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
                $table->foreign('email_template_id', 'mce_template_fk')->references('id')->on('email_templates')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('marketing_campaign_recipients')) {
            Schema::create('marketing_campaign_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id');
                $table->foreignId('marketing_campaign_email_id');
                $table->foreignId('marketing_list_member_id')->nullable();
                $table->foreignId('contact_id')->nullable();
                $table->foreignId('client_user_id')->nullable();
                $table->foreignId('client_id')->nullable();
                $table->string('email');
                $table->string('name')->nullable();
                $table->string('status', 50)->default('pending')->index();
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamp('sent_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('rfc_message_id')->nullable();
                $table->text('last_error')->nullable();
                $table->string('tracking_token', 64)->unique();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['marketing_campaign_email_id', 'marketing_list_member_id'], 'marketing_campaign_recipient_member_unique');
                $table->index(['marketing_campaign_id', 'status', 'due_at'], 'marketing_campaign_recipient_due_index');
                $table->foreign('marketing_campaign_id', 'mcr_campaign_fk')->references('id')->on('marketing_campaigns')->cascadeOnDelete();
                $table->foreign('marketing_campaign_email_id', 'mcr_email_fk')->references('id')->on('marketing_campaign_emails')->cascadeOnDelete();
                $table->foreign('marketing_list_member_id', 'mcr_member_fk')->references('id')->on('marketing_list_members')->nullOnDelete();
                $table->foreign('contact_id', 'mcr_contact_fk')->references('id')->on('contacts')->nullOnDelete();
                $table->foreign('client_user_id', 'mcr_client_user_fk')->references('id')->on('client_users')->nullOnDelete();
                $table->foreign('client_id', 'mcr_client_fk')->references('id')->on('clients')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('marketing_campaign_events')) {
            Schema::create('marketing_campaign_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id')->nullable();
                $table->foreignId('marketing_campaign_email_id')->nullable();
                $table->foreignId('marketing_campaign_recipient_id')->nullable();
                $table->foreignId('contact_id')->nullable();
                $table->foreignId('client_id')->nullable();
                $table->string('type', 50)->index();
                $table->text('url')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->foreign('marketing_campaign_id', 'mcev_campaign_fk')->references('id')->on('marketing_campaigns')->nullOnDelete();
                $table->foreign('marketing_campaign_email_id', 'mcev_email_fk')->references('id')->on('marketing_campaign_emails')->nullOnDelete();
                $table->foreign('marketing_campaign_recipient_id', 'mcev_recipient_fk')->references('id')->on('marketing_campaign_recipients')->nullOnDelete();
                $table->foreign('contact_id', 'mcev_contact_fk')->references('id')->on('contacts')->nullOnDelete();
                $table->foreign('client_id', 'mcev_client_fk')->references('id')->on('clients')->nullOnDelete();
            });
        }

        $this->ensureForeign('marketing_campaigns', 'marketing_list_id', 'mc_list_fk', 'marketing_lists', 'cascade');
        $this->ensureForeign('marketing_campaigns', 'email_account_id', 'mc_email_account_fk', 'email_accounts', 'null');
        $this->ensureForeign('marketing_campaigns', 'approved_by', 'mc_approved_by_fk', 'user_management', 'null');
        $this->ensureForeign('marketing_campaigns', 'created_by', 'mc_created_by_fk', 'user_management', 'null');
        $this->ensureForeign('marketing_campaigns', 'updated_by', 'mc_updated_by_fk', 'user_management', 'null');
        $this->ensureForeign('marketing_campaign_emails', 'marketing_campaign_id', 'mce_campaign_fk', 'marketing_campaigns', 'cascade');
        $this->ensureForeign('marketing_campaign_emails', 'email_template_id', 'mce_template_fk', 'email_templates', 'restrict');
        $this->ensureForeign('marketing_campaign_recipients', 'marketing_campaign_id', 'mcr_campaign_fk', 'marketing_campaigns', 'cascade');
        $this->ensureForeign('marketing_campaign_recipients', 'marketing_campaign_email_id', 'mcr_email_fk', 'marketing_campaign_emails', 'cascade');
        $this->ensureForeign('marketing_campaign_recipients', 'marketing_list_member_id', 'mcr_member_fk', 'marketing_list_members', 'null');
        $this->ensureForeign('marketing_campaign_recipients', 'contact_id', 'mcr_contact_fk', 'contacts', 'null');
        $this->ensureForeign('marketing_campaign_recipients', 'client_user_id', 'mcr_client_user_fk', 'client_users', 'null');
        $this->ensureForeign('marketing_campaign_recipients', 'client_id', 'mcr_client_fk', 'clients', 'null');
        $this->ensureForeign('marketing_campaign_events', 'marketing_campaign_id', 'mcev_campaign_fk', 'marketing_campaigns', 'null');
        $this->ensureForeign('marketing_campaign_events', 'marketing_campaign_email_id', 'mcev_email_fk', 'marketing_campaign_emails', 'null');
        $this->ensureForeign('marketing_campaign_events', 'marketing_campaign_recipient_id', 'mcev_recipient_fk', 'marketing_campaign_recipients', 'null');
        $this->ensureForeign('marketing_campaign_events', 'contact_id', 'mcev_contact_fk', 'contacts', 'null');
        $this->ensureForeign('marketing_campaign_events', 'client_id', 'mcev_client_fk', 'clients', 'null');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_events');
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('marketing_campaign_emails');
        Schema::dropIfExists('marketing_campaigns');
    }

    private function ensureForeign(string $table, string $column, string $name, string $foreignTable, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $this->foreignExists($table, $column, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $name, $foreignTable, $onDelete): void {
            $foreign = $table->foreign($column, $name)->references('id')->on($foreignTable);

            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'null' => $foreign->nullOnDelete(),
                'restrict' => $foreign->restrictOnDelete(),
                default => null,
            };
        });
    }

    private function foreignExists(string $table, string $column, string $name): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->where(function ($query) use ($column, $name): void {
                $query->where('CONSTRAINT_NAME', $name)
                    ->orWhere('COLUMN_NAME', $column);
            })
            ->exists();
    }
};
