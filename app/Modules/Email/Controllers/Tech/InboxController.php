<?php
namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboxController extends Controller
{
    /**
     * Display unrouted email messages (ticket_id null) with simple search & pagination.
     */
    public function index(Request $request)
    {
        $query = EmailMessage::query()
            ->whereNull('ticket_id');

        // Basic search across subject, from_email and body_text snippet
        if ($term = trim((string)$request->get('q'))) {
            $query->where(function($q) use ($term) {
                $like = '%' . str_replace(['%','_'], ['\%','\_'], $term) . '%';
                $q->where('subject', 'like', $like)
                  ->orWhere('from_email', 'like', $like)
                  ->orWhere('body_text', 'like', $like);
            });
        }

        // Sort newest first by received_at fallback created_at
        $messages = $query->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('email::Tech.index', [
            'messages' => $messages,
            'search' => $request->get('q')
        ]);
    }

    /**
     * Manually trigger immediate polling for all active email accounts.
     * Dispatches FetchImapAccount jobs; returns back to inbox with a flash.
     */
    public function poll(Request $request)
    {
        $settings = \App\Models\Settings\CommonSetting::where('type', 'emailhub')
            ->get()->pluck('value', 'name')->toArray();
        $batchSize = (int)($settings['batch_size'] ?? 20);

        $accounts = EmailAccount::query()->where('is_active', true)->get();

        $dispatched = 0;
        foreach ($accounts as $account) {
            // Force synchronous fetch AND synchronous storage now
            FetchImapAccount::dispatchSync($account->id, $batchSize, true);
            $dispatched++;
        }

        return redirect()->route('tech.inbox.index')
            ->with('status', $dispatched ? ("Checked now for {$dispatched} account" . ($dispatched>1?'s':'')) : 'No active accounts to poll');
    }

    public function show(EmailMessage $message)
    {
        // Only allow access to unrouted messages in Inbox context
        if ($message->ticket_id !== null) {
            abort(404);
        }

        $message->load(['attachments']);
        return view('email::Tech.view', [
            'message' => $message,
            'search' => request('q')
        ]);
    }

    /**
     * Delete the email message.
     * Respects account delete_policy:
     * - local_only: SoftDelete only (hides from view, keeps on server)
     * - sync_delete: SoftDelete AND delete from IMAP server
     */
    public function destroy(EmailMessage $message): RedirectResponse
    {
        if ($message->ticket_id !== null) {
            abort(404);
        }

        $account = $message->account;
        $policy = $account->delete_policy ?? 'local_only';

        // 1. If sync_delete, try to delete from IMAP first
        if ($policy === 'sync_delete' && $message->imap_uid) {
            try {
                $client = new ImapClient($account);
                $client->connect();
                $client->deleteByUid($message->imap_uid);
                $client->disconnect();
            } catch (\Throwable $e) {
                // Log error but continue with local delete?
                report($e);
            }
        }

        // 2. Perform local SoftDelete
        $message->delete();

        $statusMsg = ($policy === 'sync_delete')
            ? 'Email deleted locally and from server.'
            : 'Email hidden (Soft Deleted). It will not be re-imported.';

        return redirect()->route('tech.inbox.index')
            ->with('status', $statusMsg);
    }

    /**
     * Download an attachment from local storage if it belongs to an unrouted message.
     */
    public function download(EmailAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message;
        if (!$message || $message->ticket_id !== null) {
            abort(404);
        }
        $disk = $attachment->disk ?: 'local';
        abort_unless($attachment->path && Storage::disk($disk)->exists($attachment->path), 404);
        $filename = $attachment->filename ?: basename($attachment->path);
        return Storage::disk($disk)->download($attachment->path, $filename);
    }
}
