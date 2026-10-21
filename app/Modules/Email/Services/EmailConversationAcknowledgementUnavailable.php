<?php

namespace App\Modules\Email\Services;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class EmailConversationAcknowledgementUnavailable extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(null, 'Conversation acknowledgement is not available.');
    }
}
