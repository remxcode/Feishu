<?php

declare(strict_types=1);

namespace Yuxin\Feishu;

use Illuminate\Support\Facades\Log;
use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Exceptions\HttpException;

use function array_filter;
use function array_merge;
use function parse_url;
use function json_decode;
use function preg_match;
use function parse_str;

class Bitable
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
     * 创建多维表格记录
     *
     * @throws HttpException
     */
    public function createRecord(
        string $appToken,
        string $tableId,
        array $fields,
        string $userIdType = 'open_id',
        ?string $clientToken = null,
        ?bool $ignoreConsistencyCheck = null,
    ): array
    {
        $payload = ['fields' => $fields];
        $query   = array_filter([
            'user_id_type'              => $userIdType,
            'client_token'              => $clientToken,
            'ignore_consistency_check'  => $ignoreConsistencyCheck,
        ], static fn ($value) => $value !== null);

        return $this->send('POST', $this->recordsUri($appToken, $tableId), [
            'json' => $payload,
            'query' => $query,
        ]);
    }

    /**
     * 更新多维表格记录
     *
     * @throws HttpException
     */
    public function updateRecord(
        string $appToken,
        string $tableId,
        string $recordId,
        array $fields,
        string $userIdType = 'open_id',
        ?bool $ignoreConsistencyCheck = null,
    ): array
    {
        $payload = ['fields' => $fields];
        $query   = array_filter([
            'user_id_type'             => $userIdType,
            'ignore_consistency_check' => $ignoreConsistencyCheck,
        ], static fn ($value) => $value !== null);

        return $this->send('PATCH', $this->recordUri($appToken, $tableId, $recordId), [
            'json' => $payload,
            'query' => $query,
        ]);
    }

    /**
     * 通过 URL 创建记录
     *
     * @throws HttpException
     */
    public function createRecordByUrl(string $url, array $fields, string $userIdType = 'open_id', ?string $clientToken = null, ?bool $ignoreConsistencyCheck = null): array
    {
        [$appToken, $tableId] = $this->parseBitableUrl($url);

        return $this->createRecord($appToken, $tableId, $fields, $userIdType, $clientToken, $ignoreConsistencyCheck);
    }

    /**
     * 通过 URL 更新记录
     *
     * @throws HttpException
     */
    public function updateRecordByUrl(string $url, string $recordId, array $fields, string $userIdType = 'open_id', ?bool $ignoreConsistencyCheck = null): array
    {
        [$appToken, $tableId] = $this->parseBitableUrl($url);

        return $this->updateRecord($appToken, $tableId, $recordId, $fields, $userIdType, $ignoreConsistencyCheck);
    }

    /**
     * 获取单条记录
     *
     * @throws HttpException
     */
    public function getRecord(string $appToken, string $tableId, string $recordId): array
    {
        return $this->send('GET', $this->recordUri($appToken, $tableId, $recordId));
    }

    /**
     * 列出记录（支持分页/筛选）
     *
     * @throws HttpException
     */
    public function listRecords(string $appToken, string $tableId, array $query = []): array
    {
        $query = array_filter($query, static fn ($value) => $value !== null);

        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->send('GET', $this->recordsUri($appToken, $tableId), $options);
    }

    /**
     * 删除单条记录
     *
     * @throws HttpException
     */
    public function deleteRecord(string $appToken, string $tableId, string $recordId): bool
    {
        $this->send('DELETE', $this->recordUri($appToken, $tableId, $recordId));

        return true;
    }

    protected function recordsUri(string $appToken, string $tableId): string
    {
        return sprintf('bitable/v1/apps/%s/tables/%s/records', $appToken, $tableId);
    }

    protected function recordUri(string $appToken, string $tableId, string $recordId): string
    {
        return $this->recordsUri($appToken, $tableId) . '/' . $recordId;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function parseBitableUrl(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['path'])) {
            throw new HttpException('Invalid bitable url', 400);
        }
        $path = trim($parts['path'], '/');
        $query    = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $tableId = $query['table'] ?? null;
        if (! $tableId) {
            throw new HttpException('Bitable URL missing table parameter', 400);
        }
        // base 链接：.../base/{appToken}?table=tblxxx
        if (preg_match('#base/([A-Za-z0-9]+)#', $path, $matches)) {
            $appToken = $matches[1];

            return [$appToken, $tableId];
        }

        // wiki 链接：.../wiki/{token}?table=tblxxx
        if (preg_match('#wiki/([^/?]+)#', $path, $matches)) {
            $nodeToken = $matches[1];
            $node      = $this->makeWiki()->getNode($nodeToken, 'wiki');
            $appToken = $node['node']['obj_token'] ?? null;
            if (! $appToken) {
                throw new HttpException('Wiki node is not a specific bitable table', 400);
            }

            return [$appToken, $tableId];
        }

        throw new HttpException('Unsupported bitable url format', 400);
    }

    protected function makeWiki(): Wiki
    {
        return new Wiki($this->appId, $this->appSecret, $this->accessTokenInstance, $this->httpClient);
    }

    /**
     * @throws HttpException
     */
    protected function send(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = array_merge([
            'Authorization' => 'Bearer ' . $this->accessTokenInstance->getToken(),
        ], $options['headers'] ?? []);
        Log::info('feishu request', compact('method', 'uri', 'options'));
        $response = $this->getHttpClient()->request($method, $uri, $options);
        $payload  = json_decode($response->getBody()->getContents(), true);

        if (($payload['code'] ?? null) !== 0) {
            Log::error($payload['msg'], [
                'method' => $method,
                'uri' => $uri,
                'options' => $options,
                'error' => $payload['error'] ?? [],
            ]);
            throw new HttpException(data_get($payload, 'error.message') ?? 'Feishu bitable request failed', (int) ($payload['code'] ?? 0));
        }
        

        return $payload['data'] ?? [];
    }
}
