<?php

use App\Modules\Email\Support\EmailSubjectPresenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_messages', 'subject_search')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                // This is a derived search projection only. The provider subject
                // remains the identity-bearing source used by rules and routing.
                $table->string('subject_search', 512)->nullable()->after('subject');
            });
        }

        $this->backfillSubjectSearch();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('email_messages', 'subject_search')) {
            return;
        }

        Schema::table('email_messages', function (Blueprint $table): void {
            $table->dropColumn('subject_search');
        });
    }

    /**
     * Backfill only the derived projection. Query-builder updates deliberately
     * avoid Eloquent timestamps and every identity-bearing message attribute.
     */
    private function backfillSubjectSearch(): void
    {
        DB::table('email_messages')
            ->select(['id', 'subject', 'subject_search'])
            ->orderBy('id')
            ->chunkById(500, function ($messages): void {
                foreach ($messages as $message) {
                    $rawSubject = $message->subject === null
                        ? null
                        : (string) $message->subject;
                    $subjectSearch = EmailSubjectPresenter::present($rawSubject);

                    if ($message->subject_search === $subjectSearch) {
                        continue;
                    }

                    DB::table('email_messages')
                        ->where('id', $message->id)
                        ->update(['subject_search' => $subjectSearch]);
                }
            }, 'id');
    }
};
