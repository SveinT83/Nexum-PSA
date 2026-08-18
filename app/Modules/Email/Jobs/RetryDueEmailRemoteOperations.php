<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\RunDueEmailRemoteOperations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RetryDueEmailRemoteOperations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('email');
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-remote-operations-due'))
                ->dontRelease()
                ->expireAfter(300),
        ];
    }

    public function uniqueId(): string
    {
        return 'email-remote-operations-due';
    }

    public function handle(RunDueEmailRemoteOperations $runner): void
    {
        $runner->handle();
    }
}
