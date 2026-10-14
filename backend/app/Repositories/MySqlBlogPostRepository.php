<?php

namespace App\Repositories;

use App\Models\BlogPost;

class MySqlBlogPostRepository
{
    /** @return list<array{id:int,title:string,slug:string,image_url:?string,published_at:?string}> */
    public function publishedArticles(): array
    {
        return BlogPost::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BlogPost $post): array => $this->listContract($post))
            ->all();
    }

    /** @return array{id:int,title:string,slug:string,content:string,image_url:?string,published_at:?string}|null */
    public function publishedBySlug(string $slug): ?array
    {
        $post = BlogPost::query()
            ->where('slug', trim($slug))
            ->where('status', 'published')
            ->first();

        return $post === null ? null : $this->detailContract($post);
    }

    /** @return array{id:int,title:string,slug:string,image_url:?string,published_at:?string} */
    private function listContract(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'image_url' => $post->image_url,
            'published_at' => $post->published_at?->toDateString(),
        ];
    }

    /** @return array{id:int,title:string,slug:string,content:string,image_url:?string,published_at:?string} */
    private function detailContract(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'content' => $post->content,
            'image_url' => $post->image_url,
            'published_at' => $post->published_at?->toDateString(),
        ];
    }
}
