<?php

namespace App\Modules\Relationship\Support;

class RelationshipCapability
{
    public const TICKET_SYNC = 'ticket_sync';

    public const STATUS_SYNC = 'status_sync';

    public const ATTACHMENT_SYNC = 'attachment_sync';

    public const DOCUMENTATION_SYNC = 'documentation_sync';

    public const KNOWLEDGE_SYNC = 'knowledge_sync';

    public static function defaults(): array
    {
        return array_fill_keys(self::values(), false);
    }

    public static function values(): array
    {
        return [
            self::TICKET_SYNC,
            self::STATUS_SYNC,
            self::ATTACHMENT_SYNC,
            self::DOCUMENTATION_SYNC,
            self::KNOWLEDGE_SYNC,
        ];
    }
}
