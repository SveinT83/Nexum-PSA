<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Actions\MarkEmailAsSpam;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessDecision;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Notification\Actions\MarkNotificationsReadBySource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboxController extends Controller
{
    /**
     * Display unrouted email messages (ticket_id null) with simple search & pagination.
     */
    public function index(
        Request $request,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
    ) {
        $term = trim((string) $request->get('q'));
        $operation = $term !== ''
            ? ResolveMailboxAccessDecision::SEARCH
            : ResolveMailboxAccessDecision::CONTENT_VIEW;
        $accounts = $mailboxAccess->scopeContentAccounts(
            EmailAccount::query()->where('is_active', true)->orderBy('address'),
            $request->user(),
            $operation,
        )->get();
        abort_unless(
            $request->user()?->isActive()
                && ($request->user()->can('email.inbox_view') || $accounts->isNotEmpty()),
            403,
        );
        $listAccounts = $request->filled('account_id')
            ? $accounts->where('id', $request->integer('account_id'))->values()
            : $accounts;
        $authorizedAccountIds = $this->authorizedAccountIds(
            $request,
            $listAccounts,
            $mailboxAccess,
            $access,
            $operation,
        );

        $query = $mailboxAccess->scopeContentMessages(
            EmailMessage::query()->with('account')->providerInbox()->whereNull('ticket_id'),
            $request->user(),
            $operation,
        )->whereIn('account_id', $authorizedAccountIds);

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        // Use the same raw/decoded subject, sender and body search as Mail/API.
        if ($term !== '') {
            $query->searchText($term);
        }

        // Sort newest first by received_at fallback created_at
        $messages = $query->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('email::Tech.index', [
            'messages' => $messages,
            'search' => $request->get('q'),
            'accounts' => $accounts,
            'selectedAccountId' => $request->integer('account_id') ?: null,
        ]);
    }

    /**
     * Manually trigger immediate polling for all active email accounts.
     * Dispatches FetchImapAccount jobs asynchronously; returns back to inbox with a flash.
     */
    public function poll(Request $request, MailboxAccess $mailboxAccess)
    {
        try {
            $settings = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
                ->get()->pluck('value', 'name')->toArray();
            $batchSize = (int) ($settings['batch_size'] ?? 20);

            $accounts = $mailboxAccess->scopeAccounts(
                EmailAccount::query()->where('is_active', true),
                $request->user(),
                MailboxAccess::ORGANIZE,
            )->get();

            $dispatched = 0;
            foreach ($accounts as $account) {
                FetchImapAccount::dispatch($account->id, $batchSize);
                $dispatched++;
            }
        } catch (\Throwable $exception) {
            Log::error('Manual inbox polling could not be queued.', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return redirect()->route('tech.inbox.index')
                ->with('warning', 'Inbox check could not be queued. Check the email settings and queue worker.');
        }

        return redirect()->route('tech.inbox.index')
            ->with('status', $dispatched ? ("Inbox check queued for {$dispatched} account".($dispatched > 1 ? 's' : '').'.') : 'No active accounts to poll.');
    }

    public function show(
        EmailMessage $message,
        MarkNotificationsReadBySource $markNotificationsReadBySource,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
    ) {
        // Only allow access to unrouted messages in Inbox context
        $this->authorizeContentRoute(request());

        if (! $this->isInboxMessage($message) || $message->ticket_id !== null) {
            abort(404);
        }

        $decision = $this->authorizeMessageUse(
            request(),
            $message,
            $mailboxAccess,
            $access,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
        );

        if (! $decision) {
            abort(404);
        }

        $message->load(['attachments']);
        $notificationReadSync = $decision->usesBreakGlass()
            ? ['web_push_tags' => []]
            : $markNotificationsReadBySource->handle(
                request()->user(),
                MarkNotificationsReadBySource::SOURCE_EMAIL_MESSAGE,
                [$message->id],
            );

        return view('email::Tech.view', [
            'message' => $message,
            'search' => request('q'),
            'closedNotificationTags' => $notificationReadSync['web_push_tags'],
        ]);
    }

    public function markSpam(Request $request, EmailMessage $message, MarkEmailAsSpam $markEmailAsSpam, MailboxAccess $mailboxAccess): RedirectResponse
    {
        if (! $this->isInboxMessage($message) || $message->ticket_id !== null || ! $mailboxAccess->canOrganizeMessage($request->user(), $message)) {
            abort(404);
        }

        $rule = $markEmailAsSpam->handle($message, $request->user());

        return redirect()->route('tech.inbox.index')
            ->with('status', 'Email marked as spam and rule "'.$rule->name.'" updated.');
    }

    /**
     * Delete the email message.
     * Respects account delete_policy:
     * - local_only: SoftDelete only (hides from view, keeps on server)
     * - sync_delete: SoftDelete AND delete from IMAP server
     * - legacy_default/auto_delete: manual Inbox delete remains local-only; import cleanup is separate
     */
    public function destroy(EmailMessage $message, MailboxAccess $mailboxAccess): RedirectResponse
    {
        if (! $this->isInboxMessage($message) || $message->ticket_id !== null || ! $mailboxAccess->canOrganizeMessage(request()->user(), $message)) {
            abort(404);
        }

        $account = $message->account;
        $policy = $account->delete_policy ?? 'local_only';
        $providerDeleted = false;
        $providerDeleteAttempted = false;

        // 1. If sync_delete, try to delete from IMAP first
        if ($policy === 'sync_delete' && $message->imap_uid) {
            $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
            $client = null;

            try {
                if (! $providerLock) {
                    throw new \RuntimeException('provider_work_locked');
                }

                $expectedBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
                    ->captureBindingVersion($account);
                $client = new ImapClient($account, $expectedBindingVersion);
                $client->connect();
                $providerDeleteAttempted = true;
                $providerDeleted = $client->deleteByUid($message->imap_uid, $message->mailbox);
            } catch (\Throwable $exception) {
                Log::warning('Manual provider Email deletion failed safely.', [
                    'account_id' => $account->id,
                    'email_message_id' => $message->id,
                    'reason' => 'provider_delete_failed',
                    'exception' => $exception::class,
                ]);
            } finally {
                try {
                    $client?->disconnect();
                } catch (\Throwable) {
                    // Cleanup cannot change the already-observed provider result.
                }
                $providerLock?->release();
            }
        }

        // 2. Perform local SoftDelete
        $message->delete();

        $statusMsg = match (true) {
            $policy !== 'sync_delete' => 'Email hidden (Soft Deleted). It will not be re-imported.',
            $providerDeleted => 'Email deleted locally and from server.',
            $providerDeleteAttempted => 'Email hidden locally, but the provider did not confirm deletion.',
            default => 'Email hidden locally. Provider deletion could not be started.',
        };

        return redirect()->route('tech.inbox.index')
            ->with('status', $statusMsg);
    }

    /**
     * Download an attachment from local storage if it belongs to an unrouted message.
     */
    public function download(
        EmailAttachment $attachment,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
    ): StreamedResponse {
        $this->authorizeContentRoute(request());
        $message = $attachment->message;
        if (! $message || ! $this->isInboxMessage($message) || $message->ticket_id !== null) {
            abort(404);
        }

        $decision = $message
            ? $this->authorizeMessageUse(
                request(),
                $message,
                $mailboxAccess,
                $access,
                ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD,
                'attachment',
                (int) $attachment->id,
            )
            : null;

        if (! $decision) {
            abort(404);
        }
        $disk = $attachment->disk ?: 'local';
        abort_unless($attachment->path && Storage::disk($disk)->exists($attachment->path), 404);
        $filename = $attachment->filename ?: basename($attachment->path);

        return Storage::disk($disk)->download($attachment->path, $filename);
    }

    private function isInboxMessage(EmailMessage $message): bool
    {
        return $message->isActiveProviderInboxMessage();
    }

    private function authorizeContentRoute(Request $request): void
    {
        $actor = $request->user();

        abort_unless(
            $actor?->isActive()
                && ($actor->can('email.inbox_view') || $actor->can('email.break_glass_activate')),
            403,
        );
    }

    /**
     * @param  Collection<int, EmailAccount>  $accounts
     * @return array<int>
     */
    private function authorizedAccountIds(
        Request $request,
        Collection $accounts,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        string $operation,
    ): array {
        $resourceType = $operation === ResolveMailboxAccessDecision::SEARCH ? 'search' : 'mailbox';

        return $accounts
            ->filter(function (EmailAccount $account) use (
                $access,
                $mailboxAccess,
                $operation,
                $request,
                $resourceType,
            ): bool {
                try {
                    $decision = $access->authorize(
                        $request->user(),
                        $account,
                        $operation,
                        $resourceType,
                        (int) $account->id,
                    );
                } catch (AuthorizationException) {
                    return false;
                }

                if ($operation === ResolveMailboxAccessDecision::SEARCH
                    && ! app(ResolveMailboxAccessDecision::class)
                        ->resolve(
                            $request->user(),
                            $account,
                            ResolveMailboxAccessDecision::CONTENT_VIEW,
                        )
                        ->allowed) {
                    return false;
                }

                return $decision->usesBreakGlass()
                    || $mailboxAccess->canAccessAccount($request->user(), $account, MailboxAccess::VIEW);
            })
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    private function authorizeMessageUse(
        Request $request,
        EmailMessage $message,
        MailboxAccess $mailboxAccess,
        MailboxAccessUseGuard $access,
        string $operation,
        string $resourceType = 'message',
        ?int $resourceId = null,
    ): ?MailboxAccessDecision {
        $message->loadMissing('account');

        if (! $request->user()?->isActive() || ! $message->account) {
            return null;
        }

        try {
            $decision = $access->authorize(
                $request->user(),
                $message->account,
                $operation,
                $resourceType,
                $resourceId ?? (int) $message->id,
            );
        } catch (AuthorizationException) {
            return null;
        }

        if (! $decision->usesBreakGlass()
            && ! $mailboxAccess->canAccessAccount($request->user(), $message->account, MailboxAccess::VIEW)) {
            return null;
        }

        return $decision;
    }
}
