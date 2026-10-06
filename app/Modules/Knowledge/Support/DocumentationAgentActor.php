<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\EnsureSystemActor;

/**
 * Resolves the disabled least-privilege actor used by Knowledge automation.
 */
class DocumentationAgentActor
{
    public function resolve(): User
    {
        return app(EnsureSystemActor::class)->handle(
            key: 'knowledge_documentation_agent',
            name: 'Nexum Documentation Agent',
            email: 'documentation-agent@system.nexum.invalid',
            permissions: ['knowledge.revision_persist', 'knowledge.publish_system'],
        );
    }
}
