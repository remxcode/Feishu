<?php

declare(strict_types=1);

namespace Yuxin\Feishu;

use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Exceptions\HttpException;

use function array_filter;
use function array_merge;
use function json_decode;

class Wiki
{
    protected HttpClient $httpClient;

    public function __construct(
        protected string $appId,
        protected string $appSecret,
        protected ?AccessTokenInterface $accessTokenInstance = null,
        ?HttpClient $httpClient = null
    ) {
        $this->accessTokenInstance = $accessTokenInstance ?? new AccessToken($this->appId, $this->appSecret);
        $this->httpClient          = $httpClient          ?? new HttpClient;
    }

    public function getHttpClient(): HttpClient
    {
        return $this->httpClient;
    }

    public function setGuzzleOptions(array $options): void
    {
        $this->httpClient->setOptions($options);
    }

    /**
     * 获取知识空间节点信息
     *
     * @throws HttpException
     */
    public function getNode(string $token, ?string $objType = null): array
    {
        $query = array_filter([
            'token'    => $token,
            'obj_type' => $objType,
        ], static fn ($value) => $value !== null);

        return $this->send('GET', 'wiki/v2/spaces/get_node', [
            'query' => $query,
        ]);
    }

    /**
     * @throws HttpException
     */
    protected function send(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = array_merge([
            'Authorization' => 'Bearer ' . $this->accessTokenInstance->getToken(),
        ], $options['headers'] ?? []);

        $response = $this->getHttpClient()->request($method, $uri, $options);
        $payload  = json_decode($response->getBody()->getContents(), true);

        if (($payload['code'] ?? null) !== 0) {
            throw new HttpException($payload['msg'] ?? 'Feishu wiki request failed', (int) ($payload['code'] ?? 0));
        }

        return $payload['data'] ?? [];
    }
}
