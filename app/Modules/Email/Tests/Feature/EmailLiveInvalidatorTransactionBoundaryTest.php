<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Services\EmailLiveInvalidator;
use LogicException;
use Tests\TestCase;

class EmailLiveInvalidatorTransactionBoundaryTest extends TestCase
{
    public function test_enabled_runtime_requires_an_outer_transaction(): void
    {
        config()->set('email_live.enabled', true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Email live invalidation requires an active transaction.');

        app(EmailLiveInvalidator::class)->record([
            'user' => [1 => [EmailLiveProjectionChange::TYPE_PERSONAL_STATE]],
        ]);
    }
}
