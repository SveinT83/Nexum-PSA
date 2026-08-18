<?php

namespace App\Modules\Email\Services;

use RuntimeException;

/**
 * The requested provider UID disappeared before a mailbox mutation command
 * could be sent. Callers may therefore terminate as stale, not ambiguous.
 */
class EmailProviderMessageMissingException extends RuntimeException {}
