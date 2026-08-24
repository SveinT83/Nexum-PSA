<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailUserConversationAcknowledgement;
use Illuminate\Support\Facades\DB;

class AcknowledgeEmailConversation
{
    public function handle(EmailConversation $conversation, User $user): EmailMailUserConversationAcknowledgement
    {
        return DB::transaction(function () use ($conversation, $user) {
            $lastMessageId = $conversation->messages()->max('id');

            $ack = EmailMailUserConversationAcknowledgement::updateOrCreate(
                [
                    'email_conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                ],
                [
                    'last_acknowledged_message_id' => $lastMessageId,
                    'acknowledged_at' => now(),
                ]
            );

            // We could also trigger a re-classification or cleanup here if needed.

            return $ack;
        });
    }
}
