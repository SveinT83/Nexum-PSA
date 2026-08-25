<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use Illuminate\Validation\ValidationException;

class AcknowledgeEmailConversation
{
    /**
     * The historical implicit conversation mutation is deliberately closed.
     * Callers must use PreviewEmailConversationAcknowledgement and then apply
     * the returned frozen run with ApplyEmailConversationAcknowledgement.
     */
    public function handle(EmailConversation $conversation, User $user): never
    {
        throw ValidationException::withMessages([
            'conversation' => 'Conversation acknowledgement requires an explicit frozen preview.',
        ]);
    }
}
