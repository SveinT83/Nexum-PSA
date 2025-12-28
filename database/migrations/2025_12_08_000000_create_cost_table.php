<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('costs', function (Blueprint $table) {
            $table->id();

            // name (string)
            $table->string('name');

            // cost:
            $table->decimal('cost', 12, 2);

            // unit: client, user, site, asset, other
            $table->enum('unit', ['client', 'user', 'site', 'asset', 'other']);

            // recurrence: none, month, year, quarter
            $table->enum('recurrence', ['none', 'month', 'year', 'quarter']);

            //Policy / flags
            //created_by_user_id (foreign id -> users)
            $table->foreignId('created_by_user_id')->constrained('users');

            //updated_by_user_id (foreign id -> users nullable)
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');

            //Timestamp
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('costs');
    }
};

