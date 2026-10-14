<?php

namespace Tests\Unit\Services;

use App\Services\BlogHtmlSanitizer;
use Tests\TestCase;

class BlogHtmlSanitizerTest extends TestCase
{
    private BlogHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new BlogHtmlSanitizer();
    }

    public function test_it_removes_active_elements_and_event_attributes(): void
    {
        $sanitized = $this->sanitizer->sanitize(
            '<p onclick="alert(1)">Texto</p>'
            .'<script>alert(1)</script>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<svg onload="alert(1)"></svg>'
            .'<img src="https://example.com/a.jpg" onerror="alert(1)">',
        );

        $this->assertStringContainsString('<p>Texto</p>', $sanitized);
        $this->assertStringContainsString('<img src="https://example.com/a.jpg" loading="lazy">', $sanitized);
        $this->assertStringNotContainsString('script', $sanitized);
        $this->assertStringNotContainsString('iframe', $sanitized);
        $this->assertStringNotContainsString('svg', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('alert(1)', $sanitized);
    }

    public function test_it_removes_unsafe_link_protocols(): void
    {
        $sanitized = $this->sanitizer->sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertSame('<a>click</a>', $sanitized);
        $this->assertSame('<a>click</a>', $this->sanitizer->sanitize('<a href="JaVaScRiPt&#58;alert(1)">click</a>'));
    }

    public function test_it_preserves_allowed_editorial_html_and_secures_blank_links(): void
    {
        $sanitized = $this->sanitizer->sanitize(
            '<p>Texto <strong>seguro</strong></p>'
            .'<h2>Título</h2>'
            .'<img src="https://cdn.example.com/image.webp" alt="Producto">'
            .'<a href="https://example.com" target="_blank">Enlace</a>',
        );

        $this->assertStringContainsString('<p>Texto <strong>seguro</strong></p>', $sanitized);
        $this->assertStringContainsString('<h2>Título</h2>', $sanitized);
        $this->assertStringContainsString('<img src="https://cdn.example.com/image.webp" alt="Producto" loading="lazy">', $sanitized);
        $this->assertStringContainsString(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer">Enlace</a>',
            $sanitized,
        );
    }

    public function test_it_handles_null_and_empty_html(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }
}
