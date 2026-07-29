<?php

namespace App\Modules\Ticket\Exceptions;

use RuntimeException;

class TicketMessageIdempotencyConflict extends RuntimeException {}
