<?php

namespace App\Modules\Email\Services;

use RuntimeException;

/** The local-only run exhausted its immutable aggregate evidence-read budget. */
class EmailCanonicalCorrelationEvidenceBudgetExceeded extends RuntimeException {}
