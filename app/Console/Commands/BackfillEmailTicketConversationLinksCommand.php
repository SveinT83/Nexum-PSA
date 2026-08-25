<?php

namespace App\Console\Commands;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailTicketConversationLinkMigrationRun;
use App\Modules\Email\Services\EmailTicketConversationLinkMigrator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BackfillEmailTicketConversationLinksCommand extends Command
{
    protected $signature = 'email:backfill-ticket-conversation-links
        {--actor= : Exact active human operator ID}
        {--limit=100 : Frozen preview item cap, from 1 through 500}
        {--apply= : Public ID of the reviewed frozen preview to queue}';

    protected $description = 'Preview or queue the fail-closed legacy Email/Ticket conversation-link backfill';

    public function handle(EmailTicketConversationLinkMigrator $migrator): int
    {
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $actor = $actorId === false ? null : User::query()->find((int) $actorId);
        if (! $actor) {
            $this->error('--actor must identify the exact active human operator.');

            return self::INVALID;
        }

        try {
            if ($this->option('apply') !== null) {
                $publicId = trim((string) $this->option('apply'));
                $run = EmailTicketConversationLinkMigrationRun::query()
                    ->where('public_id', $publicId)
                    ->first();
                if (! $run) {
                    $this->error('--apply must identify an existing frozen preview.');

                    return self::INVALID;
                }

                $queued = $migrator->queueApply($run, $actor);
                $this->line((string) json_encode($this->summary($queued), JSON_THROW_ON_ERROR));
                $this->info('The bounded Email queue job was dispatched. No provider operation was requested.');

                return self::SUCCESS;
            }

            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => EmailTicketConversationLinkMigrator::MAX_ITEM_CAP,
                ],
            ]);
            if ($limit === false) {
                $this->error('--limit must be an integer from 1 through 500.');

                return self::INVALID;
            }

            $run = $migrator->preview($actor, (int) $limit);
            $this->line((string) json_encode($this->summary($run), JSON_THROW_ON_ERROR));
            if ($run->status === EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED) {
                $this->error('The preview is blocked. Review its item reason codes; it cannot be applied.');

                return self::FAILURE;
            }
            if ($run->status === EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED) {
                $this->info('No missing deterministic relationship link requires apply.');

                return self::SUCCESS;
            }

            $this->comment('Review this exact public ID and fingerprint, then use --apply with the same --actor.');

            return self::SUCCESS;
        } catch (AuthorizationException|ValidationException|RuntimeException $exception) {
            $this->error($this->safeError($exception));

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Email/Ticket relationship migration did not complete.');

            return self::FAILURE;
        }
    }

    /** @return array<string, int|string|null> */
    private function summary(EmailTicketConversationLinkMigrationRun $run): array
    {
        return [
            'public_id' => $run->public_id,
            'status' => $run->status,
            'candidate_count' => $run->candidate_count,
            'ready_count' => $run->ready_count,
            'already_mapped_count' => $run->already_mapped_count,
            'conflict_count' => $run->conflict_count,
            'applied_count' => $run->applied_count,
            'failed_count' => $run->failed_count,
            'scope_fingerprint' => $run->scope_fingerprint,
            'error_code' => $run->error_code,
        ];
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first()
                ?? 'The migration request is invalid.';
        }
        if ($exception instanceof AuthorizationException) {
            return 'Email/Ticket relationship migration is unavailable.';
        }
        if (str_starts_with($exception->getMessage(), 'email_ticket_link_migration_')) {
            return $exception->getMessage();
        }

        return 'Email/Ticket relationship migration did not complete.';
    }
}
