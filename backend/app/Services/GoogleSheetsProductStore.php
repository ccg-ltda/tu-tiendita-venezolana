<?php

namespace App\Services;

use App\Products\ProductNormalizer;

class GoogleSheetsProductStore
{
    private const SHEET='Productos';
    private const HEADERS=['product_id','category','subcategory','name','presentation','price_cop','inventory','active','image_path','legacy_img','created_at','updated_at','revision'];
    public function __construct(private readonly GoogleSheetsClient $client) {}
    public function all(): array
    {
        $response=$this->request('GET',self::SHEET.'!A:M', [], [], 'read');
        $values=$response['values']??[]; if(array_slice(array_pad($values[0]??[],13,null),0,13)!==self::HEADERS) throw new ProductSheetsException(502,'INVALID_SHEET_HEADERS');
        $out=[];$ids=[]; foreach(array_slice($values,1) as $row){if(!array_filter($row,fn($v)=>$v!==''&&$v!==null))continue;$p=ProductNormalizer::sheetRow($row);if($p===null)throw new ProductSheetsException(502,'INVALID_SHEET_DATA');if(isset($ids[$p['product_id']]))throw new ProductSheetsException(502,'DUPLICATE_PRODUCT_ID');$ids[$p['product_id']]=1;$out[]=$p;}return $out;
    }
    public function find(int $id): ?array { foreach($this->all() as $p)if($p['product_id']===$id)return $p; return null; }
    public function update(array $product, int $row=null): array { $row??=$this->rowFor($product['product_id']); $this->request('PUT',self::SHEET."!A{$row}:M{$row}", ['valueInputOption'=>'RAW'], ['values'=>[ProductNormalizer::row($product)],], 'write'); return $this->find($product['product_id'])??$product; }
    public function append(array $product): array { $spec=self::appendRequestSpec($this->client->spreadsheetId(), ProductNormalizer::row($product)); $this->requestUrl('POST',$spec['url'],$spec['query'],$spec['json'],'write'); return $this->find($product['product_id'])??$product; }
    /** @return array{url:string,query:array<string,string>,json:array{values:list<list<mixed>>}} */
    public static function appendRequestSpec(string $spreadsheetId, array $values): array
    { return ['url'=>'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode(self::SHEET.'!A:M').':append','query'=>['valueInputOption'=>'RAW','insertDataOption'=>'INSERT_ROWS'],'json'=>['values'=>[$values]]]; }
    private function rowFor(int $id): int { foreach($this->all() as $index=>$p)if($p['product_id']===$id)return $index+2; throw new ProductSheetsException(404,'PRODUCT_NOT_FOUND'); }
    private function request(string $method,string $range,array $query=[],array $json=[],string $kind='read'): array { $url='https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($this->client->spreadsheetId()).'/values/'.rawurlencode($range);return $this->requestUrl($method,$url,$query,$json,$kind); }
    private function requestUrl(string $method,string $url,array $query,array $json,string $kind): array
    { try{$response=$this->client->httpClient()->request($method,$url,['query'=>$query]+($json===[]?[]:['json'=>$json]));$status=$response->getStatusCode();$data=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);if($status<200||$status>=300||!is_array($data))throw new ProductSheetsException($status>=400?$status:502,$kind==='write'?'GOOGLE_SHEETS_WRITE':'GOOGLE_SHEETS_READ');return $data;}catch(ProductSheetsException $e){throw $e;}catch(\JsonException $e){throw new ProductSheetsException(502,$kind==='write'?'GOOGLE_SHEETS_WRITE_INVALID_RESPONSE':'GOOGLE_SHEETS_INVALID_RESPONSE',$e);}catch(\Throwable $e){$status=method_exists($e,'getResponse')&&$e->getResponse()?$e->getResponse()->getStatusCode():0;throw new ProductSheetsException($status>=400?$status:503,$kind==='write'?'GOOGLE_SHEETS_WRITE_TRANSPORT':'GOOGLE_SHEETS_TRANSPORT',$e);} }
}
