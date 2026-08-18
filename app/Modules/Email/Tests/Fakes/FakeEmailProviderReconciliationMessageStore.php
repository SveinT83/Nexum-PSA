<?php

namespace App\Modules\Email\Tests\Fakes;

use App\Modules\Email\Contracts\EmailProviderReconciliationMessageStore;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;
use Closure;
use LogicException;

final class FakeEmailProviderReconciliationMessageStore implements EmailProviderReconciliationMessageStore
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public ?Closure $callback = null;

    public function store(
        int $runId,
        int $itemId,
        int $claimAttempt,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
        EmailProviderReconciliationPeekedMessage $peeked,
        bool $runInboundRules,
    ): EmailProviderReconciliationStoredMessage {
        $arguments = compact(
            'runId',
            'itemId',
            'claimAttempt',
            'accountId',
            'folderId',
            'uidNamespaceId',
            'uidValidity',
            'uid',
            'peeked',
            'runInboundRules',
        );
        $this->calls[] = $arguments;
        if (! $this->callback) {
            throw new LogicException('The fake reconciliation message store needs a callback.');
        }

        $stored = ($this->callback)($arguments);
        if (! $stored instanceof EmailProviderReconciliationStoredMessage) {
            throw new LogicException('The fake reconciliation store callback returned invalid evidence.');
        }

        return $stored;
    }
}
