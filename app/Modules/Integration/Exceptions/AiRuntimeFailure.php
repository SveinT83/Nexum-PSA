<?php

namespace App\Modules\Integration\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Safe failure envelope: no raw upstream message, response, request or previous exception is retained. */
class AiRuntimeFailure extends RuntimeException
{
    public readonly string $category;

    public readonly string $correlationId;

    public readonly bool $retryable;

    public readonly ?int $httpStatus;

    public function __construct(string $category = 'unknown', ?string $correlationId = null, ?int $httpStatus = null)
    {
        $messages = [
            'denied' => 'AI access is denied by policy. Ask an administrator to review the approved access.',
            'unavailable' => 'AI is unavailable. Retry later or ask an administrator to check the configured service.',
            'timeout' => 'The AI request timed out. You may retry within the approved limits.',
            'budget_exceeded' => 'The AI budget is exhausted. Ask an administrator to review the approved limit.',
            'rate_limited' => 'The AI service is busy. Wait before retrying.',
            'authentication_failed' => 'AI authentication failed. Ask an administrator to check the secure credential configuration.',
            'provider_failed' => 'The AI service could not complete the request. Retry later or ask an administrator.',
            'invalid_response' => 'The AI service returned an unusable response. Retry or contact an administrator.',
            'policy_changed' => 'The AI policy changed. Ask an administrator to review access before retrying.',
            'cancelled' => 'The AI request was cancelled. Start a new request if needed.',
            'unknown' => 'The AI request failed. Retry later or contact an administrator.',
        ];
        $this->category = array_key_exists($category, $messages) ? $category : 'unknown';
        $this->correlationId = is_string($correlationId) && Str::isUuid($correlationId)
            ? strtolower($correlationId) : (string) Str::uuid();
        $this->retryable = in_array($this->category, ['unavailable', 'timeout', 'rate_limited', 'provider_failed', 'invalid_response', 'unknown'], true);
        $this->httpStatus = $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : null;
        parent::__construct($messages[$this->category].' Reference: '.$this->correlationId);
    }

    public static function fromThrowable(Throwable $error, ?string $correlationId = null): self
    {
        if ($error instanceof self) {
            return $error;
        }
        $category = match (true) {
            $error instanceof AiPolicyDeniedException => 'denied',
            $error instanceof StructuredAiProviderUnavailableException => 'unavailable',
            $error instanceof ConnectionException => $error->getCode() === 28 ? 'timeout' : 'unavailable',
            $error instanceof \JsonException => 'invalid_response',
            default => 'unknown',
        };

        return new self($category, $correlationId);
    }

    public static function fromHttp(int $status, ?string $correlationId = null): self
    {
        return new self(match ($status) {
            401, 403 => 'authentication_failed',
            408, 504 => 'timeout',
            429 => 'rate_limited',
            default => $status >= 500 ? 'provider_failed' : 'invalid_response',
        }, $correlationId, $status);
    }
}
