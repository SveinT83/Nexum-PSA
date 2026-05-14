<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreTicketAttachment
{
    public function fromUpload(TicketMessage $message, UploadedFile $file, ?User $actor = null): TicketAttachment
    {
        $ticket = $message->ticket;
        $disk = 'local';
        $filename = $this->safeFilename($file->getClientOriginalName());
        $path = $this->path($ticket->id, $message->id, $filename);

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'uploaded_by' => $actor?->id,
            'source' => 'upload',
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'content_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'checksum_sha1' => sha1_file($file->getRealPath()),
        ]);
    }

    public function fromEmailAttachment(TicketMessage $message, EmailAttachment $emailAttachment): ?TicketAttachment
    {
        if (! $message->ticket || ! $emailAttachment->path) {
            return null;
        }

        if (TicketAttachment::where('ticket_message_id', $message->id)
            ->where('email_attachment_id', $emailAttachment->id)
            ->exists()) {
            return null;
        }

        $sourceDisk = $emailAttachment->disk ?: 'local';

        if (! Storage::disk($sourceDisk)->exists($emailAttachment->path)) {
            return null;
        }

        $ticket = $message->ticket;
        $disk = 'local';
        $filename = $this->safeFilename($emailAttachment->filename ?: basename($emailAttachment->path));
        $path = $this->path($ticket->id, $message->id, $filename);

        $stream = Storage::disk($sourceDisk)->readStream($emailAttachment->path);

        if ($stream === false) {
            return null;
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'email_attachment_id' => $emailAttachment->id,
            'source' => 'email',
            'filename' => $filename,
            'original_filename' => $emailAttachment->filename,
            'content_type' => $emailAttachment->content_type,
            'size_bytes' => $emailAttachment->size_bytes,
            'disk' => $disk,
            'path' => $path,
            'checksum_sha1' => $emailAttachment->checksum_sha1,
        ]);
    }

    private function path(int $ticketId, int $messageId, string $filename): string
    {
        return 'ticket/attachments/' . $ticketId . '/' . $messageId . '/' . Str::uuid() . '-' . $filename;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\'], '-', $filename));

        return $filename !== '' ? $filename : 'attachment';
    }
}
