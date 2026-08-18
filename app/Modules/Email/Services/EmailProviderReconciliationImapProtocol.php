<?php

namespace App\Modules\Email\Services;

use WeakMap;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

/**
 * IMAP protocol reader with hard response bounds enforced before allocation.
 *
 * Webklex's default protocol trusts a provider literal declaration and grows
 * response strings until that declaration is satisfied. Reconciliation is a
 * hostile-input boundary, so every line, literal, and complete command
 * response is capped before it can enter the vendor parser.
 */
final class EmailProviderReconciliationImapProtocol extends ImapProtocol
{
    public const MAX_CONTROL_LINE_BYTES = 524_288;

    private const MAX_CONTROL_LITERAL_BYTES = 65_536;

    private const MAX_DEFAULT_RESPONSE_BYTES = 1_048_576;

    private const MAX_LIST_RESPONSE_BYTES = 8_388_608;

    private const MAX_METADATA_RESPONSE_BYTES = 8_388_608;

    private const MAX_SEARCH_RESPONSE_BYTES = 1_048_576;

    private const RESPONSE_FRAMING_BYTES = 65_536;

    /** @var WeakMap<Response, int> */
    private WeakMap $responseBytes;

    /** @var WeakMap<Response, int> */
    private WeakMap $literalBytesRemaining;

    public function __construct(
        #[\SensitiveParameter] Config $config,
        bool $certValidation = true,
        mixed $encryption = false,
    ) {
        parent::__construct($config, $certValidation, $encryption);
        $this->responseBytes = new WeakMap;
        $this->literalBytesRemaining = new WeakMap;
    }

    /**
     * Read one bounded protocol line. A literal declaration is rejected on
     * its small framing line, before a single byte of an oversized literal is
     * consumed or materialized.
     */
    public function nextLine(#[\SensitiveParameter] Response $response): string
    {
        [$lineCap, $literalCap, $responseCap] = $this->bounds($response);
        $responseBytes = $this->responseBytes[$response] ?? 0;
        $literalRemaining = $this->literalBytesRemaining[$response] ?? 0;
        $currentLineCap = $literalRemaining > 0
            ? min($responseCap, $literalRemaining + self::RESPONSE_FRAMING_BYTES)
            : $lineCap;
        $line = '';
        $lineBytes = 0;
        $nextCharacter = null;

        while (($nextCharacter = fread($this->stream, 1)) !== false
            && ! in_array($nextCharacter, ['', "\n"], true)) {
            $lineBytes++;
            $responseBytes++;
            if ($lineBytes > $currentLineCap || $responseBytes > $responseCap) {
                throw new EmailProviderReconciliationReadException(
                    'provider_response_byte_cap_exceeded',
                );
            }

            $line .= $nextCharacter;
        }

        if ($line === '' && ($nextCharacter === false || $nextCharacter === '')) {
            throw new RuntimeException('empty response');
        }

        // The consumed LF is part of both the protocol response and an IMAP
        // literal's declared octet sequence.
        $line .= "\n";
        $lineBytes++;
        $responseBytes++;
        if ($lineBytes > $currentLineCap || $responseBytes > $responseCap) {
            throw new EmailProviderReconciliationReadException(
                'provider_response_byte_cap_exceeded',
            );
        }

        if ($literalRemaining > 0) {
            $this->literalBytesRemaining[$response] = max(
                0,
                $literalRemaining - $lineBytes,
            );
        }

        if (preg_match('/\{([0-9]+)\+?\}\r?\n$/', $line, $matches) === 1) {
            $declared = filter_var($matches[1], FILTER_VALIDATE_INT);
            if ($declared === false
                || $declared < 0
                || $declared > $literalCap
                || $declared > ($responseCap - $responseBytes)) {
                throw new EmailProviderReconciliationReadException(
                    'provider_response_byte_cap_exceeded',
                );
            }

            $this->literalBytesRemaining[$response] = $declared;
        }

        $this->responseBytes[$response] = $responseBytes;
        $response->addResponse($line);

        if ($this->debug) {
            echo '<< '.$line;
        }

        return $line;
    }

    /** @return array{0:int,1:int,2:int} */
    private function bounds(#[\SensitiveParameter] Response $response): array
    {
        $commands = $response->getCommands();
        $lastCommand = end($commands);
        $command = is_string($lastCommand) ? $lastCommand : '';

        if (preg_match(
            '/BODY\.PEEK\[(HEADER|TEXT)?\]<0\.([0-9]+)>/i',
            $command,
            $matches,
        ) === 1) {
            $requested = filter_var($matches[2], FILTER_VALIDATE_INT);
            $hardCap = isset($matches[1]) && strcasecmp($matches[1], 'HEADER') === 0
                ? EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 1
                : EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES + 1;
            if ($requested === false || $requested < 1 || $requested > $hardCap) {
                throw new EmailProviderReconciliationReadException(
                    'provider_response_byte_cap_exceeded',
                );
            }

            return [
                min(self::MAX_CONTROL_LINE_BYTES, $requested + self::RESPONSE_FRAMING_BYTES),
                $requested,
                $requested + self::RESPONSE_FRAMING_BYTES,
            ];
        }

        $upper = strtoupper($command);
        $responseCap = match (true) {
            str_contains($upper, ' LIST ') => self::MAX_LIST_RESPONSE_BYTES,
            str_contains($upper, ' SEARCH ') => self::MAX_SEARCH_RESPONSE_BYTES,
            str_contains($upper, ' FETCH ') => self::MAX_METADATA_RESPONSE_BYTES,
            default => self::MAX_DEFAULT_RESPONSE_BYTES,
        };

        return [
            self::MAX_CONTROL_LINE_BYTES,
            self::MAX_CONTROL_LITERAL_BYTES,
            $responseCap,
        ];
    }
}
