<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Yuxin\Feishu\Contracts\AccessTokenInterface;
use Yuxin\Feishu\Enums\MessageTypeEnum;
use Yuxin\Feishu\Events\MessageSent;
use Yuxin\Feishu\Exceptions\HttpException;
use Yuxin\Feishu\Exceptions\InvalidArgumentException;
use Yuxin\Feishu\HttpClient;
use Yuxin\Feishu\Message;

use function json_encode;

beforeEach(function (): void {
    $this->accessToken = Mockery::mock(AccessTokenInterface::class);
    $this->accessToken->allows()->getToken()->andReturn('mock_access_token');
});

afterEach(function (): void {
    Mockery::close();
});

/**
 * 构造一个 post 请求交给回调校验的 Message，用于断言请求内容
 */
function messageWithPost(object $test, Response $response, Closure $assertRequest): Message
{
    $guzzle = Mockery::mock(Client::class);
    $guzzle->shouldReceive('post')
        ->once()
        ->withArgs($assertRequest)
        ->andReturn($response);

    $httpClient = Mockery::mock(HttpClient::class);
    $httpClient->shouldReceive('getClient')->andReturn($guzzle);

    $message = Mockery::mock(Message::class, ['app_id', 'app_secret', $test->accessToken])->makePartial();
    $message->allows()->getHttpClient()->andReturn($httpClient);

    return $message;
}

describe('reply', function (): void {
    test('posts to the reply endpoint and returns the new message id', function (): void {
        Event::fake([MessageSent::class]);
        $card = ['type' => 'template', 'data' => ['template_id' => 'tpl']];

        $message = messageWithPost(
            $this,
            new Response(200, [], json_encode(['code' => 0, 'msg' => 'success', 'data' => ['message_id' => 'om_new']])),
            function (string $uri, array $options) use ($card): bool {
                $body = json_decode($options['body'], true);

                return $uri === 'im/v1/messages/om_origin/reply'
                    && $options['headers']['Authorization'] === 'Bearer mock_access_token'
                    && $body['msg_type'] === MessageTypeEnum::Interactive->value
                    && $body['content'] === json_encode($card)
                    && $body['reply_in_thread'] === false
                    && ! array_key_exists('uuid', $body);
            },
        );

        expect($message->reply('om_origin', MessageTypeEnum::Interactive->value, $card))->toBe('om_new');
        Event::assertDispatched(MessageSent::class);
    });

    test('passes uuid and reply_in_thread through', function (): void {
        $message = messageWithPost(
            $this,
            new Response(200, [], json_encode(['code' => 0, 'msg' => 'success', 'data' => ['message_id' => 'om_new']])),
            function (string $uri, array $options): bool {
                $body = json_decode($options['body'], true);

                return $body['uuid'] === 'dedupe-1' && $body['reply_in_thread'] === true;
            },
        );

        expect($message->reply('om_origin', MessageTypeEnum::Interactive->value, '{"a":1}', 'dedupe-1', true))->toBe('om_new');
    });

    test('wraps text content under the text key', function (): void {
        $message = messageWithPost(
            $this,
            new Response(200, [], json_encode(['code' => 0, 'msg' => 'success', 'data' => ['message_id' => 'om_new']])),
            function (string $uri, array $options): bool {
                $body = json_decode($options['body'], true);

                return $body['msg_type'] === MessageTypeEnum::Text->value
                    && $body['content'] === json_encode(['text' => 'hello']);
            },
        );

        expect($message->reply('om_origin', MessageTypeEnum::Text->value, 'hello'))->toBe('om_new');
    });

    test('throws on invalid message type without calling the api', function (): void {
        $message = new Message('app_id', 'app_secret', $this->accessToken);

        $message->reply('om_origin', 'not_a_type', 'hello');
    })->throws(InvalidArgumentException::class, 'Invalid message type: not_a_type');

    test('throws HttpException when the api returns an error code', function (): void {
        $message = messageWithPost(
            $this,
            new Response(200, [], json_encode(['code' => 230001, 'msg' => 'invalid request'])),
            fn (): bool => true,
        );

        $message->reply('om_origin', MessageTypeEnum::Interactive->value, '{}');
    })->throws(HttpException::class, 'invalid request');
});
