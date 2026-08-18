<?php

namespace App\Modules\Email\Services;

use RuntimeException;

/**
 * The single SMTP attempt is already in progress or its provider outcome is
 * not safely known. Retrying the same key could deliver a duplicate message.
 */
class EmailSendOutcomeUnresolvedException extends RuntimeException {}
