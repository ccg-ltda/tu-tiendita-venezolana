<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Revolution\Google\Sheets\Facades\Sheets;
use Throwable;

class CheckBunzyGoogleSheet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bunzy:google-sheets:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica acceso de solo lectura a la hoja Blog de Bunzy.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $spreadsheetId = config('google.bunzy_sheet_id');

        if (blank($spreadsheetId)) {
            $this->error('GOOGLE_SHEET_ID no está configurado.');

            return self::FAILURE;
        }

        try {
            $rows = Sheets::spreadsheet($spreadsheetId)
                ->sheet('Blog')
                ->all();
        } catch (Throwable $exception) {
            $this->error('No se pudo acceder a Google Sheets: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Conexión correcta con la hoja Blog. Filas leídas: '.count($rows).'.');

        return self::SUCCESS;
    }
}
