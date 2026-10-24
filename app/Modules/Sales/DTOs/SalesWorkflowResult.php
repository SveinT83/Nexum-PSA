<?php

namespace App\Modules\Sales\DTOs;

/** Transport-neutral workflow result; browser adapters choose their own redirect and modal. */
final readonly class SalesWorkflowResult
{
    public function __construct(public string $level = 'success', public string $message = '', public ?int $id = null, public array $data = []) {}
}
