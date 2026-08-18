<?php

namespace App\Console\Commands;

use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Console\Command;

/** Operator recovery after a schema-wait job reached its finite deadline. */
final class ResumeInboundRulesAfterFanoutReadyCommand extends Command
{
    protected $signature = 'notification:resume-inbound-rules-after-fanout-ready {email_message_id}';

    protected $description = 'Resume one read-only inbound-rule intent after the fanout schema is sealed';

    public function handle(InboundEmailNotificationFanoutReadiness $readiness): int
    {
        $rawId = $this->argument('email_message_id');
        if (! is_string($rawId) || ! ctype_digit($rawId) || (int) $rawId < 1) {
            $this->error('A positive email_message_id is required.');

            return self::INVALID;
        }

        $emailMessageId = (int) $rawId;
        if (! $readiness->ready()) {
            $this->error('Inbound notification fanout schema is not sealed.');

            return self::FAILURE;
        }
        if (! EmailMessage::query()->whereKey($emailMessageId)->exists()) {
            $this->error('Email message does not exist.');

            return self::FAILURE;
        }

        ProcessInboundRules::dispatch($emailMessageId, false);
        $this->info('Queued read-only inbound-rule recovery for email '.$emailMessageId.'.');

        return self::SUCCESS;
    }
}
