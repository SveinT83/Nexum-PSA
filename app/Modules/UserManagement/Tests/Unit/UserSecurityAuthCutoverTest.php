<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Tests\Unit;

use App\Models\Core\User;
use App\Modules\UserManagement\Security\Support\UserSecurityAuthCutover;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UserSecurityAuthCutoverTest extends TestCase
{
    public function test_exact_legacy_and_enforced_tuples_are_the_only_accepted_states(): void
    {
        $cutover = new UserSecurityAuthCutover;

        $this->configure('eloquent');
        self::assertFalse($cutover->isEnforced());

        $this->configure('nexum-user-security');
        self::assertTrue($cutover->isEnforced());
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_unknown_or_mixed_configuration_fails_closed(array $guard, array $provider): void
    {
        config()->set('auth.guards.web', $guard);
        config()->set('auth.providers.user_management', $provider);

        $this->expectException(LogicException::class);
        (new UserSecurityAuthCutover)->isEnforced();
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'wrong guard driver' => [
                ['driver' => 'token', 'provider' => 'user_management'],
                ['driver' => 'eloquent', 'model' => User::class],
            ],
            'wrong provider name' => [
                ['driver' => 'session', 'provider' => 'users'],
                ['driver' => 'eloquent', 'model' => User::class],
            ],
            'wrong provider model' => [
                ['driver' => 'session', 'provider' => 'user_management'],
                ['driver' => 'eloquent', 'model' => self::class],
            ],
            'driver alias' => [
                ['driver' => 'session', 'provider' => 'user_management'],
                ['driver' => 'nexum_user_security', 'model' => User::class],
            ],
            'missing provider driver' => [
                ['driver' => 'session', 'provider' => 'user_management'],
                ['model' => User::class],
            ],
            'missing guard' => [
                [],
                ['driver' => 'eloquent', 'model' => User::class],
            ],
        ];
    }

    private function configure(string $driver): void
    {
        config()->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'user_management',
        ]);
        config()->set('auth.providers.user_management', [
            'driver' => $driver,
            'model' => User::class,
        ]);
    }
}
