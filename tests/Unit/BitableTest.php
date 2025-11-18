<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Mockery\Matcher\AnyArgs;
use Yuxin\Feishu\Bitable;
use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Exceptions\HttpException;
use Yuxin\Feishu\HttpClient;
use Yuxin\Feishu\Wiki;

beforeEach(function (): void {
    $this->appId     = 'app_id';
    $this->appSecret = 'app_secret';
    $this->appToken  = 'app_token';
    $this->tableId   = 'tbl123';
    $this->recordId  = 'rec456';

    $this->token = 'mock_token';

    $this->accessToken = Mockery::mock(AccessTokenInterface::class);
    $this->accessToken->allows()->getToken()->andReturn($this->token);
});

afterEach(function (): void {
    Mockery::close();
});

test('get http client instance', function (): void {
    $bitable = new Bitable($this->appId, $this->appSecret);

    expect($bitable->getHttpClient())->toBeInstanceOf(HttpClient::class);
});

test('set guzzle options', function (): void {
    $bitable = new Bitable($this->appId, $this->appSecret);

    expect($bitable->getHttpClient()->getClient()->getConfig('timeout'))->toBeNull();

    $bitable->setGuzzleOptions(['timeout' => 10]);

    expect($bitable->getHttpClient()->getClient()->getConfig('timeout'))->toBe(10);
});

describe('record operations', function (): void {
    test('create record success', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'rec001',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('POST', 'bitable/v1/apps/app_token/tables/tbl123/records', [
                'json' => [
                    'fields' => ['Name' => 'Yomov'],
                ],
                'query' => [
                    'user_id_type' => 'open_id',
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);

        $data = $bitable->createRecord($this->appToken, $this->tableId, ['Name' => 'Yomov']);

        expect($data)->toBe(['record_id' => 'rec001']);
    });

    test('create record with custom query params', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'rec777',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('POST', 'bitable/v1/apps/app_token/tables/tbl123/records', [
                'json' => [
                    'fields' => ['Name' => 'Yomov'],
                ],
                'query' => [
                    'user_id_type' => 'user_id',
                    'client_token' => 'uuid-123',
                    'ignore_consistency_check' => true,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);

        $data = $bitable->createRecord(
            $this->appToken,
            $this->tableId,
            ['Name' => 'Yomov'],
            'user_id',
            'uuid-123',
            true
        );

        expect($data['record_id'])->toBe('rec777');
    });

    test('update record success', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'rec456',
                'fields'    => ['Status' => 'Won'],
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('PATCH', 'bitable/v1/apps/app_token/tables/tbl123/records/rec456', [
                'json' => [
                    'fields' => ['Status' => 'Won'],
                ],
                'query' => [
                    'user_id_type' => 'open_id',
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);

        $data = $bitable->updateRecord($this->appToken, $this->tableId, $this->recordId, ['Status' => 'Won']);

        expect($data['record_id'])->toBe('rec456');
        expect($data['fields']['Status'])->toBe('Won');
    });

    test('list records with query', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'items' => [
                    ['record_id' => 'rec1'],
                    ['record_id' => 'rec2'],
                ],
                'page_token' => 'next',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('GET', 'bitable/v1/apps/app_token/tables/tbl123/records', [
                'query' => [
                    'page_size' => 20,
                    'page_token' => 'token',
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = new Bitable($this->appId, $this->appSecret, $this->accessToken, $mockHttpClient);

        $data = $bitable->listRecords($this->appToken, $this->tableId, [
            'page_size' => 20,
            'page_token' => 'token',
            'view_id' => null,
        ]);

        expect($data['items'])->toHaveCount(2);
        expect($data['page_token'])->toBe('next');
    });

    test('delete record success', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => null,
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('DELETE', 'bitable/v1/apps/app_token/tables/tbl123/records/rec456', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = new Bitable($this->appId, $this->appSecret, $this->accessToken, $mockHttpClient);

        expect($bitable->deleteRecord($this->appToken, $this->tableId, $this->recordId))->toBeTrue();
    });
});

test('throws http exception when api fails', function (): void {
    $response = new Response(200, [], json_encode([
        'code' => 90001,
        'msg'  => 'invalid app token',
    ]));

    $mockHttpClient = Mockery::mock(HttpClient::class);
    $mockHttpClient->allows()->request(new AnyArgs)->andReturn($response);

    $bitable = new Bitable($this->appId, $this->appSecret, $this->accessToken, $mockHttpClient);

    expect(fn () => $bitable->createRecord($this->appToken, $this->tableId, []))->toThrow(HttpException::class, 'invalid app token');
});
    test('update record with query params', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'rec456',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('PATCH', 'bitable/v1/apps/app_token/tables/tbl123/records/rec456', [
                'json' => [
                    'fields' => ['Status' => 'Won'],
                ],
                'query' => [
                    'user_id_type' => 'user_id',
                    'ignore_consistency_check' => true,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ])
            ->once()
            ->andReturn($response);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);

        $data = $bitable->updateRecord(
            $this->appToken,
            $this->tableId,
            $this->recordId,
            ['Status' => 'Won'],
            'user_id',
            true
        );

        expect($data['record_id'])->toBe('rec456');
    });
    test('create record by url (base)', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'recUrl',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('POST', 'bitable/v1/apps/bascn123/tables/tbl456/records', Mockery::on(function ($options) {
                return $options['query']['user_id_type'] === 'open_id';
            }))
            ->once()
            ->andReturn($response);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);

        $url  = 'https://foo.feishu.cn/base/bascn123?table=tbl456';
        $data = $bitable->createRecordByUrl($url, ['Name' => 'URL']);

        expect($data['record_id'])->toBe('recUrl');
    });

    test('create record by wiki url', function (): void {
        $response = new Response(200, [], json_encode([
            'code' => 0,
            'data' => [
                'record_id' => 'recWiki',
            ],
        ]));

        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('request')
            ->with('POST', 'bitable/v1/apps/app_token/tables/tbl999/records', Mockery::on(function ($options) {
                return $options['query']['user_id_type'] === 'open_id';
            }))
            ->once()
            ->andReturn($response);

        $wikiMock = Mockery::mock(Wiki::class)->makePartial();
        $wikiMock->allows()->getNode('wikcnabc', 'bitable')->andReturn([
            'node' => [
                'obj_token' => 'app_token',
                'table_id'  => 'tbl999',
            ],
        ]);

        $bitable = Mockery::mock(Bitable::class, [$this->appId, $this->appSecret, $this->accessToken])->makePartial();
        $bitable->shouldAllowMockingProtectedMethods();
        $bitable->allows()->getHttpClient()->andReturn($mockHttpClient);
        $bitable->allows()->makeWiki()->andReturn($wikiMock);

        $data = $bitable->createRecordByUrl('https://foo.feishu.cn/wiki/wikcnabc', ['Name' => 'Wiki']);

        expect($data['record_id'])->toBe('recWiki');
    });
