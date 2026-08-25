<?php

namespace App\Modules\Email\Tests\Unit;

use App\Modules\Email\Services\OutboundEmailHtmlPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OutboundEmailHtmlPolicyTest extends TestCase
{
    #[Test]
    public function it_accepts_safe_body_fragments_and_complete_single_slot_layouts(): void
    {
        $policy = new OutboundEmailHtmlPolicy;

        $policy->assertBody('<h2>Update</h2><p><a href="https://example.test">Read more</a></p>');
        $policy->assertLayout('<!doctype html><html><head><style>p { color: #123456; }</style></head><body>{{ email_body }}</body></html>');

        $this->addToAssertionCount(2);
    }

    #[Test]
    #[DataProvider('unsafeHtmlProvider')]
    public function it_rejects_active_or_ambiguous_html(string $kind, string $html): void
    {
        $policy = new OutboundEmailHtmlPolicy;
        $this->expectException(InvalidArgumentException::class);

        $kind === 'body'
            ? $policy->assertBody($html)
            : $policy->assertLayout($html);
    }

    public static function unsafeHtmlProvider(): array
    {
        return [
            'full document in body' => ['body', '<html><body>Wrong field</body></html>'],
            'reserved slot in body' => ['body', '<p>{{ email_body }}</p>'],
            'missing layout slot' => ['layout', '<html><body><p>No slot</p></body></html>'],
            'duplicate layout slot' => ['layout', '<html><body>{{ email_body }}{{email_body}}</body></html>'],
            'layout slot in attribute' => ['layout', '<html><body><div data-copy="{{ email_body }}"></div></body></html>'],
            'layout slot in comment' => ['layout', '<html><body><!-- {{ email_body }} --></body></html>'],
            'layout slot in head style' => ['layout', '<html><head><style>.copy::after { content: "{{ email_body }}"; }</style></head><body></body></html>'],
            'script' => ['layout', '<html><body>{{ email_body }}<script>alert(1)</script></body></html>'],
            'event handler' => ['layout', '<html><body onload="alert(1)">{{ email_body }}</body></html>'],
            'unsafe URL' => ['layout', '<html><body>{{ email_body }}<a href="javascript:alert(1)">Open</a></body></html>'],
            'unsafe CSS' => ['layout', '<html><head><style>@import url(https://example.test/x.css);</style></head><body>{{ email_body }}</body></html>'],
            'form control' => ['body', '<p>Reply</p><input name="reply">'],
        ];
    }
}
