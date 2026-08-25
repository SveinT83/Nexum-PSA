<?php

namespace App\Modules\Email\Services;

use Illuminate\Support\Facades\Schema;

class EmailCollaborationGate
{
    public function __construct(
        private readonly EmailLiveRuntimeReadiness $liveRuntime,
    ) {}

    /**
     * Configuration is checked first on purpose. A default-off or pre-migrate
     * deployment must never query Order 9's optional tables.
     */
    public function available(): bool
    {
        if (! config('email_live.enabled', false)
            || ! config('email_live.collaboration_enabled', false)) {
            return false;
        }

        return $this->liveRuntime->ready()
            && Schema::hasTable('email_shared_draft_locks')
            && Schema::hasTable('email_shared_draft_events')
            && Schema::hasColumn('email_composer_drafts', 'shared_scope_id');
    }
}
