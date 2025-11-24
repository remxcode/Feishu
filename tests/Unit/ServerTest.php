<?php

declare(strict_types=1);

use Yuxin\Feishu\Server;

it('verifies signature and responds challenge via serve', function (): void {
    $server = new Server();

    $timestamp = (string) time();
    $nonce = 'nonce-123';
    $rawBody = json_encode(['type' => 'url_verification', 'challenge' => 'challenge-token']);
    $signature = hash('sha256', $timestamp . $nonce . $rawBody);

    $result = $server->serve([
        'X-Lark-Request-Timestamp' => $timestamp,
        'X-Lark-Request-Nonce' => $nonce,
        'X-Lark-Signature' => $signature,
    ], $rawBody);

    expect($result)->toMatchArray(['challenge' => 'challenge-token']);
});

it('runs middleware pipeline on decrypted payload', function (): void {
    $encryptKey = 'secret-key';
    $server = new Server($encryptKey);

    $plaintext = json_encode(['event' => ['foo' => 'bar']]);
    $rawBody = json_encode(['encrypt' => encryptFeishuPayload($plaintext, $encryptKey)]);

    $timestamp = (string) time();
    $nonce = 'nonce-456';
    $signature = hash('sha256', $timestamp . $nonce . $encryptKey . $rawBody);

    $server->with(function (array $message, Closure $next) {
        $message['event']['foo'] = 'baz';

        return $next($message);
    })->with(function (array $message, Closure $next) {
        $message['handled'] = true;

        return $next($message);
    });

    $response = $server->serve([
        'X-Lark-Request-Timestamp' => $timestamp,
        'X-Lark-Request-Nonce' => $nonce,
        'X-Lark-Signature' => $signature,
    ], $rawBody);

    expect($response['status'])->toBe('success');
    expect($response['data']['event']['foo'] ?? null)->toBe('baz');
    expect($response['data']['handled'] ?? false)->toBeTrue();
});

it('rejects expired timestamp', function (): void {
    $server = new Server();

    $timestamp = (string) (time() - 1000);
    $nonce = 'nonce-expired';
    $rawBody = '{}';
    $signature = hash('sha256', $timestamp . $nonce . $rawBody);

    expect(fn () => $server->serve([
        'X-Lark-Request-Timestamp' => $timestamp,
        'X-Lark-Request-Nonce' => $nonce,
        'X-Lark-Signature' => $signature,
    ], $rawBody, 10))->toThrow(RuntimeException::class);
});

it('handles unsigned url_verification fallback', function (): void {
    $server = new Server();

    $rawBody = json_encode([
        'type' => 'url_verification',
        'challenge' => '2f1f9485-43db-476e-a50b-01f29c28f777',
        'token' => 'lpbmrTMpoJmeKUKFPUCRVgtlsXmJLeWX',
    ]);

    $result = $server->serve([], $rawBody);

    expect($result)->toMatchArray(['challenge' => '2f1f9485-43db-476e-a50b-01f29c28f777']);
});

function encryptFeishuPayload(string $plain, string $encryptKey): string
{
    $key = hash('sha256', $encryptKey, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($cipher === false) {
        throw new RuntimeException('Failed to encrypt test payload.');
    }

    return base64_encode($iv . $cipher);
}
