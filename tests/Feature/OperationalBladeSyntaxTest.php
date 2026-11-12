<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class OperationalBladeSyntaxTest extends TestCase
{
    #[Test]
    public function operational_templates_compile_to_valid_php(): void
    {
        // Compile complete templates: mixed PHP directives can hide unmatched control blocks.
        $files = Finder::create()->files()->name('*.blade.php')->in([
            app_path('Modules/Ticket/Views'),
            app_path('Modules/Task/Views'),
            app_path('Modules/Commercial/Views'),
        ]);
        $errors = [];
        $count = 0;
        foreach ($files as $file) {
            $count++;
            try {
                token_get_all(Blade::compileString($file->getContents()), TOKEN_PARSE);
            } catch (\ParseError $error) {
                $errors[] = $file->getRealPath().': '.$error->getMessage();
            }
        }

        $this->assertGreaterThan(0, $count);
        $this->assertSame([], $errors, implode("\n", $errors));
    }
}
