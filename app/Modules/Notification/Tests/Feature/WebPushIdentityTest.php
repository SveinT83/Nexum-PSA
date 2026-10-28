<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Modules\Notification\Support\WebPushIdentity;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Ephemeral key-pair validation only; values never leave process memory or become installation keys. */
class WebPushIdentityTest extends TestCase
{
    #[Test]
    public function generated_p256_pair_is_valid_but_a_different_private_key_is_not(): void
    {
        $one = VAPID::createVapidKeys();
        $two = VAPID::createVapidKeys();
        $identity = ['subject' => 'mailto:admin@example.test', 'public_key' => $one['publicKey'], 'private_key' => $one['privateKey']];
        $this->assertTrue(WebPushIdentity::valid($identity));
        $identity['private_key'] = $two['privateKey'];
        $this->assertFalse(WebPushIdentity::valid($identity));
        $identity['private_key'] = 'PRIVATE-CANARY-invalid';
        $this->assertFalse(WebPushIdentity::valid($identity));
    }

    #[Test]
    public function subjects_are_stable_contacts_and_cannot_embed_credentials_or_controls(): void
    {
        foreach (['mailto:admin@example.test', 'https://example.test/contact'] as $subject) {
            $this->assertTrue(WebPushIdentity::validSubject($subject));
        }
        foreach (['', 'http://example.test', 'https://user:secret@example.test', "mailto:admin@example.test\n", 'javascript:alert(1)'] as $subject) {
            $this->assertFalse(WebPushIdentity::validSubject($subject));
        }
    }
}
