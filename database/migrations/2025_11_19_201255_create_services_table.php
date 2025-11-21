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
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // sku (string unique)
            $table->string('sku')->unique();

            // name (string)
            $table->string('name');

            // status (string default 'Active')
            $table->string('status')->default('Active');

            // icon (string nullable)
            $table->string('icon')->nullable();

            //Que addon: availability_addon_of_service_id (foreign id nullable -> services.id)
            $table->foreignId('availability_addon_of_service_id')->nullable()->constrained('services')->onDelete('set null');
            
            // availability_audience (enum: all, business, private)
            $table->enum('availability_audience', ['all', 'business', 'private'])->default('all');
            
            // orderable: Can client order this? Boolean true/false.
            $table->boolean('orderable')->default(true);
            
            // taxable: number in %
            $table->decimal('taxable', 5, 2)->default(0);
            
            // setup_fee (decimal(12,2) nullable)
            $table->decimal('setup_fee', 12, 2)->nullable();
            
            // billing_cycle (enum: monthly, yearly, one_time)
            $table->enum('billing_cycle', ['monthly', 'yearly', 'one_time'])->default('monthly');
            
            //Price excluding tax. Billing interval: monthly, yearly, one time.
             $table->decimal('price_including_tax', 12, 2)->default(0);
            
             //price_ex_vat (decimal(12,2))
            $table->decimal('price_ex_vat', 12, 2)->default(0);
            
            //one_time_fee (decimal(12,2) nullable)
            $table->decimal('one_time_fee', 12, 2)->nullable();
            
            //one_time_fee_recurrence (enum: none, yearly, every_x_years, every_x_months nullable)
            $table->enum('one_time_fee_recurrence', ['none', 'yearly', 'every_x_years', 'every_x_months'])->nullable();
            
            //recurrence_value_x (integer nullable) // required when recurrence needs X
            $table->integer('recurrence_value_x')->nullable();
            
            //default_discount_value (decimal(12,2) nullable)
            $table->decimal('default_discount_value', 12, 2)->nullable();
           
            //default_discount_type (enum: amount, percent nullable)
            $table->enum('default_discount_type', ['amount', 'percent'])->nullable();


            //Timebank..
            //Timebank enabled (boolean)
            $table->boolean('timebank_enabled')->default(false);

            //Timebank minutes (decimal(12,2) nullable)
            $table->decimal('timebank_minutes', 12, 2)->nullable();

            //Timebank interval (enum: monthly, yearly, one_time)
            $table->enum('timebank_interval', ['monthly', 'yearly', 'one_time'])->nullable();
            
            //Description and terms
            //short_description (text nullable)
            $table->text('short_description')->nullable();

            //long_description (text nullable)
            $table->text('long_description')->nullable();

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
        Schema::dropIfExists('services');
    }
};
