<?php

namespace App\Http\Controllers;

use App\Repositories\MySqlBlogPostRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BlogController extends Controller
{
    public function index(MySqlBlogPostRepository $posts): JsonResponse
    {
        try {
            $articles = $posts->publishedArticles();
        } catch (\Throwable $exception) {
            return $this->unavailable($exception);
        }

        return response()->json($articles);
    }

    public function show(string $slug, MySqlBlogPostRepository $posts): JsonResponse
    {
        try {
            $article = $posts->publishedBySlug($slug);
        } catch (\Throwable $exception) {
            return $this->unavailable($exception);
        }

        if ($article === null) {
            return response()->json([
                'success' => false,
                'message' => 'Artículo no encontrado.',
            ], 404);
        }

        return response()->json($article);
    }

    private function unavailable(\Throwable $exception): JsonResponse
    {
        Log::error('Bunzy blog could not be loaded from MySQL.', [
            'exception' => $exception,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'No fue posible cargar el blog.',
        ], 503);
    }
}
