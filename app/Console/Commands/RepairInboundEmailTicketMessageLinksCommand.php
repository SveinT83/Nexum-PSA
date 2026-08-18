<?php

namespace App\Console\Commands;

use App\Modules\Ticket\Actions\AdvanceInboundEmailTicketMessageRepair;
use Illuminate\Console\Command;

class RepairInboundEmailTicketMessageLinksCommand extends Command
{
    protected $signature = 'notification:repair-inbound-ticket-message-links';

    protected $description = 'Advance one bounded legacy inbound Ticket-message link repair page';

    public function handle(AdvanceInboundEmailTicketMessageRepair $repair): int
    {
        $result = $repair->handle();
        $this->line((string) json_encode([
            'status' => $result['status'],
            'cursor_id' => $result['cursor_id'],
            'through_id' => $result['through_id'],
            'processed' => $result['processed'],
            'error_code' => $result['error_code'],
        ], JSON_THROW_ON_ERROR));

        return $result['status'] === AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED
            || $result['error_code'] !== null
            ? self::FAILURE
            : self::SUCCESS;
    }
}
