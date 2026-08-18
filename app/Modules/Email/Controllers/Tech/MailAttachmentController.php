<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\EmailPrivateStorage;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailAttachmentController extends Controller
{
    /**
     * Download one private attachment through the exact visible mailbox placement.
     *
     * Placement binding is intentional: message or Ticket access alone must not
     * expose Mail content after a mailbox grant is revoked or a placement hidden.
     */
    public function download(
        Request $request,
        EmailMailboxPlacement $placement,
        EmailAttachment $attachment,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        EmailCanonicalContentResolver $canonicalContent,
    ): StreamedResponse {
        $placement->loadMissing('account');
        $account = $placement->account;
        $actor = $request->user();

        abort_unless(
            $actor?->isActive()
                && ($actor->can('email.inbox_view') || $actor->can('email.break_glass_activate')),
            403,
        );

        $sourceMessage = $account
            ? EmailMessage::query()
                ->where('account_id', $account->id)
                ->whereKey($placement->email_message_id)
                ->first()
            : null;

        abort_unless(
            $account
                && $sourceMessage?->hasActiveProviderPlacement($placement)
                && (int) $attachment->message_id === (int) $sourceMessage->id,
            404,
        );

        try {
            $decision = $access->authorize(
                $request->user(),
                $account,
                ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD,
                'attachment',
                (int) $attachment->id,
            );
        } catch (AuthorizationException) {
            abort(404);
        }

        abort_unless(
            $decision->usesBreakGlass()
                || $mailboxAccess->canAccessAccount($request->user(), $account, MailboxAccess::VIEW),
            404,
        );

        // A full actual-file parity pass is still required in canonical mode, but the route-
        // bound source attachment remains the download identity. Metadata is not a unique part
        // key and selecting a canonical part by filename/checksum could swap duplicate parts.
        $canonicalContent->resolve($placement, $sourceMessage, verifyFiles: true);

        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $path = $this->safeStoredPath($disk, $attachment);

        abort_unless($path !== null, 404);

        return $disk->download($path, $this->downloadFilename($attachment, $path), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeStoredPath(FilesystemAdapter $disk, EmailAttachment $attachment): ?string
    {
        if (($attachment->disk ?: EmailPrivateStorage::DISK) !== EmailPrivateStorage::DISK) {
            return null;
        }

        $path = trim((string) $attachment->path);

        if ($path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || ! str_starts_with($path, 'email/attachments/')) {
            return null;
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || ! $disk->exists($path)) {
            return null;
        }

        $root = realpath($disk->path('email/attachments'));
        $absolutePath = realpath($disk->path($path));

        if ($root === false
            || $absolutePath === false
            || ! is_file($absolutePath)
            || ! str_starts_with($absolutePath, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    private function downloadFilename(EmailAttachment $attachment, string $path): string
    {
        $filename = basename(str_replace('\\', '/', trim((string) $attachment->filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        if ($filename === '') {
            $filename = basename($path);
        }

        return mb_substr($filename, 0, 180);
    }
}
