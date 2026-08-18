<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCanonicalReadMode extends Model
{
    public const MODE_LEGACY = 'legacy';

    public const MODE_VERIFY = 'verify';

    public const MODE_CANONICAL = 'canonical';

    /** @var list<string> */
    public const MODES = [self::MODE_LEGACY, self::MODE_VERIFY, self::MODE_CANONICAL];

    protected $table = 'email_canonical_read_modes';

    protected $guarded = [];

    protected $casts = [
        'lock_version' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
