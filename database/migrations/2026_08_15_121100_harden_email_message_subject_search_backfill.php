<?php

use App\Modules\Email\Support\EmailSubjectPresenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_messages')
            || ! Schema::hasColumn('email_messages', 'subject_search')) {
            return;
        }

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

                    $update = DB::table('email_messages')
                        ->where('id', $message->id);

                    $this->whereOriginalValue($update, 'subject', $message->subject);
                    $this->whereOriginalValue($update, 'subject_search', $message->subject_search);

                    // A zero-row update means a concurrent writer changed the
                    // raw subject or its projection after this chunk was read.
                    // Unrelated concurrent state/timestamp writes remain intact.
                    $update->update(['subject_search' => $subjectSearch]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Forward-only data repair: a previous projection cannot be restored
        // safely without changing the identity-bearing raw subject.
    }

    private function whereOriginalValue(Builder $query, string $column, mixed $value): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        $query->where($column, '=', $value);
    }
};
