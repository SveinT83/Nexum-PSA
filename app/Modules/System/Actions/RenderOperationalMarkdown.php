<?php

namespace App\Modules\System\Actions;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/** Shared presentation only: callers retain source ownership and authorize before rendering. */
final class RenderOperationalMarkdown
{
    public function handle(?string $source): string
    {
        if ($source === null || $source === '') {
            return '';
        }
        try {
            $environment = new Environment([
                'html_input' => 'escape', 'allow_unsafe_links' => false,
                'max_nesting_level' => 32, 'max_delimiters_per_line' => 1000,
                'renderer' => ['soft_break' => "<br />\n"],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            // Images remain readable alt text; viewing work must not fetch remote tracking pixels.
            $environment->addRenderer(Image::class, new class implements NodeRendererInterface
            {
                public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
                {
                    Image::assertInstanceOf($node);

                    return $childRenderer->renderNodes($node->children());
                }
            }, 100);

            return (string) (new MarkdownConverter($environment))->convert($source);
        } catch (\Throwable) {
            // Escaped source is useful even when a malformed document cannot be parsed.
            return nl2br(e($source));
        }
    }
}
