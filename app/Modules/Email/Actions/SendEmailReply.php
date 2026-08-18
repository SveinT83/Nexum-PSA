<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;

class SendEmailReply
{
    public function __construct(
        private readonly SendEmailComposerMessage $composerMessage,
    ) {}

    /**
     * Send a direct Mail reply through the selected placement's account.
     *
     * @param  array{
     *     to?: string|null,
     *     cc?: string|null,
     *     subject?: string|null,
     *     body?: string|null,
     *     idempotency_key?: string|null,
     *     attachments?: array<int, UploadedFile|TemporaryUploadedFile>
     * }  $payload
     */
    public function handle(EmailMailboxPlacement $placement, User $actor, array $payload): EmailLog
    {
        return $this->composerMessage->handle($placement, $actor, [
            'mode' => SendEmailComposerMessage::MODE_REPLY,
        ] + $payload);
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    public function parseRecipients(string $value): array
    {
        return $this->composerMessage->parseRecipients($value);
    }

    public function defaultSubject(EmailMessage $message): string
    {
        return $this->composerMessage->defaultReplySubject($message);
    }
}
