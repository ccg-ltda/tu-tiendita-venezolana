<?php

namespace Tests\Unit;

use App\Services\ProductImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageStoreTest extends TestCase
{
    public function test_it_stores_new_product_images_on_the_public_disk(): void
    {
        Storage::fake('public');
        $path = (new ProductImageStore())->store($this->validPngUpload());

        $this->assertMatchesRegularExpression('#^/storage/products/[a-f0-9]{32}\\.png$#', $path);
        Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
    }

    public function test_it_deletes_only_new_product_images_from_the_public_disk(): void
    {
        Storage::fake('public');
        $store = new ProductImageStore();
        $path = $store->store($this->validPngUpload());
        $storedPath = substr($path, strlen('/storage/'));
        Storage::disk('public')->put('assets/products/01.jpg', 'legacy');

        $store->forget('/assets/products/01.jpg');
        Storage::disk('public')->assertExists('assets/products/01.jpg');

        $store->forget($path);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_it_builds_an_absolute_api_url_only_for_new_storage_paths(): void
    {
        config()->set('filesystems.disks.public.url', 'https://api.tutienditavenezolana.com/storage');
        $store = new ProductImageStore();

        $this->assertSame('https://api.tutienditavenezolana.com/storage/products/'.str_repeat('a', 32).'.png', $store->publicUrl('/storage/products/'.str_repeat('a', 32).'.png'));
        $this->assertSame('/assets/products/01.jpg', $store->publicUrl('/assets/products/01.jpg'));
    }

    private function validPngUpload(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent('product.png', $png === false ? '' : $png);
    }
}
