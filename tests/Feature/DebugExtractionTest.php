<?php
uses(Illuminate\Foundation\Testing\LazilyRefreshDatabase::class);
it('debug sink under closure fake bound var', function () {
    config(['imports.timeout'=>20,'imports.output_bytes'=>2097152]);
    $out=['ok'=>true];
    Http::fake(['https://extract.example.test/v1'=>function($request) use ($out){ return Http::response($out); }]);
    Http::preventStrayRequests();
    $stream=fopen('php://temp/maxmemory:2097152','w+b');
    try{
        $response=Http::withToken('K')->acceptJson()->withHeaders(['Idempotency-Key'=>'KEY'])
            ->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withOptions(['sink'=>$stream,'progress'=>function(int $t,int $d):void{}])
            ->post('https://extract.example.test/v1',['x'=>'y']);
        dump('HTTPSTATUS '.$response->status());
        rewind($stream); $body=stream_get_contents($stream,(int)config('imports.output_bytes')+1);
        dump('BODYLEN='.strlen($body).' raw='.substr($body,0,20));
    } catch(Throwable $e){ dump('EXC '.get_class($e).' '.$e->getMessage()); }
    finally{ fclose($stream); }
    expect(true)->toBeTrue();
});