<?php

namespace App\Modules\Integration\Support;

enum StructuredAiWorkloadStatus: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
