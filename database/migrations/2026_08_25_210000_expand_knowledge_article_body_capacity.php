<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TEXT_MAX_BYTES = 65_535;

    /**
     * Expand Knowledge article bodies so BookStack pages are preserved without truncation.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->mediumText('body_markdown')->change();
            $table->mediumText('body_html')->nullable()->change();
        });
    }

    /**
     * Refuse a destructive shrink when MEDIUMTEXT-sized content already exists.
     */
    public function down(): void
    {
        if ($this->hasOversizedArticleBody()) {
            throw new \RuntimeException(
                'Refusing to shrink Knowledge article bodies to TEXT while content exceeds 65,535 bytes.'
            );
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->text('body_markdown')->change();
            $table->text('body_html')->nullable()->change();
        });
    }

    /**
     * Measure bytes rather than characters so multi-byte UTF-8 content is protected.
     */
    private function hasOversizedArticleBody(): bool
    {
        $driver = DB::connection()->getDriverName();

        $markdownLength = match ($driver) {
            'sqlite' => 'LENGTH(CAST(body_markdown AS BLOB))',
            'mysql', 'pgsql' => 'OCTET_LENGTH(body_markdown)',
            default => throw new \RuntimeException(
                "Knowledge article body rollback preflight is unsupported for database driver [{$driver}]."
            ),
        };
        $htmlLength = match ($driver) {
            'sqlite' => 'LENGTH(CAST(body_html AS BLOB))',
            'mysql', 'pgsql' => 'OCTET_LENGTH(body_html)',
            default => throw new \RuntimeException(
                "Knowledge article body rollback preflight is unsupported for database driver [{$driver}]."
            ),
        };

        return DB::table('articles')
            ->whereRaw($markdownLength.' > ?', [self::TEXT_MAX_BYTES])
            ->orWhereRaw($htmlLength.' > ?', [self::TEXT_MAX_BYTES])
            ->exists();
    }
};
