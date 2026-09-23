<?php
namespace Tests\Unit\Services;
use App\Services\GoogleSheetsProductStore;
use App\Services\ProductSheetsException;
use Tests\TestCase;

class GoogleSheetsProductStoreTest extends TestCase
{
    public function test_append_request_keeps_append_suffix_outside_encoded_range(): void
    {
        $spec = GoogleSheetsProductStore::appendRequestSpec('spreadsheet-test', [200, 'Pruebas', 'Validacion', 'Producto E2E', 'Unidad', 1500, 5, true, '/assets/products/e0190519ec63ed088b0b194d581382bc.png', null, 'created', 'updated', 1]);
        $this->assertSame('https://sheets.googleapis.com/v4/spreadsheets/spreadsheet-test/values/Productos%21A%3AM:append', $spec['url']);
        $this->assertStringNotContainsString('%3Aappend', $spec['url']);
        $this->assertSame('RAW', $spec['query']['valueInputOption']);
        $this->assertSame('INSERT_ROWS', $spec['query']['insertDataOption']);
        $this->assertSame(13, count($spec['json']['values'][0]));
        $this->assertSame(200, $spec['json']['values'][0][0]);
        $this->assertSame(1, $spec['json']['values'][0][12]);
    }

    public function test_write_http_error_has_write_specific_internal_message(): void
    {
        $exception = new ProductSheetsException(400, 'GOOGLE_SHEETS_WRITE');
        $this->assertSame(400, $exception->status());
        $this->assertSame('No fue posible sincronizar el producto con Google Sheets.', $exception->getMessage());
    }
}
