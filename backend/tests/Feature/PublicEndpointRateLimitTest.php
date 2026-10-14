<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicEndpointRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_products_returns_429_after_the_catalog_limit(): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/products');
        }

        $this->getJson('/api/products')->assertTooManyRequests();
    }

    public function test_blog_list_returns_429_after_the_blog_limit(): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/blog');
        }

        $this->getJson('/api/blog')->assertTooManyRequests();
    }

    public function test_blog_detail_returns_429_after_the_blog_limit(): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/blog');
        }

        $this->getJson('/api/blog/any-slug')->assertTooManyRequests();
    }

    public function test_coupon_preview_returns_429_after_its_limit(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/checkout/coupon/preview', ['code' => 'TEST', 'items' => []]);
        }

        $this->postJson('/api/checkout/coupon/preview', ['code' => 'TEST', 'items' => []])
            ->assertTooManyRequests();
    }

    public function test_an_unthrottled_coupon_preview_request_reaches_its_existing_handler(): void
    {
        $this->postJson('/api/checkout/coupon/preview', ['code' => 'TEST', 'items' => []])
            ->assertUnprocessable();
    }
}
