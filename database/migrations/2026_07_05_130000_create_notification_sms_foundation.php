<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_channel_id')->nullable()->constrained('notification_channels')->nullOnDelete();
            $table->foreignId('notification_sms_template_id')->nullable()->constrained('notification_sms_templates')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('contact_phone_id')->nullable()->constrained('contact_phones')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('provider', 50)->default('dry_run')->index();
            $table->string('status', 50)->index();
            $table->string('direction', 20)->default('outbound')->index();
            $table->string('sender_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('normalized_recipient_phone')->nullable()->index();
            $table->text('body')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['contact_id', 'created_at']);
        });

        DB::table('notification_channels')->updateOrInsert(
            ['name' => 'sms'],
            [
                'label' => 'SMS',
                'driver' => 'sms',
                'is_enabled' => false,
                'config' => json_encode([
                    'provider' => 'dry_run',
                    'sender_name' => 'Nexum',
                    'default_country_code' => '+47',
                ]),
                'secrets' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        DB::table('notification_sms_templates')->updateOrInsert(
            ['key' => 'sms_test'],
            [
                'name' => 'SMS test message',
                'body' => 'Test SMS from {{ company_name }} to {{ contact_name }}.',
                'variables' => json_encode(['company_name', 'contact_name']),
                'is_active' => true,
                'metadata' => json_encode(['created_from' => 'notification_sms_foundation_migration']),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_channels')->where('name', 'sms')->delete();

        Schema::dropIfExists('notification_sms_messages');
        Schema::dropIfExists('notification_sms_templates');
    }
};
