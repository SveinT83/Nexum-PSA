<?php

namespace App\Modules\Marketing\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MarketingLifecycleMigrationSafetyTest extends TestCase
{
    public function test_email_foreign_key_index_is_created_before_the_old_unique_index_is_removed(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 5).'/database/migrations/2026_07_04_120000_complete_marketing_lifecycle_and_content_sources.php'
        );

        $supportingIndex = strpos(
            $migration,
            "'marketing_campaign_recipients_email_index'"
        );
        $oldUniqueDrop = strpos(
            $migration,
            "dropUnique('marketing_campaign_recipient_member_unique')"
        );

        self::assertNotFalse($supportingIndex);
        self::assertNotFalse($oldUniqueDrop);
        self::assertLessThan(
            $oldUniqueDrop,
            $supportingIndex,
            'The standalone email index must exist before MySQL drops the unique index used by the foreign key.'
        );
    }
}
