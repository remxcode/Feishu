<?php

declare(strict_types=1);

namespace Yuxin\Feishu;

use GuzzleHttp\Exception\GuzzleException;
use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Contracts\UserInterface;
use Yuxin\Feishu\Enums\UserIDTypeEnum;
use Yuxin\Feishu\Events\UserSearched;
use Yuxin\Feishu\Exceptions\HttpException;
use Yuxin\Feishu\Exceptions\InvalidArgumentException;

use function array_column;
use function filter_var;
use function in_array;
use function json_decode;
use function json_encode;

class User implements UserInterface
{
    protected HttpClient $httpClient;

    public function __construct(
        protected string $appId,
        protected string $appSecret,
        protected ?AccessTokenInterface $accessTokenInstance = null,
        ?HttpClient $httpClient = null
    ) {
        $this->accessTokenInstance = $accessTokenInstance ?: new AccessToken($this->appId, $this->appSecret);
        $this->httpClient          = $httpClient ?? new HttpClient;
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
     * @throws HttpException
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getId(string $username, string $type = 'union_id', bool $includeResigned = true): string
    {
        if (! in_array($type, array_column(UserIDTypeEnum::cases(), 'value'))) {
            throw new InvalidArgumentException('Invalid user id type');
        }

        $response = json_decode($this->getHttpClient()->getClient()->post('contact/v3/users/batch_get_id', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessTokenInstance->getToken(),
            ],
            'query' => [
                'user_id_type' => $type,
            ],
            'body' => json_encode([
                filter_var($username, FILTER_VALIDATE_EMAIL)
                    ? 'emails'
                    : 'mobiles'    => (array) $username,
                'include_resigned' => $includeResigned,
            ]),
        ])->getBody()->getContents(), true);

        if (empty($response['data']['user_list'][0]['user_id'])) {
            throw new HttpException('User not found');
        }

        $userId = $response['data']['user_list'][0]['user_id'];

        event(new UserSearched($username, $userId));

        return $userId;
    }

    /**
     * Batch resolve user IDs by mobiles (or emails if provided).
     *
     * @param  array<int,string>  $usernames
     * @return array<string,string> key: username, value: user_id
     *
     * @throws HttpException
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getIds(array $usernames, string $type = 'union_id', bool $includeResigned = true): array
    {
        if (! in_array($type, array_column(UserIDTypeEnum::cases(), 'value'))) {
            throw new InvalidArgumentException('Invalid user id type');
        }

        if (empty($usernames)) {
            return [];
        }

        $mobiles = [];
        $emails = [];
        foreach ($usernames as $username) {
            if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $username;
            } else {
                $mobiles[] = $username;
            }
        }

        $payload = [
            'include_resigned' => $includeResigned,
        ];

        if ($mobiles) {
            $payload['mobiles'] = array_values($mobiles);
        }

        if ($emails) {
            $payload['emails'] = array_values($emails);
        }

        $response = json_decode($this->getHttpClient()->getClient()->post('contact/v3/users/batch_get_id', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessTokenInstance->getToken(),
            ],
            'query' => [
                'user_id_type' => $type,
            ],
            'body' => json_encode($payload),
        ])->getBody()->getContents(), true);

        $list = data_get($response, 'data.user_list', []);

        $results = [];
        foreach ($list as $entry) {
            $username = $entry['mobile'] ?? ($entry['email'] ?? null);
            $userId = $entry['user_id'] ?? null;

            if ($username && $userId) {
                $results[$username] = $userId;
            }
        }

        return $results;
    }
}
