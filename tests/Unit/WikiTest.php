<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Mockery\Matcher\AnyArgs;
use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Exceptions\HttpException;
use Yuxin\Feishu\HttpClient;
use Yuxin\Feishu\Wiki;

beforeEach(function (): void {
    $this->appId     = 'app_id';
    $this->appSecret = 'app_secret';
    $this->nodeToken = 'wikcn123';
    $this->token     = 'mock_token';

    $this->accessToken = Mockery::mock(AccessTokenInterface::class);
    $this->accessToken->allows()->getToken()->andReturn($this->token);
});

afterEach(function (): void {
    Mockery::close();
});

test('get http client instance', function (): void {
    $wiki = new Wiki($this->appId, $this->appSecret);

    expect($wiki->getHttpClient())->toBeInstanceOf(HttpClient::class);
});

test('set guzzle options', function (): void {
    $wiki = new Wiki($this->appId, $this->appSecret);

    expect($wiki->getHttpClient()->getClient()->getConfig('timeout'))->toBeNull();

    $wiki->setGuzzleOptions(['timeout' => 15]);

    expect($wiki->getHttpClient()->getClient()->getConfig('timeout'))->toBe(15);
});

test('get node success', function (): void {
    $response = new Response(200, [], json_encode([
        'code' => 0,
        'data' => [
            'node' => [
                'node_token' => $this->nodeToken,
                'obj_token'  => 'doccnxxxx',
                'obj_type'   => 'bitable',
            ],
        ],
    ]));

    $mockHttpClient = Mockery::mock(HttpClient::class);
    $mockHttpClient->shouldReceive('request')
        ->with('GET', 'wiki/v2/space_node/get_node', [
            'query' => [
                'token'    => $this->nodeToken,
                'obj_type' => 'bitable',
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
            ],
        ])
        ->once()
        ->andReturn($response);

    $wiki  = new Wiki($this->appId, $this->appSecret, $this->accessToken, $mockHttpClient);
    $data  = $wiki->getNode($this->nodeToken, 'bitable');
    $node  = $data['node'];

    expect($node['node_token'])->toBe($this->nodeToken);
    expect($node['obj_type'])->toBe('bitable');
});

test('throw http exception when api fails', function (): void {
    $response = new Response(200, [], json_encode([
        'code' => 100500,
        'msg'  => 'space not exists',
    ]));

    $mockHttpClient = Mockery::mock(HttpClient::class);
    $mockHttpClient->allows()->request(new AnyArgs)->andReturn($response);

    $wiki = new Wiki($this->appId, $this->appSecret, $this->accessToken, $mockHttpClient);

    expect(fn () => $wiki->getNode($this->nodeToken))->toThrow(HttpException::class, 'space not exists');
});
