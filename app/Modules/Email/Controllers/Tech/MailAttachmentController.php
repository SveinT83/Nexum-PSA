<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailAttachmentController extends Controller
{
    public function download(
        Request $request,
        EmailMailboxPlacement $placement,
        EmailAttachment $attachment,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        EmailCanonicalContentResolver $canonicalContent,
    ): StreamedResponse {
        return app(\App\Modules\Email\Actions\StreamEmailMailboxAttachment::class)->handle($request, $placement, $attachment, $mailboxAccess, $access, $canonicalContent);
    }
}
