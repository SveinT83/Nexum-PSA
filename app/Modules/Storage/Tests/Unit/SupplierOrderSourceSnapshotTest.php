<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Modules\Storage\Support\SupplierOrderSourceSnapshot;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderSourceSnapshotTest extends TestCase
{
    #[Test]
    public function stored_html_is_re_sanitized_without_presentational_or_interactive_attributes(): void
    {
        $snapshot = app(SupplierOrderSourceSnapshot::class)->sanitizeStoredSnapshot([
            'subject' => 'Synthetic order',
            'from' => ['email' => 'orders@example.invalid'],
            'body_html' => <<<'HTML'
<div class="position-fixed" id="cover" data-bs-toggle="modal" style="inset:0" onclick="alert(1)">
    <p aria-label="spoofed"><a href="https://attacker.invalid">Visible source text</a></p>
    <table role="dialog"><tr><td colspan="99">Line text</td></tr></table>
    <script>alert(2)</script>
    <button formaction="https://attacker.invalid">Submit</button>
</div>
HTML,
            'body_text' => 'Visible source text',
        ]);

        $html = (string) $snapshot['body_html'];

        $this->assertStringContainsString('Visible source text', $html);
        $this->assertStringContainsString('Line text', $html);
        $this->assertStringNotContainsString('position-fixed', $html);
        $this->assertStringNotContainsString('id=', $html);
        $this->assertStringNotContainsString('data-bs-', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('aria-', $html);
        $this->assertStringNotContainsString('role=', $html);
        $this->assertStringNotContainsString('colspan=', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<button', $html);
    }
}
