<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->nullable()->after('category_id')->constrained('vendors')->nullOnDelete();
            $table->string('source')->default('nexum')->after('vendor_id')->index();
            $table->decimal('cost_price', 14, 4)->nullable()->after('price_ex_vat');
            $table->decimal('suggested_sale_price', 14, 4)->nullable()->after('cost_price');
            $table->string('price_currency', 3)->default('NOK')->after('suggested_sale_price');
            $table->string('price_mode')->default('manual')->after('price_currency');
            $table->decimal('price_markup_percent', 8, 4)->nullable()->after('price_mode');
            $table->boolean('manual_price_override')->default(true)->after('price_markup_percent');
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->boolean('allow_license_additions')->default(true)->after('allow_decrease_during_binding');
            $table->boolean('allow_license_increases')->default(true)->after('allow_license_additions');
            $table->boolean('allow_license_decreases')->default(false)->after('allow_license_increases');
            $table->boolean('allow_license_price_updates')->default(true)->after('allow_license_decreases');
        });

        Schema::table('contract_items', function (Blueprint $table): void {
            $table->string('source')->default('nexum')->after('service_id')->index();
            $table->string('provider_subscription_id')->nullable()->after('source')->index();
            $table->date('commitment_start_date')->nullable()->after('billing_interval');
            $table->date('commitment_end_date')->nullable()->after('commitment_start_date');
            $table->date('cancellation_deadline')->nullable()->after('commitment_end_date');
            $table->timestamp('billing_effective_at')->nullable()->after('cancellation_deadline');
            $table->json('licence_metadata')->nullable()->after('billing_effective_at');
        });

        Schema::create('cloudfactory_client_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->uuid('external_customer_id');
            $table->string('match_method')->nullable();
            $table->json('last_synced_snapshot')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_customer_id'], 'cf_client_external_unique');
            $table->unique(['integration_id', 'client_id'], 'cf_client_local_unique');
        });

        Schema::create('cloudfactory_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('external_product_id');
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->string('provider_family')->nullable()->index();
            $table->string('vendor_name')->nullable()->index();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('external_category_id')->nullable();
            $table->integer('recurrence_term')->nullable();
            $table->integer('billing_term')->nullable();
            $table->decimal('cost', 14, 4)->nullable();
            $table->decimal('msrp', 14, 4)->nullable();
            $table->string('currency', 3)->default('NOK');
            $table->string('price_mode')->nullable();
            $table->decimal('markup_percent', 8, 4)->nullable();
            $table->decimal('manual_sale_price', 14, 4)->nullable();
            $table->boolean('sell_enabled')->default(false)->index();
            $table->boolean('excluded')->default(false)->index();
            $table->unsignedInteger('active_subscription_count')->default(0);
            $table->boolean('deprecated')->default(false);
            $table->boolean('purchasable')->default(true);
            $table->json('provider_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_product_id'], 'cf_offer_external_unique');
            $table->index(['integration_id', 'provider_family', 'sell_enabled'], 'cf_offer_provider_sell_idx');
        });

        Schema::create('cloudfactory_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignUuid('client_link_id')->nullable()->constrained('cloudfactory_client_links')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('offer_id')->nullable()->constrained('cloudfactory_offers')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->string('provider_family')->index();
            $table->string('external_subscription_id');
            $table->string('name')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('used_quantity')->nullable();
            $table->string('status')->index();
            $table->boolean('auto_renew')->nullable();
            $table->date('commitment_start_date')->nullable();
            $table->date('commitment_end_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->date('cancellation_deadline')->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('unit_sale_price', 14, 4)->nullable();
            $table->string('currency', 3)->default('NOK');
            $table->string('origin')->default('cloudfactory');
            $table->string('billing_state')->default('unlinked')->index();
            $table->json('allowed_actions')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'provider_family', 'external_subscription_id'],
                'cf_subscription_external_unique'
            );
            $table->index(['client_id', 'provider_family', 'status'], 'cf_subscription_client_status_idx');
        });

        Schema::create('cloudfactory_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('cloudfactory_subscriptions')->nullOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_family')->nullable();
            $table->string('action')->index();
            $table->string('status')->default('pending')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('external_operation_id')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status', 'created_at'], 'cf_operation_status_idx');
        });

        Schema::create('cloudfactory_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('kind')->index();
            $table->string('status')->default('running')->index();
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_conflicted')->default(0);
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cloudfactory_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('conflict_type')->index();
            $table->string('external_id')->nullable()->index();
            $table->json('fields')->nullable();
            $table->json('candidate_ids')->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('status')->default('open')->index();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status', 'conflict_type'], 'cf_conflict_open_idx');
        });

        Schema::create('cloudfactory_licence_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('cloudfactory_subscriptions')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->foreignUuid('operation_id')->nullable()->constrained('cloudfactory_operations')->nullOnDelete();
            $table->string('change_type')->index();
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->decimal('old_unit_price', 14, 4)->nullable();
            $table->decimal('new_unit_price', 14, 4)->nullable();
            $table->date('commitment_end_date')->nullable();
            $table->timestamp('effective_at');
            $table->string('origin');
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('cloudfactory_billing_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('subscription_id')->constrained('cloudfactory_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('amendment_id')->nullable()->constrained('cloudfactory_licence_amendments')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price_ex_vat', 14, 4);
            $table->string('currency', 3)->default('NOK');
            $table->string('status')->default('confirmed')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['subscription_id', 'period_start', 'period_end'],
                'cf_billing_subscription_period_unique'
            );
        });

        Schema::create('cloudfactory_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event')->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'cf_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudfactory_audit_events');
        Schema::dropIfExists('cloudfactory_billing_periods');
        Schema::dropIfExists('cloudfactory_licence_amendments');
        Schema::dropIfExists('cloudfactory_conflicts');
        Schema::dropIfExists('cloudfactory_sync_runs');
        Schema::dropIfExists('cloudfactory_operations');
        Schema::dropIfExists('cloudfactory_subscriptions');
        Schema::dropIfExists('cloudfactory_offers');
        Schema::dropIfExists('cloudfactory_client_links');

        Schema::table('contract_items', function (Blueprint $table): void {
            $table->dropColumn([
                'source',
                'provider_subscription_id',
                'commitment_start_date',
                'commitment_end_date',
                'cancellation_deadline',
                'billing_effective_at',
                'licence_metadata',
            ]);
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn([
                'allow_license_additions',
                'allow_license_increases',
                'allow_license_decreases',
                'allow_license_price_updates',
            ]);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn([
                'vendor_id',
                'source',
                'cost_price',
                'suggested_sale_price',
                'price_currency',
                'price_mode',
                'price_markup_percent',
                'manual_price_override',
            ]);
        });
    }
};
