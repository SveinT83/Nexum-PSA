<?php

namespace App\Modules\Email\Services;

use RuntimeException;

class EmailConversationAcknowledgementUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Conversation acknowledgement is not available.');
    }
}
