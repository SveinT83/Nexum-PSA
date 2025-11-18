<?php

namespace App\Http\Controllers\Tech\Inbox;

use App\Domain\Email\Models\EmailMessage;
use App\Domain\Email\Models\EmailAttachment;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShowController extends Controller
{
    public function show(EmailMessage $message)
    {
        // Only allow access to unrouted messages in Inbox context
        if ($message->ticket_id !== null) {
            abort(404);
        }

        $message->load(['attachments']);
        return view('Tech.Inbox.view', [
            'message' => $message,
            'search' => request('q')
        ]);
    }

    /**
     * Permanently delete the email message from local DB and its stored files.
     * Does NOT touch IMAP server.
     */
    public function destroy(EmailMessage $message): RedirectResponse
    {
        if ($message->ticket_id !== null) {
            abort(404);
        }

        // Delete raw .eml if present
        if ($message->raw_path) {
            // Default disk 'local' unless path contains disk prefix; here we assume local
            Storage::disk('local')->delete($message->raw_path);
        }

        // Delete attachments files
        $message->load('attachments');
        foreach ($message->attachments as $att) {
            $disk = $att->disk ?: 'local';
            if ($att->path) {
                Storage::disk($disk)->delete($att->path);
            }
        }

        // DB: attachments cascade via FK, but ensure cleanup
        $message->attachments()->delete();
        $message->delete();

        return redirect()->route('tech.inbox.index')
            ->with('status', 'Email deleted from local storage.');
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
