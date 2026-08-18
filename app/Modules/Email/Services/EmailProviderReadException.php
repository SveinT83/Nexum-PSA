<?php

namespace App\Modules\Email\Services;

use RuntimeException;

/**
 * Provider metadata could not be read before a mailbox mutation began.
 *
 * The public message is deliberately stable. The wrapped library exception
 * remains available only for sanitized class/code diagnostics.
 */
class EmailProviderReadException extends RuntimeException {}
