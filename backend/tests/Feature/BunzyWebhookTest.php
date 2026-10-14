<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Services\BunzyBlogSheet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BunzyWebhookTest extends TestCase
{
    private const SECRET = 'bunzy-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bunzy.secret', self::SECRET);
        Cache::flush();
        RateLimiter::clear('bunzy-webhook:127.0.0.1');
        config()->set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('mysql');
        Schema::connection('mysql')->create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->text('image_url')->nullable();
            $table->date('published_at')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamp('sheet_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_valid_hmac_signature_processes_the_request(): void
    {
        $this->bindSuccessfulSheet();

        $this->signedWebhook($this->articlePayload('article.published'))
            ->assertCreated()
            ->assertJsonPath('sheet_synced', true);
    }

    public function test_an_invalid_hmac_signature_is_rejected(): void
    {
        $this->bindUnusedSheet();

        $this->signedWebhook($this->articlePayload('article.published'), 'sha256=invalid')
            ->assertUnauthorized();

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_a_missing_hmac_signature_is_rejected(): void
    {
        $this->bindUnusedSheet();

        $this->rawWebhook($this->encodePayload($this->articlePayload('article.published')))
            ->assertUnauthorized();

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_a_signed_verification_request_returns_ok_without_persisting_or_syncing(): void
    {
        $this->bindUnusedSheet();

        $this->signedWebhook(['test' => true, 'slug' => 'sample-post'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Webhook Bunzy verificado.',
            ]);

        $this->assertSame(0, BlogPost::query()->count());
    }

    public function test_article_published_creates_a_blog_post(): void
    {
        $this->bindSuccessfulSheet();

        $this->signedWebhook($this->articlePayload('article.published'))->assertCreated();

        $post = BlogPost::query()->where('slug', 'url-amigable')->firstOrFail();
        $this->assertSame('Título del artículo', $post->title);
        $this->assertSame('<p>Contenido HTML del artículo</p>', $post->content);
        $this->assertSame('published', $post->status);
    }

    public function test_formula_like_values_are_preserved_in_mysql_before_the_sheet_sync(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            1,
            '=SUM(1,1)',
            '-external-slug',
            '+external-content',
            'https://cdn.bunzy.io/media/thumbnails/article.webp',
            '2026-10-13',
            'published',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published', [
            'title' => '=SUM(1,1)',
            'slug' => '-external-slug',
            'html' => '+external-content',
        ]))->assertCreated();

        $post = BlogPost::query()->firstOrFail();
        $this->assertSame('=SUM(1,1)', $post->title);
        $this->assertSame('-external-slug', $post->slug);
        $this->assertSame('+external-content', $post->content);
    }

    public function test_bunzy_html_is_sanitized_before_mysql_and_google_sheets(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            1,
            'Título del artículo',
            'url-amigable',
            Mockery::on(static function ($content): bool {
                return is_string($content)
                    && trim($content) === '<p>Seguro</p>'
                    && ! str_contains($content, '<script')
                    && ! str_contains($content, 'alert(1)');
            }),
            'https://cdn.bunzy.io/media/thumbnails/article.webp',
            '2026-10-13',
            'published',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published', [
            'html' => '<p>Seguro</p><script>alert(1)</script>',
        ]))->assertCreated();

        $content = BlogPost::query()->firstOrFail()->content;
        $this->assertSame('<p>Seguro</p>', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('alert(1)', $content);
    }

    public function test_thumbnail_url_is_persisted_and_sent_to_google_sheets(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            1,
            'Título del artículo',
            'url-amigable',
            '<p>Contenido HTML del artículo</p>',
            'https://cdn.bunzy.io/media/thumbnails/article.webp',
            '2026-10-13',
            'published',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published'))->assertCreated();

        $this->assertSame('https://cdn.bunzy.io/media/thumbnails/article.webp', BlogPost::query()->firstOrFail()->image_url);
    }

    public function test_seo_og_image_is_used_when_thumbnail_url_is_missing(): void
    {
        $this->bindSuccessfulSheet();

        $this->signedWebhook($this->articlePayload('article.published', [
            'thumbnailUrl' => null,
            'seo' => ['ogImage' => 'https://cdn.bunzy.io/media/seo/article.webp'],
        ]))->assertCreated();

        $this->assertSame('https://cdn.bunzy.io/media/seo/article.webp', BlogPost::query()->firstOrFail()->image_url);
    }

    public function test_image_url_is_null_when_bunzy_provides_no_thumbnail_or_og_image(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            1,
            'Título del artículo',
            'url-amigable',
            '<p>Contenido HTML del artículo</p>',
            '',
            '2026-10-13',
            'published',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published', ['thumbnailUrl' => null]))->assertCreated();

        $this->assertNull(BlogPost::query()->firstOrFail()->image_url);
    }

    public function test_a_repeated_article_published_event_updates_without_creating_a_duplicate(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->twice();
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published'))->assertCreated();
        $this->signedWebhook($this->articlePayload('article.published', ['html' => '<p>Contenido reenviado</p>']))->assertCreated();

        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame('<p>Contenido reenviado</p>', BlogPost::query()->firstOrFail()->content);
    }

    public function test_article_updated_updates_the_existing_article(): void
    {
        $post = $this->createPost();
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            $post->id,
            'Título actualizado',
            'url-amigable',
            '<p>Contenido actualizado</p>',
            'https://cdn.bunzy.io/media/thumbnails/article.webp',
            '2026-10-13',
            'published',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.updated', [
            'title' => 'Título actualizado',
            'html' => '<p>Contenido actualizado</p>',
        ]))->assertCreated();

        $post->refresh();
        $this->assertSame('Título actualizado', $post->title);
        $this->assertSame('<p>Contenido actualizado</p>', $post->content);
    }

    public function test_article_unpublished_changes_the_article_to_draft(): void
    {
        $post = $this->createPost();
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->with(
            $post->id,
            $post->title,
            $post->slug,
            $post->content,
            $post->image_url,
            '2026-10-13',
            'draft',
        );
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook([
            'event_type' => 'article.unpublished',
            'data' => ['article' => ['slug' => 'url-amigable']],
        ])->assertCreated();

        $this->assertSame('draft', $post->fresh()->status);
    }

    public function test_unpublished_articles_are_not_returned_by_the_public_blog_endpoints(): void
    {
        $post = $this->createPost();
        $this->bindSuccessfulSheet();

        $this->signedWebhook([
            'event_type' => 'article.unpublished',
            'data' => ['article' => ['slug' => $post->slug]],
        ])->assertCreated();

        $this->getJson('/api/blog')->assertOk()->assertExactJson([]);
        $this->getJson('/api/blog/'.$post->slug)->assertNotFound();
    }

    public function test_a_sheet_failure_keeps_the_mysql_article(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once()->andThrow(new \RuntimeException('Google unavailable'));
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published'))
            ->assertCreated()
            ->assertJsonPath('sheet_synced', false);

        $post = BlogPost::query()->firstOrFail();
        $this->assertSame('url-amigable', $post->slug);
        $this->assertNull($post->sheet_synced_at);
    }

    public function test_the_signature_is_calculated_from_the_exact_raw_body(): void
    {
        $this->bindSuccessfulSheet();
        $rawBody = "{\n  \"event_type\": \"article.published\",\n  \"data\": {\n    \"article\": {\n      \"title\": \"Título del artículo\",\n      \"slug\": \"url-amigable\",\n      \"html\": \"<p>Contenido HTML del artículo</p>\",\n      \"thumbnailUrl\": \"https://cdn.bunzy.io/media/thumbnails/article.webp\",\n      \"published_at\": \"2026-10-13\"\n    }\n  }\n}";

        $this->rawWebhook($rawBody, $this->signature($rawBody))->assertCreated();
    }

    public function test_an_exact_signed_replay_returns_success_without_a_second_write(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once();
        $this->app->instance(BunzyBlogSheet::class, $sheet);
        $payload = $this->articlePayload('article.published');

        $this->signedWebhook($payload)->assertCreated();
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, BlogPost::query()->count());
    }

    public function test_a_different_signed_payload_for_the_same_slug_is_processed(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->twice();
        $this->app->instance(BunzyBlogSheet::class, $sheet);

        $this->signedWebhook($this->articlePayload('article.published'))->assertCreated();
        $this->signedWebhook($this->articlePayload('article.updated', ['html' => '<p>Contenido nuevo</p>']))->assertCreated();

        $this->assertSame('<p>Contenido nuevo</p>', BlogPost::query()->firstOrFail()->content);
    }

    public function test_an_oversized_bunzy_payload_is_rejected_before_processing(): void
    {
        $this->bindUnusedSheet();

        $this->rawWebhook(str_repeat('x', \App\Http\Middleware\EnsureWebhookPayloadSize::MAX_BYTES + 1), 'sha256=invalid')
            ->assertStatus(413);
    }

    public function test_the_bunzy_webhook_rate_limit_is_applied(): void
    {
        $this->bindUnusedSheet();
        $rawBody = '{}';

        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->rawWebhook($rawBody, 'sha256=invalid')->assertUnauthorized();
        }

        $this->rawWebhook($rawBody, 'sha256=invalid')->assertTooManyRequests();
    }

    private function bindSuccessfulSheet(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldReceive('syncArticle')->once();
        $this->app->instance(BunzyBlogSheet::class, $sheet);
    }

    private function bindUnusedSheet(): void
    {
        $sheet = Mockery::mock(BunzyBlogSheet::class);
        $sheet->shouldNotReceive('syncArticle');
        $this->app->instance(BunzyBlogSheet::class, $sheet);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function articlePayload(string $eventType, array $overrides = []): array
    {
        return [
            'event_type' => $eventType,
            'data' => ['article' => array_replace([
                'title' => 'Título del artículo',
                'slug' => 'url-amigable',
                'html' => '<p>Contenido HTML del artículo</p>',
                'thumbnailUrl' => 'https://cdn.bunzy.io/media/thumbnails/article.webp',
                'published_at' => '2026-10-13',
            ], $overrides)],
        ];
    }

    private function createPost(): BlogPost
    {
        return BlogPost::query()->create([
            'title' => 'Título del artículo',
            'slug' => 'url-amigable',
            'content' => '<p>Contenido HTML del artículo</p>',
            'image_url' => 'https://ejemplo.com/imagen.jpg',
            'published_at' => '2026-10-13',
            'status' => 'published',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function signedWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $rawBody = $this->encodePayload($payload);

        return $this->rawWebhook($rawBody, $signature ?? $this->signature($rawBody));
    }

    private function rawWebhook(string $rawBody, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_BUNZY_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/bunzy/webhook', [], [], [], $server, $rawBody);
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function signature(string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, self::SECRET);
    }
}
