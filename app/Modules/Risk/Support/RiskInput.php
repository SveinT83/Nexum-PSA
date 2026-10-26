<?php

namespace App\Modules\Risk\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RiskInput
{
    public static function only(Request $r, array $allowed): void
    {
        $unknown = array_diff(array_keys($r->all()), $allowed);
        if ($unknown) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields: '.implode(', ', $unknown).'.']);
        }
    }
}
