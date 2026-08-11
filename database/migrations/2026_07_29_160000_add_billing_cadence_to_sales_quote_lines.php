<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_quote_lines', 'billing_cadence')) {
            Schema::table('sales_quote_lines', function (Blueprint $table): void {
                $table->string('billing_cadence')->default('one_time')->after('downstream_type')->index();
            });

            DB::table('sales_quote_lines')
                ->where(function ($query): void {
                    $query->where('downstream_type', 'recurring_contract')
                        ->orWhere('section', 'monthly_services');
                })
                ->update(['billing_cadence' => 'monthly']);
        }

        $oldHtml = '<p>Hello {{ contact_name }},</p><p>Your quote is ready:</p><p><a href="{{ quote_url }}">View quote</a></p><p>Total ex VAT: {{ total_ex_vat }}<br>Total inc VAT: {{ total_inc_vat }}<br>Expires: {{ expires_at }}</p><p>Regards,<br>{{ seller_name }}</p>';
        $oldText = "Hello {{ contact_name }},\n\nYour quote is ready:\n{{ quote_url }}\n\nTotal ex VAT: {{ total_ex_vat }}\nTotal inc VAT: {{ total_inc_vat }}\nExpires: {{ expires_at }}\n\nRegards,\n{{ seller_name }}";

        $newHtml = '<p>Hello {{ contact_name }},</p><p>Your quote is ready:</p>{{ quote_customer_copy_html }}<p><strong>Price summary</strong><br>{{ quote_summary_html }}</p><p><a href="{{ quote_url }}">View quote</a></p><p>Expires: {{ expires_at }}</p><p>Regards,<br>{{ seller_name }}</p>';
        $newText = "Hello {{ contact_name }},\n\nYour quote is ready:\n\n{{ quote_customer_copy_text }}\n\nPrice summary\n{{ quote_summary_text }}\n\n{{ quote_url }}\n\nExpires: {{ expires_at }}\n\nRegards,\n{{ seller_name }}";

        if (Schema::hasTable('email_templates')) {
            DB::table('email_templates')
                ->where('scope', 'sales')
                ->where('key', 'sales_quote_send')
                ->where('is_default', true)
                ->where('body_html', $oldHtml)
                ->where('body_text', $oldText)
                ->update([
                    'body_html' => $newHtml,
                    'body_text' => $newText,
                    'variables' => json_encode([
                        'opportunity_key',
                        'opportunity_title',
                        'client_name',
                        'contact_name',
                        'quote_key',
                        'quote_url',
                        'quote_summary_html',
                        'quote_summary_text',
                        'quote_customer_copy_html',
                        'quote_customer_copy_text',
                        'expires_at',
                        'seller_name',
                    ]),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_quote_lines', 'billing_cadence')) {
            Schema::table('sales_quote_lines', function (Blueprint $table): void {
                $table->dropColumn('billing_cadence');
            });
        }
    }
};
