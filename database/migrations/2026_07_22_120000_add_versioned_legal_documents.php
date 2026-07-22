<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->string('origin', 32)->default('nexum')->after('type')->index();
            $table->foreignUuid('source_integration_id')->nullable()->after('origin')
                ->constrained('integrations')->nullOnDelete();
            $table->string('external_document_id', 191)->nullable()->after('source_integration_id');
            $table->string('issuer')->nullable()->after('external_document_id');
            $table->text('source_url')->nullable()->after('issuer');
            $table->boolean('managed_externally')->default(false)->after('source_url')->index();
            $table->string('sync_status', 32)->default('current')->after('managed_externally');
            $table->timestamp('last_checked_at')->nullable()->after('sync_status');
            $table->json('metadata')->nullable()->after('last_checked_at');

            $table->unique(
                ['source_integration_id', 'external_document_id'],
                'terms_integration_external_unique'
            );
        });

        Schema::create('term_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('issuer')->nullable();
            $table->string('version_label')->nullable();
            $table->longText('content')->nullable();
            $table->text('source_url')->nullable();
            $table->char('checksum', 64);
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('provider_published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'checksum']);
        });

        Schema::table('terms', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->after('metadata')
                ->constrained('term_versions')->nullOnDelete();
        });

        DB::table('terms')
            ->select(['id', 'name', 'type', 'content', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $term): void {
                $content = (string) ($term->content ?? '');
                $checksum = hash('sha256', $content);
                $versionId = DB::table('term_versions')->insertGetId([
                    'term_id' => $term->id,
                    'version_label' => '1',
                    'name' => $term->name,
                    'type' => $term->type,
                    'content' => $content,
                    'checksum' => $checksum,
                    'first_seen_at' => $term->created_at ?? now(),
                    'last_seen_at' => $term->updated_at ?? now(),
                    'created_at' => $term->created_at ?? now(),
                    'updated_at' => $term->updated_at ?? now(),
                ]);

                DB::table('terms')->where('id', $term->id)->update([
                    'current_version_id' => $versionId,
                ]);
            });

        Schema::create('cloudfactory_offer_term', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('offer_id')->constrained('cloudfactory_offers')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'term_id']);
        });

        Schema::create('contract_term_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('term_version_id')->nullable()->constrained('term_versions')->nullOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('origin', 32);
            $table->string('issuer')->nullable();
            $table->string('version_label')->nullable();
            $table->longText('content')->nullable();
            $table->text('source_url')->nullable();
            $table->char('checksum', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['contract_id', 'contract_item_id', 'term_id'],
                'contract_term_item_unique'
            );
        });

        Schema::create('legal_acceptance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('cloudfactory_offer_id')->nullable()
                ->constrained('cloudfactory_offers')->nullOnDelete();
            $table->foreignUuid('cloudfactory_subscription_id')->nullable()
                ->constrained('cloudfactory_subscriptions')->nullOnDelete();
            $table->foreignUuid('cloudfactory_operation_id')->nullable()
                ->constrained('cloudfactory_operations')->nullOnDelete();
            $table->foreignId('customer_portal_account_id')->nullable()
                ->constrained('customer_portal_accounts')->nullOnDelete();
            $table->foreignId('customer_portal_membership_id')->nullable()
                ->constrained('customer_portal_memberships')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('status', 32)->default('recorded')->index();
            $table->string('confirmed_by_name');
            $table->json('term_version_ids');
            $table->json('evidence');
            $table->char('evidence_hash', 64);
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('previous_quantity')->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('confirmed_at');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptance_events');
        Schema::dropIfExists('contract_term_snapshots');
        Schema::dropIfExists('cloudfactory_offer_term');

        Schema::table('terms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('term_versions');

        Schema::table('terms', function (Blueprint $table): void {
            $table->dropUnique('terms_integration_external_unique');
            $table->dropConstrainedForeignId('source_integration_id');
            $table->dropColumn([
                'origin',
                'external_document_id',
                'issuer',
                'source_url',
                'managed_externally',
                'sync_status',
                'last_checked_at',
                'metadata',
            ]);
        });
    }
};
