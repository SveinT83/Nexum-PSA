<?php

namespace App\Modules\System\Resources\Api\V1;

use App\Modules\System\Support\CompanyProfileRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/** Explicit resources never serialize settings paths, job payloads or raw exception text. */
final class SystemApiView
{
    public static function company(array $settings): array
    {
        return Arr::only($settings, array_keys(CompanyProfileRules::company()));
    }

    public static function branding(array $settings): array
    {
        $fields = array_diff(array_keys(CompanyProfileRules::branding()), [
            'logo', 'logo_light', 'logo_dark', 'remove_logo', 'remove_light_logo', 'remove_dark_logo',
        ]);
        $result = Arr::only($settings, $fields);
        $result['logos'] = [];
        foreach (['fallback' => 'logo', 'light' => 'logo_light', 'dark' => 'logo_dark'] as $slot => $prefix) {
            $path = $settings[$prefix.'_path'] ?? null;
            $resolved = $path ?: ($settings['logo_path'] ?? null);
            $digest = null;
            $size = null;
            if ($resolved && Storage::disk('public')->exists($resolved)) {
                $stream = Storage::disk('public')->readStream($resolved);
                if (is_resource($stream)) {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $stream);
                    $digest = hash_final($context);
                    fclose($stream);
                    $size = Storage::disk('public')->size($resolved);
                }
            }
            $result['logos'][$slot] = ['configured' => filled($path),
                'url' => $settings[$prefix.'_url'] ?? null, 'sha256' => $digest, 'bytes' => $size];
        }

        return $result;
    }
}
