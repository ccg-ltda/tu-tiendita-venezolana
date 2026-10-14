<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Services\BlogHtmlSanitizer;
use App\Services\BunzyBlogSheet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BunzyController extends Controller
{
    private const REPLAY_TTL_MINUTES = 30;

    public function webhook(Request $request, BunzyBlogSheet $sheet, BlogHtmlSanitizer $sanitizer): JsonResponse
    {
        $rawBody = $request->getContent();
        if (! $this->hasValidSignature($request, $rawBody)) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 401);
        }

        $replayKey = 'bunzy:webhook:'.hash('sha256', $rawBody);
        try {
            if (! Cache::add($replayKey, true, now()->addMinutes(self::REPLAY_TTL_MINUTES))) {
                Log::info('Bunzy webhook replay ignored.', ['payload_hash' => substr(hash('sha256', $rawBody), 0, 12)]);

                return response()->json(['success' => true, 'replayed' => true]);
            }
        } catch (\Throwable) {
            $replayKey = null;
            Log::warning('Bunzy webhook replay cache unavailable.');
        }

        $payload = $request->json()->all();

        if (($payload['test'] ?? null) === true) {
            return response()->json([
                'success' => true,
                'message' => 'Webhook Bunzy verificado.',
            ]);
        }

        $eventType = $payload['event_type'] ?? null;
        $article = $payload['data']['article'] ?? null;

        if (! is_string($eventType) || ! is_array($article)) {
            return $this->invalidPayloadResponse();
        }

        $response = match ($eventType) {
            'article.published', 'article.updated' => $this->upsertPublishedArticle($article, $sheet, $sanitizer),
            'article.unpublished' => $this->unpublishArticle($article, $sheet),
            default => $this->invalidPayloadResponse(),
        };

        if ($replayKey !== null && $response->getStatusCode() >= 500) {
            try {
                Cache::forget($replayKey);
            } catch (\Throwable) {
                Log::warning('Bunzy webhook replay cache could not be cleared after a failed request.');
            }
        }

        return $response;
    }

    /** @param array<string, mixed> $article */
    private function upsertPublishedArticle(array $article, BunzyBlogSheet $sheet, BlogHtmlSanitizer $sanitizer): JsonResponse
    {
        $html = $article['html'] ?? null;
        $input = [
            'title' => $article['title'] ?? null,
            'slug' => $article['slug'] ?? null,
            'content' => is_string($html) ? $sanitizer->sanitize($html) : $html,
            'image_url' => $this->imageUrl($article),
            'published_at' => $article['published_at'] ?? $article['publishedAt'] ?? null,
        ];
        $validator = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'slug' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url'],
            'published_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Los datos enviados no son válidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $slug = trim($validated['slug']);
        $publishedAt = $validated['published_at'] !== null
            ? Carbon::parse($validated['published_at'])->toDateString()
            : now()->toDateString();

        try {
            $post = DB::connection('mysql')->transaction(static function () use ($validated, $slug, $publishedAt): BlogPost {
                $post = BlogPost::query()->where('slug', $slug)->lockForUpdate()->first() ?? new BlogPost(['slug' => $slug]);
                $post->fill([
                    'title' => $validated['title'],
                    'slug' => $slug,
                    'content' => $validated['content'],
                    'image_url' => $validated['image_url'] ?? null,
                    'published_at' => $publishedAt,
                    'status' => 'published',
                    'sheet_synced_at' => null,
                ]);
                $post->save();

                return $post;
            });
        } catch (\Throwable $exception) {
            return $this->mysqlUnavailable($exception, $slug);
        }

        return $this->synchronizeAndRespond($post, $sheet);
    }

    /** @param array<string, mixed> $article */
    private function unpublishArticle(array $article, BunzyBlogSheet $sheet): JsonResponse
    {
        $validator = Validator::make(['slug' => $article['slug'] ?? null], [
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->invalidPayloadResponse();
        }

        $slug = trim($validator->validated()['slug']);

        try {
            $post = DB::connection('mysql')->transaction(static function () use ($slug): ?BlogPost {
                $post = BlogPost::query()->where('slug', $slug)->lockForUpdate()->first();

                if ($post === null) {
                    return null;
                }

                $post->update(['status' => 'draft', 'sheet_synced_at' => null]);

                return $post;
            });
        } catch (\Throwable $exception) {
            return $this->mysqlUnavailable($exception, $slug);
        }

        if ($post === null) {
            Log::notice('Bunzy unpublished event received for an unknown blog slug.', ['slug' => $slug]);

            return response()->json(['success' => true, 'slug' => $slug, 'sheet_synced' => false]);
        }

        return $this->synchronizeAndRespond($post, $sheet);
    }

    private function synchronizeAndRespond(BlogPost $post, BunzyBlogSheet $sheet): JsonResponse
    {
        $sheetSynced = false;

        try {
            $sheet->syncArticle(
                $post->id,
                $post->title,
                $post->slug,
                $post->content,
                $post->image_url ?? '',
                $post->published_at->toDateString(),
                $post->status,
            );
            $post->update(['sheet_synced_at' => now()]);
            $sheetSynced = true;
        } catch (\Throwable $exception) {
            Log::error('Bunzy blog article could not be synchronized to Google Sheets.', [
                'exception' => $exception,
                'blog_post_id' => $post->id,
                'slug' => $post->slug,
            ]);
        }

        return response()->json([
            'success' => true,
            'id' => $post->id,
            'slug' => $post->slug,
            'sheet_synced' => $sheetSynced,
        ], 201);
    }

    /** @param array<string, mixed> $article */
    private function imageUrl(array $article): mixed
    {
        return $article['thumbnailUrl'] ?? data_get($article, 'seo.ogImage') ?? null;
    }

    private function hasValidSignature(Request $request, string $rawBody): bool
    {
        $signature = $request->header('X-Bunzy-Signature');
        $secret = config('services.bunzy.secret');

        if (! is_string($signature) || $signature === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function invalidPayloadResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Los datos enviados no son válidos.',
        ], 422);
    }

    private function mysqlUnavailable(\Throwable $exception, string $slug): JsonResponse
    {
        Log::error('Bunzy blog article could not be persisted to MySQL.', [
            'exception' => $exception,
            'slug' => $slug,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'No fue posible guardar el artículo.',
        ], 503);
    }
}
