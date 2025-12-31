<?php

namespace App\Http\Controllers\Tech\Inbox;

use App\Domain\Email\Models\EmailMessage;
use App\Domain\Email\Models\EmailAccount;
use App\Domain\Email\Jobs\FetchImapAccount;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class IndexController extends Controller
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

        return view('tech.inbox.index', [
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
        $accounts = EmailAccount::query()->where('is_active', true)->get();

        $dispatched = 0;
        foreach ($accounts as $account) {
            // Force synchronous fetch now, regardless of queue driver
            FetchImapAccount::dispatchSync($account->id);
            $dispatched++;
        }

        return redirect()->route('tech.inbox.index')
            ->with('status', $dispatched ? ("Checked now for {$dispatched} account" . ($dispatched>1?'s':'')) : 'No active accounts to poll');
    }
}
