<?php

namespace App\Modules\Ticket\Support;

use Illuminate\Http\UploadedFile;

/** Stable file identity across multipart retries; never hash temporary upload paths. */
final class TicketMessageFingerprint
{
    public static function attachments(array $files): array
    {
        return array_map(fn (UploadedFile $file) => [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ], $files);
    }
}
