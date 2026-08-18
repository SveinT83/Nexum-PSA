<?php

namespace App\Modules\Integration\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderTelemetryRemediationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function remediation_uses_exact_hashed_cohorts_and_preserves_unrelated_telescope_history(): void
    {
        $first = $this->entry('query', json_encode([
            'sql' => 'insert into provider_log (imap_secret) values (?)',
            'bindings' => ['secret-one-canary'],
        ], JSON_THROW_ON_ERROR));
        $second = $this->entry('model', json_encode([
            'model' => 'App\\Modules\\Integration\\Models\\EmailProviderConnection:provider-id',
            'changes' => ['imap_host' => 'model-host-canary.example'],
        ], JSON_THROW_ON_ERROR));
        $third = $this->entry('request', json_encode([
            'uri' => 'https://nexum.test/tech/admin/system/integrations/email-providers',
            'request' => ['private_endpoint_reason' => 'reason-three-canary'],
        ], JSON_THROW_ON_ERROR));
        $safe = $this->entry('log', json_encode([
            'message' => 'EmailAccountant report uses imapXsecret and imap%secret literals only.',
        ], JSON_THROW_ON_ERROR));
        DB::table('telescope_entries_tags')->insert([
            'entry_uuid' => $first->uuid,
            'tag' => 'provider-sensitive-test',
        ]);

        $status = Artisan::call('email-provider:telescope-remediate', ['--limit' => 2]);
        $output = Artisan::output();
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('exceeded the bounded cohort', $output);
        $this->assertStringContainsString('--through-sequence='.$second->sequence, $output);
        $this->assertStringNotContainsString('secret-one-canary', $output);
        $this->assertStringNotContainsString('model-host-canary.example', $output);
        $this->assertStringNotContainsString('reason-three-canary', $output);

        $status = Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--through-sequence' => $second->sequence,
        ]);
        $output = Artisan::output();
        $this->assertSame(Command::FAILURE, $status, 'A preview with matches is a release-blocking inventory.');
        $cohortHash = $this->outputValue($output, 'cohort_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cohortHash);

        // Changing a row after preview invalidates the content-bound manifest.
        DB::table('telescope_entries')->where('sequence', $second->sequence)->update([
            'content' => json_encode([
                'model' => 'App\\Modules\\Integration\\Models\\EmailProviderConnection:provider-id',
                'changes' => ['smtp_host' => 'changed-after-preview-canary.example'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $status = Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--through-sequence' => $second->sequence,
            '--cohort-hash' => $cohortHash,
            '--purge' => true,
            '--acknowledge-observability-loss' => true,
        ]);
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('cohort changed', Artisan::output());
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $first->uuid]);
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $second->uuid]);

        Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--through-sequence' => $second->sequence,
        ]);
        $cohortHash = $this->outputValue(Artisan::output(), 'cohort_hash');
        $status = Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--through-sequence' => $second->sequence,
            '--cohort-hash' => $cohortHash,
            '--purge' => true,
            '--acknowledge-observability-loss' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $first->uuid]);
        $this->assertDatabaseMissing('telescope_entries', ['uuid' => $second->uuid]);
        $this->assertDatabaseMissing('telescope_entries_tags', ['entry_uuid' => $first->uuid]);
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $third->uuid]);
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $safe->uuid]);

        $status = Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--after-sequence' => $second->sequence,
            '--through-sequence' => $third->sequence,
        ]);
        $this->assertSame(Command::FAILURE, $status);
        $thirdHash = $this->outputValue(Artisan::output(), 'cohort_hash');
        $status = Artisan::call('email-provider:telescope-remediate', [
            '--limit' => 2,
            '--after-sequence' => $second->sequence,
            '--through-sequence' => $third->sequence,
            '--cohort-hash' => $thirdHash,
            '--purge' => true,
            '--acknowledge-observability-loss' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $status);

        $status = Artisan::call('email-provider:telescope-remediate', [
            '--after-sequence' => $third->sequence,
        ]);
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('matched=0', Artisan::output());
        $this->assertDatabaseHas('telescope_entries', ['uuid' => $safe->uuid]);
    }

    private function entry(string $type, string $content): object
    {
        $uuid = (string) Str::uuid();
        DB::table('telescope_entries')->insert([
            'uuid' => $uuid,
            'batch_id' => (string) Str::uuid(),
            'type' => $type,
            'content' => $content,
            'created_at' => now(),
        ]);

        return DB::table('telescope_entries')->where('uuid', $uuid)->firstOrFail();
    }

    private function outputValue(string $output, string $key): string
    {
        preg_match('/^'.preg_quote($key, '/').'=(.+)$/m', $output, $matches);

        return trim((string) ($matches[1] ?? ''));
    }
}
