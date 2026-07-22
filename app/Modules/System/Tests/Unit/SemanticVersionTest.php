<?php

namespace App\Modules\System\Tests\Unit;

use App\Modules\System\Support\SemanticVersion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SemanticVersionTest extends TestCase
{
    #[Test]
    public function it_normalizes_semantic_versions_and_rejects_legacy_tags(): void
    {
        $this->assertSame('0.2.0-beta', SemanticVersion::normalize('v0.2.0-beta'));
        $this->assertSame('1.4.2-beta.3+build.9', SemanticVersion::normalize('V1.4.2-beta.3+build.9'));
        $this->assertNull(SemanticVersion::normalize('Beta2'));
        $this->assertNull(SemanticVersion::normalize('1.2'));
        $this->assertNull(SemanticVersion::normalize('01.2.3'));
    }

    #[Test]
    public function it_compares_stable_and_prerelease_versions(): void
    {
        $this->assertTrue(SemanticVersion::isNewer('0.3.0-beta.1', '0.2.0-beta'));
        $this->assertTrue(SemanticVersion::isNewer('0.2.0', '0.2.0-beta'));
        $this->assertFalse(SemanticVersion::isNewer('0.2.0-beta', '0.2.0-beta'));
        $this->assertNull(SemanticVersion::isNewer('Beta2', '0.2.0-beta'));
    }
}
