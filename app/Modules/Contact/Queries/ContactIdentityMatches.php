<?php

namespace App\Modules\Contact\Queries;

use App\Modules\Contact\Models\Contact;
use Illuminate\Support\Collection;

/** One normalized identity comparison for form/API discovery and canonical persistence. */
class ContactIdentityMatches
{
    public static function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '0047')) {
            return substr($digits, 4);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '47')) {
            return substr($digits, 2);
        }

        return $digits;
    }

    public function handle(?string $email, ?string $phone, ?int $exclude = null): Collection
    {
        $email = mb_strtolower(trim((string) $email));
        $phone = self::phone($phone);
        if ($email === '' && $phone === '') {
            return collect();
        }
        $phones = [$phone];
        if (strlen($phone) === 8) {
            $phones = [$phone, '47'.$phone, '0047'.$phone];
        }

        return Contact::query()->with(['emails', 'phones', 'relations'])
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude))
            ->where(function ($q) use ($email, $phone, $phones): void {
                if ($email !== '') {
                    $q->orWhereHas('emails', fn ($e) => $e->whereRaw('LOWER(email) = ?', [$email]));
                }
                if ($phone !== '') {
                    $q->orWhereHas('phones', fn ($p) => $p->whereIn(\Illuminate\Support\Facades\DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '')"), $phones));
                }
            })->orderBy('id')->get();
    }
}
