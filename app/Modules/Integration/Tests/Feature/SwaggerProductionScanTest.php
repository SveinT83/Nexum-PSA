<?php

namespace App\Modules\Integration\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SwaggerProductionScanTest extends TestCase
{
    #[Test]
    public function swagger_generates_without_loading_development_only_module_test_parents(): void
    {
        $directory = sys_get_temp_dir().'/nexum-swagger-production-'.Str::uuid();
        File::makeDirectory($directory, 0700, true);
        $sentinel = $directory.'/development-parent.php';
        File::put($sentinel, '<?php throw new RuntimeException("Swagger loaded a development-only parent.");');

        // A separate PHP process has no PHPUnit bootstrap. A poison parent makes any accidental
        // reflection of module tests fail even when this test runner has dev dependencies installed.
        $program = <<<'PHP'
$loader = require getcwd().'/vendor/autoload.php';
$loader->addClassMap([
    'Tests\\TestCase' => getenv('NEXUM_SWAGGER_TEST_SENTINEL'),
    'Laravel\\Telescope\\TelescopeApplicationServiceProvider' => getenv('NEXUM_SWAGGER_TEST_SENTINEL'),
]);
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app['config']->set('l5-swagger.defaults.paths.docs', getenv('NEXUM_SWAGGER_TEST_DIRECTORY'));
$status = $kernel->call('l5-swagger:generate');
echo $kernel->output();
exit($status);
PHP;

        try {
            $process = new Process([PHP_BINARY, '-r', $program], base_path(), [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => $directory.'/config.php',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
                'MAIL_MAILER' => 'array',
                'TELESCOPE_ENABLED' => 'false',
                'PULSE_ENABLED' => 'false',
                'NIGHTWATCH_ENABLED' => 'false',
                'NEXUM_SWAGGER_TEST_SENTINEL' => $sentinel,
                'NEXUM_SWAGGER_TEST_DIRECTORY' => $directory,
            ]);
            $process->setTimeout(120);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $schema = json_decode(File::get($directory.'/api-docs.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('/api/v1/email/mailbox/context', $schema['paths']);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
