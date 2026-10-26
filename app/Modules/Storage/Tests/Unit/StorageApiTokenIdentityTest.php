<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Models\Core\User;
use App\Modules\Storage\Support\StorageApiAccess;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StorageApiTokenIdentityTest extends TestCase
{
    #[Test]
    public function personal_token_identity_preserves_existing_durable_receipt_compatibility(): void
    {
        $token = new PersonalAccessToken;
        $token->forceFill(['id' => 123]);
        $actor = (new User)->withAccessToken($token);
        $request = Request::create('/api/v1/storage/settings');
        $request->setUserResolver(fn () => $actor);

        $this->assertSame(hash('sha256', '123'), StorageApiAccess::tokenHash($request));
    }

    #[Test]
    public function stateful_sanctum_tokens_have_distinct_session_fingerprints_without_model_methods(): void
    {
        $actor = (new User)->withAccessToken(new TransientToken);
        $request = Request::create('/api/v1/storage/settings');
        $request->setUserResolver(fn () => $actor);
        $session = new Store('storage-api-test', new ArraySessionHandler(120));
        $session->setId(str_repeat('a', 40));
        $request->setLaravelSession($session);
        $first = StorageApiAccess::tokenHash($request);
        $session->setId(str_repeat('b', 40));

        $this->assertSame(hash('sha256', 'session:'.str_repeat('a', 40)), $first);
        $this->assertNotSame($first, StorageApiAccess::tokenHash($request));
    }

    #[Test]
    public function an_unidentifiable_transient_token_is_refused_without_a_server_error(): void
    {
        $request = Request::create('/api/v1/storage/settings');
        $request->setUserResolver(fn () => (new User)->withAccessToken(new TransientToken));
        try {
            StorageApiAccess::tokenHash($request);
            $this->fail('Missing authenticated session must be refused.');
        } catch (HttpException $error) {
            $this->assertSame(401, $error->getStatusCode());
        }
    }
}
