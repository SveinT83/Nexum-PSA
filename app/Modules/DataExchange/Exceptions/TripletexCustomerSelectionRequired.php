<?php

namespace App\Modules\DataExchange\Exceptions;

use Illuminate\Validation\ValidationException;

/** Duplicate preflight stopped before any customer POST; a corrected selection needs new keys. */
final class TripletexCustomerSelectionRequired extends ValidationException {}
