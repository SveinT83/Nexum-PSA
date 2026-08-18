<?php

namespace App\Modules\Email\Controllers\Tech;

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

class MailRawSourceController extends Controller
{
    /**
     * Stream the exact stored RFC 822 snapshot only after current authorization and durable
     * break-glass audit have succeeded. Route-bound placement IDs never imply mailbox access.
     */
    public function show(
        Request $request,
        EmailMailboxPlacement $placement,
        MailboxAccessUseGuard $access,
        MailboxAccess $mailboxAccess,
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

        $message = $account
            ? EmailMessage::query()
                ->where('account_id', $account->id)
                ->whereKey($placement->email_message_id)
                ->first()
            : null;

        abort_unless(
            $account && $message?->hasActiveProviderPlacement($placement),
            404,
        );

        try {
            $decision = $access->authorize(
                $request->user(),
                $account,
                ResolveMailboxAccessDecision::RAW_SOURCE,
                'raw_source',
                (int) $placement->email_message_id,
            );
        } catch (AuthorizationException) {
            abort(404);
        }

        abort_unless(
            $decision->usesBreakGlass()
                || $mailboxAccess->canAccessAccount(
                    $request->user(),
                    $account,
                    MailboxAccess::VIEW,
                ),
            404,
        );

        $message = $canonicalContent->resolve($placement, $message, verifyFiles: true)->message;
        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $path = $message ? $this->safeRawPath($disk, (string) $message->raw_path) : null;

        abort_unless($path !== null, 404);

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'inline; filename="message-'.$message->id.'.eml"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeRawPath(FilesystemAdapter $disk, string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === ''
            || str_starts_with($path, '/')
            || ! str_starts_with($path, 'email/raw/')
            || str_contains($path, '/../')
            || str_ends_with($path, '/..')
            || ! $disk->exists($path)) {
            return null;
        }

        $root = realpath($disk->path('email/raw'));
        $absolutePath = realpath($disk->path($path));

        if ($root === false
            || $absolutePath === false
            || ! is_file($absolutePath)
            || ! str_starts_with($absolutePath, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
