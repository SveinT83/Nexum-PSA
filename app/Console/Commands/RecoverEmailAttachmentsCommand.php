<?php

namespace App\Console\Commands;

use App\Modules\Email\Actions\RecoverEmailMessageAttachments;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailAttachmentRecoveryReadiness;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class RecoverEmailAttachmentsCommand extends Command
{
    protected $signature = 'email:recover-attachments
        {--message=* : Explicit message IDs; repeat the option or use comma-separated values}
        {--account= : Require every selected message to belong to this account ID}
        {--limit=50 : Safety bound for one invocation (maximum 100)}
        {--provider-fallback : Permit exact read-only UID/UIDVALIDITY provider reads when local recovery is unavailable}
        {--apply : Persist recovered attachment rows/files and recompute counters}';

    protected $description = 'Preflight or repair a bounded explicit set of missing inbound Email attachments.';

    public function handle(
        RecoverEmailMessageAttachments $recovery,
        EmailAttachmentRecoveryReadiness $readiness,
    ): int {
        try {
            $messageIds = $this->messageIds();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $limit = min(100, max(1, (int) $this->option('limit')));

        if ($messageIds === []) {
            $this->error('At least one explicit --message ID is required.');

            return self::INVALID;
        }

        if (count($messageIds) > $limit) {
            $this->error(sprintf('Selected %d messages; this invocation is limited to %d.', count($messageIds), $limit));

            return self::INVALID;
        }

        $accountId = filter_var($this->option('account'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($this->option('account') !== null && $accountId === false) {
            $this->error('--account must be a positive integer.');

            return self::INVALID;
        }

        $messages = EmailMessage::query()
            ->withCount(['attachments as attachment_rows_count'])
            ->whereKey($messageIds)
            ->when($accountId !== false && $accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->get()
            ->keyBy('id');

        if (! $this->option('apply')) {
            $this->line('Preflight only; no files, rows, counters, rules, or provider state were changed.');

            foreach ($messageIds as $messageId) {
                $message = $messages->get($messageId);
                $this->line($message
                    ? sprintf(
                        '#%d account=%d rows=%d counter=%d raw_reference=%s',
                        $message->id,
                        $message->account_id,
                        $message->attachment_rows_count,
                        $message->attachments_count,
                        filled($message->raw_path) ? 'present' : 'missing',
                    )
                    : sprintf('#%d unavailable', $messageId));
            }

            $this->line('Run again with --apply after storage access and this exact target list have been reviewed.');

            return $messages->count() === count($messageIds) ? self::SUCCESS : self::FAILURE;
        }

        $readinessResult = $readiness->check();
        if (! $readinessResult['safe']) {
            $this->error(
                'Attachment recovery readiness check failed: '.$readinessResult['reason_code']
                .'. Resolve the reported schema/storage gate before retrying --apply.',
            );

            return self::FAILURE;
        }

        $failed = false;

        foreach ($messageIds as $messageId) {
            $message = $messages->get($messageId);
            if (! $message) {
                $failed = true;
                $this->line(sprintf('#%d status=failed reason=message_unavailable source=- parts=0/0 rows=0->0 counter=0->0', $messageId));

                continue;
            }

            try {
                $result = $recovery->handle($message, (bool) $this->option('provider-fallback'));
            } catch (Throwable $exception) {
                $failed = true;
                $this->line(sprintf(
                    '#%d status=failed reason=recovery_exception source=- parts=0/0 rows=%d->%d counter=%d->%d',
                    $messageId,
                    $message->attachments()->count(),
                    $message->attachments()->count(),
                    $message->attachments_count,
                    $message->fresh()->attachments_count,
                ));

                report($exception);

                continue;
            }

            $failed = $failed || in_array($result['status'], ['failed', 'partial'], true);
            $this->line(sprintf(
                '#%d status=%s reason=%s source=%s parts=%d/%d rows=%d->%d counter=%d->%d',
                $result['message_id'],
                $result['status'],
                $result['reason_code'],
                $result['source'] ?: '-',
                $result['processed_count'],
                $result['source_count'],
                $result['before_count'],
                $result['after_count'],
                $result['counter_before'],
                $result['counter_after'],
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int> */
    private function messageIds(): array
    {
        $ids = [];

        foreach ((array) $this->option('message') as $value) {
            foreach (explode(',', (string) $value) as $candidate) {
                $id = filter_var(trim($candidate), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);

                if ($id !== false) {
                    $ids[] = (int) $id;
                } else {
                    throw new InvalidArgumentException('--message values must contain only positive integer IDs.');
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
