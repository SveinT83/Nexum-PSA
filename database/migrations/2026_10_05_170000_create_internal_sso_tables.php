<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add isolated SSO records without rewriting local credentials or account authority. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sso_providers', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('issuer', 500);
            $table->string('client_id');
            $table->text('client_secret');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('user_management')->restrictOnDelete();
            $table->string('issuer', 500);
            $table->string('subject', 255);
            // Hash exact bytes to avoid case-insensitive database identity collisions.
            $table->char('identity_key', 64)->unique();
            $table->timestamps();
        });
        Schema::create('user_sso_attempts', function (Blueprint $table) {
            $table->char('state_hash', 64)->primary();
            $table->char('binding_hash', 64);
            $table->text('payload');
            $table->dateTime('expires_at')->index();
        });
        Schema::create('user_sso_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('user_management')->restrictOnDelete();
            $table->char('identity_key', 64)->index();
            $table->char('sid_hash', 64)->nullable()->index();
            $table->char('security_fingerprint', 64);
            $table->unsignedInteger('provider_revision');
            $table->dateTime('expires_at')->index();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');
        });
        Schema::create('user_sso_logout_receipts', function (Blueprint $table) {
            $table->char('token_key', 64)->primary();
            $table->dateTime('expires_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['user_sso_logout_receipts', 'user_sso_sessions', 'user_sso_attempts',
            'user_external_identities', 'user_sso_providers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
