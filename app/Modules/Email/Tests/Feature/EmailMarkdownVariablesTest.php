<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\System\Actions\RenderOperationalMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailMarkdownVariablesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function html_only_override_preserves_subject_plain_text_and_custom_layout(): void
    {
        $template = new EmailTemplate(['subject' => 'Subject {{ message_body }}',
            'body_html' => '<p class="reply">{{message_body}}</p>',
            'body_text' => '{{ message_body }}', 'layout_mode' => EmailTemplate::LAYOUT_CUSTOM,
            'layout_html' => '<html><body><header>Custom layout</header><main>{{ email_body }}</main></body></html>']);
        $source = "**Viktig** {{ contact_name }}\nNeste linje\n\n- Punkt";
        $rendered = app(EmailTemplateRenderer::class)->render($template, ['message_body' => $source, 'contact_name' => 'Do not expand user text'],
            ['message_body' => app(RenderOperationalMarkdown::class)->handle($source)]);
        $this->assertSame('Subject '.$source, $rendered['subject']);
        $this->assertSame($source, $rendered['text']);
        $this->assertStringNotContainsString('Do not expand user text', $rendered['html']);
        $this->assertStringContainsString('<strong>Viktig</strong>', $rendered['html']);
        $this->assertStringContainsString('<li>Punkt</li>', $rendered['html']);
        $this->assertStringContainsString('<div class="reply">', $rendered['html']);
        $this->assertStringContainsString('<header>Custom layout</header>', $rendered['html']);
        $this->assertSame('<p class="reply">{{message_body}}</p>', $template->body_html);
    }

    #[Test]
    public function calls_without_html_overrides_keep_the_existing_template_contract(): void
    {
        $template = new EmailTemplate(['subject' => '{{ message_body }}', 'body_html' => '<p>{{ message_body }}</p>',
            'body_text' => '{{ message_body }}']);
        $rendered = app(EmailTemplateRenderer::class)->render($template, ['message_body' => 'Plain fixture.']);
        $this->assertSame('Plain fixture.', $rendered['subject']);
        $this->assertSame('Plain fixture.', $rendered['text']);
        $this->assertStringContainsString('<p>Plain fixture.</p>', $rendered['html']);
    }
}
