<?php

namespace App\Modules\System\Tests\Unit;

use App\Modules\System\Actions\RenderOperationalMarkdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RenderOperationalMarkdownTest extends TestCase
{
    #[Test]
    public function ordinary_text_unicode_line_breaks_and_markdown_remain_readable(): void
    {
        $html = (new RenderOperationalMarkdown)->handle("Første linje\nAndre linje\n\n**Viktig** og *rolig*\n\n- Ett\n- To\n\n1. Først\n2. Så\n\n[Dokument](https://example.test/path)\n\n`<code>`");
        foreach (['Første linje<br />', '<strong>Viktig</strong>', '<em>rolig</em>',
            '<li>Ett</li>', '<ol>', '<a href="https://example.test/path">Dokument</a>',
            '<code>&lt;code&gt;</code>'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public static function hostileInput(): array
    {
        return [
            ['<script>alert(1)</script><iframe src="https://evil.test"></iframe>'],
            ['<img src="https://tracker.example.test/pixel" onerror="alert(1)">'],
            ['[bad](javascript:alert%281%29)'],
            ['[bad](JaVaScRiPt:alert%281%29)'],
            ['[bad](javascript&#58;alert%281%29)'],
            ['[bad](data:text/html;base64,PHNjcmlwdD4=)'],
            ['[bad](vbscript:msgbox%281%29)'],
            ['![Diagram](https://tracker.example.test/pixel)'],
        ];
    }

    #[Test, DataProvider('hostileInput')]
    public function input_cannot_create_active_html_unsafe_links_or_images(string $source): void
    {
        $html = (new RenderOperationalMarkdown)->handle($source);
        $document = new \DOMDocument;
        @$document->loadHTML('<html><body>'.$html.'</body></html>', LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//script|//iframe|//img|//object|//embed')->length);
        foreach ($xpath->query('//@*') as $attribute) {
            $this->assertFalse(str_starts_with(strtolower($attribute->name), 'on'));
            if ($attribute->name === 'href') {
                $this->assertDoesNotMatchRegularExpression('/^(?:javascript|vbscript|data):/i', html_entity_decode($attribute->value));
            }
        }
    }

    #[Test]
    public function image_alt_text_and_deep_or_empty_documents_are_safe(): void
    {
        $renderer = new RenderOperationalMarkdown;
        $html = $renderer->handle('![Diagram](https://tracker.example.test/pixel)');
        $this->assertStringContainsString('Diagram', $html);
        $this->assertStringNotContainsString('tracker.example.test', $html);
        $this->assertSame('', $renderer->handle(null));
        $this->assertSame('', $renderer->handle(''));
        $this->assertStringContainsString('Final text', $renderer->handle(str_repeat('> ', 200).'Final text'));
    }
}
