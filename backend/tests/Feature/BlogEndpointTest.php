<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Repositories\MySqlBlogPostRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BlogEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_the_blog_list_returns_only_published_articles(): void
    {
        $this->createBlogPostFixture('Publicado', 'publicado', '2026-10-13', 'published');

        $this->getJson('/api/blog')
            ->assertOk()
            ->assertExactJson([$this->listContract(1, 'Publicado', 'publicado', '2026-10-13')]);
    }

    public function test_the_blog_list_excludes_drafts(): void
    {
        $this->createBlogPostFixture('Publicado', 'publicado', '2026-10-13', 'published');
        $this->createBlogPostFixture('Borrador', 'borrador', '2026-10-14', 'draft');

        $this->getJson('/api/blog')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'publicado');
    }

    public function test_the_blog_list_does_not_include_content(): void
    {
        $this->createBlogPostFixture('Publicado', 'publicado', '2026-10-13', 'published');

        $article = $this->getJson('/api/blog')->assertOk()->json('0');

        $this->assertArrayNotHasKey('content', $article);
    }

    public function test_the_blog_list_is_ordered_by_date_then_id_descending(): void
    {
        $this->createBlogPostFixture('Antiguo', 'antiguo', '2026-10-11', 'published');
        $this->createBlogPostFixture('Misma fecha menor id', 'misma-fecha-menor', '2026-10-13', 'published');
        $this->createBlogPostFixture('Misma fecha mayor id', 'misma-fecha-mayor', '2026-10-13', 'published');
        $this->createBlogPostFixture('Reciente', 'reciente', '2026-10-14', 'published');

        $this->getJson('/api/blog')
            ->assertOk()
            ->assertJsonPath('0.id', 4)
            ->assertJsonPath('1.id', 3)
            ->assertJsonPath('2.id', 2)
            ->assertJsonPath('3.id', 1);
    }

    public function test_the_blog_list_returns_a_controlled_error_when_persistence_fails(): void
    {
        $repository = Mockery::mock(MySqlBlogPostRepository::class);
        $repository->shouldReceive('publishedArticles')->once()->andThrow(new \RuntimeException('database unavailable'));
        $this->app->instance(MySqlBlogPostRepository::class, $repository);

        $this->getJson('/api/blog')
            ->assertStatus(503)
            ->assertExactJson($this->unavailableResponse());
    }

    public function test_a_published_article_can_be_retrieved_by_slug(): void
    {
        $this->createBlogPostFixture('Publicado', 'publicado', '2026-10-13', 'published');

        $this->getJson('/api/blog/publicado')
            ->assertOk()
            ->assertExactJson([
                'id' => 1,
                'title' => 'Publicado',
                'slug' => 'publicado',
                'content' => '<p>Contenido de Publicado</p>',
                'image_url' => 'https://ejemplo.com/publicado.jpg',
                'published_at' => '2026-10-13',
            ]);
    }

    public function test_a_draft_article_is_not_found_publicly(): void
    {
        $this->createBlogPostFixture('Borrador', 'borrador', '2026-10-13', 'draft');

        $this->getJson('/api/blog/borrador')
            ->assertNotFound()
            ->assertExactJson($this->notFoundResponse());
    }

    public function test_an_unknown_article_is_not_found(): void
    {
        $this->createBlogPostFixture('Publicado', 'publicado', '2026-10-13', 'published');

        $this->getJson('/api/blog/inexistente')
            ->assertNotFound()
            ->assertExactJson($this->notFoundResponse());
    }

    public function test_an_article_request_returns_a_controlled_error_when_persistence_fails(): void
    {
        $repository = Mockery::mock(MySqlBlogPostRepository::class);
        $repository->shouldReceive('publishedBySlug')->once()->with('publicado')->andThrow(new \RuntimeException('database unavailable'));
        $this->app->instance(MySqlBlogPostRepository::class, $repository);

        $this->getJson('/api/blog/publicado')
            ->assertStatus(503)
            ->assertExactJson($this->unavailableResponse());
    }

    private function createBlogPostFixture(string $title, string $slug, string $publishedAt, string $status): BlogPost
    {
        return BlogPost::query()->create([
            'title' => $title,
            'slug' => $slug,
            'content' => '<p>Contenido de '.$title.'</p>',
            'image_url' => 'https://ejemplo.com/'.$slug.'.jpg',
            'published_at' => $publishedAt,
            'status' => $status,
        ]);
    }

    /** @return array{id:int,title:string,slug:string,image_url:string,published_at:string} */
    private function listContract(int $id, string $title, string $slug, string $publishedAt): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'image_url' => 'https://ejemplo.com/'.$slug.'.jpg',
            'published_at' => $publishedAt,
        ];
    }

    /** @return array{success:bool,message:string} */
    private function notFoundResponse(): array
    {
        return ['success' => false, 'message' => 'Artículo no encontrado.'];
    }

    /** @return array{success:bool,message:string} */
    private function unavailableResponse(): array
    {
        return ['success' => false, 'message' => 'No fue posible cargar el blog.'];
    }
}
