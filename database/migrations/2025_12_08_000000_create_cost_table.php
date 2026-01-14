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

            // unitId: The id of the unit
            $table->integer('unitId');

            // recurrence: none, month, year, quarter
            $table->enum('recurrence', ['none', 'month', 'year', 'quarter']);

            //Vendor
            $table->foreignId('vendor_id')->constrained('vendors');

            // Note (text)
            $table->text('note');

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

