<?php

declare(strict_types=1);

namespace Tests\Unit\Services\WebChat;

use App\Services\WebChat\SimpleTextPdf;
use PHPUnit\Framework\TestCase;

final class SimpleTextPdfTest extends TestCase
{
    public function test_render_builds_a_readable_pdf(): void
    {
        $pdf = SimpleTextPdf::render('Cover letter', "Dear Hiring Manager,\n\nI am writing to apply.");

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertStringContainsString('Dear Hiring Manager', $pdf);
        $this->assertStringContainsString('I am writing to apply.', $pdf);
    }
}
