<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailRawSourceController extends Controller
{
    public function show(
        Request $request,
        EmailMailboxPlacement $placement,
        MailboxAccessUseGuard $access,
        MailboxAccess $mailboxAccess,
        EmailCanonicalContentResolver $canonicalContent,
    ): StreamedResponse {
        return app(\App\Modules\Email\Actions\StreamEmailMailboxRawSource::class)->handle($request, $placement, $access, $mailboxAccess, $canonicalContent);
    }
}
