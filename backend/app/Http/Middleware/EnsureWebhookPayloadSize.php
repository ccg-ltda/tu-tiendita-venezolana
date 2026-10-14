<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWebhookPayloadSize
{
    public const MAX_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return $this->tooLarge();
        }

        return $next($request);
    }

    private function tooLarge(): JsonResponse
    {
        return response()->json(['error' => 'Webhook payload too large.'], 413);
    }
}
