<?php
namespace App\Services;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;

final class GoogleSheetsClient
{
    private const SCOPE='https://www.googleapis.com/auth/spreadsheets';
    private ?GuzzleClient $http=null;
    public function httpClient(): GuzzleClient
    {
        if($this->http)return $this->http;
        $credentials=config('services.google_sheets.credentials');$spreadsheet=config('services.google_sheets.spreadsheet_id');
        if(!is_string($credentials)||$credentials===''||!is_file($credentials)||!is_string($spreadsheet)||$spreadsheet==='')throw new ProductSheetsException(503,'GOOGLE_SHEETS_CONFIGURATION');
        try{$serviceCredentials=new ServiceAccountCredentials(self::SCOPE,$credentials);$serviceCredentials->useJwtAccessWithScope();$http=new GuzzleClient(['connect_timeout'=>10,'timeout'=>60,'verify'=>true,'force_ip_resolve'=>'v4','on_stats'=>function(TransferStats $stats):void{$response=$stats->hasResponse()?$stats->getResponse():null;$s=$stats->getHandlerStats();Log::debug('Google Sheets HTTP transfer stats.',['effective_uri'=>$this->safeUri($stats),'has_response'=>$stats->hasResponse(),'response_status'=>$response?->getStatusCode(),'transfer_time'=>$stats->getTransferTime(),'handler_error_data'=>$stats->getHandlerErrorData(),'handler_stats'=>array_intersect_key($s,array_flip(['primary_ip','primary_port','local_ip','local_port','namelookup_time','connect_time','appconnect_time','pretransfer_time','starttransfer_time','total_time','http_version','ssl_verifyresult','num_connects']))]);}]);$google=new Client(['credentials'=>$serviceCredentials,'scopes'=>[self::SCOPE]]);$google->setHttpClient($http);if($google->getHttpClient()!==$http)throw new \LogicException('Google Sheets HTTP client was not applied.');$this->http=$google->authorize();return $this->http;}catch(\Throwable $e){throw new ProductSheetsException(503,'GOOGLE_SHEETS_CLIENT', $e);}
    }
    public function spreadsheetId(): string { $id=config('services.google_sheets.spreadsheet_id');if(!is_string($id)||$id==='')throw new ProductSheetsException(503,'GOOGLE_SHEETS_CONFIGURATION');return $id; }
    private function safeUri(TransferStats $stats): string { $u=$stats->getEffectiveUri();$path=preg_replace('#(/spreadsheets/)[^/]+#','$1[redacted]',$u->getPath())?:'/';return $u->getScheme().'://'.$u->getHost().$path; }
}
