<?php

namespace App\Modules\Email\Tests\Fakes;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationBindingSnapshot;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;

final class FakeEmailProviderReconciliationReader implements EmailProviderReconciliationReader
{
    public EmailProviderReconciliationBindingSnapshot $bindingSnapshot;

    /** @var array<int, mixed> */
    public array $folders = [];

    /** @var array<string, array<int, EmailProviderReconciliationFolderState|EmailProviderReconciliationReadException>> */
    public array $folderStates = [];

    /** @var array<string, array<int, EmailProviderReconciliationMetadataPage|EmailProviderReconciliationReadException>> */
    public array $metadataPages = [];

    /** @var array<string, EmailProviderReconciliationPeekedMessage|null|EmailProviderReconciliationReadException> */
    public array $messages = [];

    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function __construct(?EmailProviderReconciliationBindingSnapshot $binding = null)
    {
        $this->bindingSnapshot = $binding ?? new EmailProviderReconciliationBindingSnapshot(
            bindingVersion: 1,
            configurationVersion: 1,
            credentialVersion: 0,
            runtimeFingerprint: str_repeat('a', 64),
        );
    }

    public function binding(int $accountId, int $expectedBindingVersion): EmailProviderReconciliationBindingSnapshot
    {
        $this->calls[] = compact('accountId', 'expectedBindingVersion') + ['operation' => 'binding'];

        return $this->bindingSnapshot;
    }

    public function discoverFolders(
        int $accountId,
        int $expectedBindingVersion,
        int $timeCapSeconds,
    ): array {
        $this->calls[] = compact('accountId', 'expectedBindingVersion', 'timeCapSeconds')
            + ['operation' => 'discover'];

        return $this->folders;
    }

    public function folderState(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $timeCapSeconds,
    ): EmailProviderReconciliationFolderState {
        $this->calls[] = compact('accountId', 'expectedBindingVersion', 'folderPath', 'timeCapSeconds')
            + ['operation' => 'state'];
        $queue = $this->folderStates[$folderPath] ?? [];
        $value = array_shift($queue);
        $this->folderStates[$folderPath] = $queue;
        if ($value instanceof EmailProviderReconciliationReadException) {
            throw $value;
        }
        if (! $value instanceof EmailProviderReconciliationFolderState) {
            throw new EmailProviderReconciliationReadException('fake_folder_state_missing');
        }

        return $value;
    }

    public function metadataPage(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $uidValidity,
        int $afterUid,
        int $throughUid,
        int $limit,
        int $timeCapSeconds,
    ): EmailProviderReconciliationMetadataPage {
        $this->calls[] = compact(
            'accountId',
            'expectedBindingVersion',
            'folderPath',
            'uidValidity',
            'afterUid',
            'throughUid',
            'limit',
            'timeCapSeconds',
        ) + ['operation' => 'metadata'];
        $queue = $this->metadataPages[$folderPath] ?? [];
        $value = array_shift($queue);
        $this->metadataPages[$folderPath] = $queue;
        if ($value instanceof EmailProviderReconciliationReadException) {
            throw $value;
        }
        if (! $value instanceof EmailProviderReconciliationMetadataPage) {
            throw new EmailProviderReconciliationReadException('fake_metadata_page_missing');
        }

        return $value;
    }

    public function messageByUidPeek(
        int $accountId,
        int $expectedBindingVersion,
        string $folderPath,
        int $uidValidity,
        int $uid,
        int $timeCapSeconds,
    ): ?EmailProviderReconciliationPeekedMessage {
        $this->calls[] = compact(
            'accountId',
            'expectedBindingVersion',
            'folderPath',
            'uidValidity',
            'uid',
            'timeCapSeconds',
        ) + ['operation' => 'peek'];
        $value = $this->messages[$folderPath.':'.$uid] ?? null;
        if ($value instanceof EmailProviderReconciliationReadException) {
            throw $value;
        }

        return $value;
    }
}
