<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Outbound email templates
    |--------------------------------------------------------------------------
    |
    | Stores reusable subject/body templates for outbound email flows. The
    | scope column lets Ticket, System, and future modules share one template
    | engine while still keeping defaults and UI filtering simple.
    |
    */
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 50)->index();
            $table->string('key', 100);
            $table->string('name');
            $table->string('subject');
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
