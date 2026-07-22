<?php

namespace App\Modules\System\Tests\Feature;

use App\Modules\System\Support\SemanticVersion;
use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationBuildMetadataTest extends TestCase
{
    #[Test]
    public function source_release_version_is_semantic_and_loaded_by_default(): void
    {
        $sourceVersion = trim((string) file_get_contents(base_path('version.txt')));

        $this->assertSame($sourceVersion, SemanticVersion::normalize($sourceVersion));

        if (env('APP_VERSION') === null) {
            $this->assertSame($sourceVersion, config('app.version'));
        }
    }

    #[Test]
    public function composer_install_reference_is_used_as_the_default_build_commit(): void
    {
        $composerReference = InstalledVersions::getRootPackage()['reference'] ?? null;

        $this->assertIsString($composerReference);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/i', $composerReference);

        if (env('APP_COMMIT_SHA') === null) {
            $this->assertSame(strtolower($composerReference), strtolower((string) config('app.commit')));
        }
    }
}
