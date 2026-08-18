<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Services\EmailPrivateStorage;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailPrivateStorageTest extends TestCase
{
    #[Test]
    public function it_verifies_payloads_and_normalizes_modes_after_a_restrictive_umask(): void
    {
        Storage::fake(EmailPrivateStorage::DISK);
        $previousUmask = umask(0022);

        try {
            $stored = app(EmailPrivateStorage::class)->put(
                'email/sent-pending/2/accepted-message.eml',
                'safe RFC 822 test payload',
            );
        } finally {
            umask($previousUmask);
        }

        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $this->assertTrue($stored);
        $disk->assertExists('email/sent-pending/2/accepted-message.eml');
        $this->assertSame(
            0660,
            fileperms($disk->path('email/sent-pending/2/accepted-message.eml')) & 0777,
        );

        foreach (['email', 'email/sent-pending', 'email/sent-pending/2'] as $directory) {
            $permissions = fileperms($disk->path($directory));
            $this->assertNotFalse($permissions);
            $this->assertSame(0770, $permissions & 0777);
            $this->assertSame(02000, $permissions & 02000);
        }
    }

    #[Test]
    public function it_rejects_paths_outside_the_mail_owned_private_tree(): void
    {
        Storage::fake(EmailPrivateStorage::DISK);

        $this->expectException(\InvalidArgumentException::class);

        app(EmailPrivateStorage::class)->put('../email/sent-pending/message.eml', 'payload');
    }
}
