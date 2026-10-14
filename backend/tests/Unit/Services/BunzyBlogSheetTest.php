<?php

namespace Tests\Unit\Services;

use App\Services\BunzyBlogSheet;
use Revolution\Google\Sheets\Facades\Sheets;
use Tests\TestCase;

final class BunzyBlogSheetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('google.bunzy_sheet_id', 'bunzy-test-sheet');
    }

    protected function tearDown(): void
    {
        Sheets::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_formula_like_text_is_neutralized_before_syncing_to_google_sheets(): void
    {
        $this->expectAppend([
            42,
            "'=SUM(1,1)",
            "'-external-slug",
            "'+external-content",
            "'@external-image",
            '2026-10-14',
            "'=published",
        ]);

        (new BunzyBlogSheet)->syncArticle(
            42,
            '=SUM(1,1)',
            '-external-slug',
            '+external-content',
            '@external-image',
            '2026-10-14',
            '=published',
        );
    }

    public function test_normal_article_values_are_synced_without_modification(): void
    {
        $this->expectAppend([
            42,
            'Titulo normal',
            'titulo-normal',
            '<p>Contenido normal</p>',
            'https://cdn.example.test/article.webp',
            '2026-10-14',
            'published',
        ]);

        (new BunzyBlogSheet)->syncArticle(
            42,
            'Titulo normal',
            'titulo-normal',
            '<p>Contenido normal</p>',
            'https://cdn.example.test/article.webp',
            '2026-10-14',
            'published',
        );
    }

    /** @param array{0:int,1:string,2:string,3:string,4:string,5:string,6:string} $row */
    private function expectAppend(array $row): void
    {
        Sheets::shouldReceive('spreadsheet')->twice()->with('bunzy-test-sheet')->andReturnSelf();
        Sheets::shouldReceive('sheet')->twice()->with('Blog')->andReturnSelf();
        Sheets::shouldReceive('all')->once()->andReturn([]);
        Sheets::shouldReceive('append')->once()->with([$row]);
    }
}
