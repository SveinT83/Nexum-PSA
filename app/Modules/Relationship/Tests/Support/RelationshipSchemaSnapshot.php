<?php

namespace App\Modules\Relationship\Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Isolated generation avoids races with other contributors regenerating shared Dev Swagger. */
class RelationshipSchemaSnapshot
{
    private static ?array $document = null;

    public static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }
        $directory = sys_get_temp_dir().'/nexum-relationship-schema-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $original = config('l5-swagger.defaults.paths.docs');
        try {
            config(['l5-swagger.defaults.paths.docs' => $directory]);
            if (Artisan::call('l5-swagger:generate') !== 0) {
                throw new \RuntimeException('Relationship schema generation failed.');
            }
            self::$document = json_decode(File::get($directory.'/api-docs.json'), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            config(['l5-swagger.defaults.paths.docs' => $original]);
            File::deleteDirectory($directory);
        }

        return self::$document;
    }
}
