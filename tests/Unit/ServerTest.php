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

it('accepts datetime-formatted timestamps for tolerance and signature', function (): void {
    $encryptKey = 'qDggBEE';
    $server = new Server($encryptKey);

    $timestampHeader = date('Y-m-d H:i:s') . '.153131788 +0800 CST m=+0.000';
    $nonce = '436337865';
    $plaintext = json_encode(['event' => ['foo' => 'bar']]);
    $rawBody = json_encode(['encrypt' => encryptFeishuPayload($plaintext, $encryptKey)]);
    $signature = hash('sha256', $timestampHeader . $nonce . $encryptKey . $rawBody);

    $result = $server->serve([
        'X-Lark-Request-Timestamp' => $timestampHeader,
        'X-Lark-Request-Nonce' => $nonce,
        'X-Lark-Signature' => $signature,
    ], $rawBody, toleranceSeconds: 999999);

    expect($result['status'] ?? null)->toBe('success');
    expect(data_get($result, 'data.event.foo'))->toBe('bar');
});

it('decrypts and parses encrypted card callback payload', function (): void {
    $encryptKey = 'qDggBEE';
    $server = new Server($encryptKey);

    $rawBody = '{"encrypt":"VIKaz3SRDDtx9duwhjWX10iWd1q6C/2HGvsrKy1wnm66AJwJjzEC4IEjCFCQTMYobXtROJusvcGtoGoFg5yiekzm4+POJpmHJr94Llqq8WiGKRapEVVGcueYmV93CGh6+sajEz9R63JK/Z6H19pPiyu4ro8uMJGaY01kuQv22VFEX2uDuE22ngA6BxeyqD1twFsjB92HH9tU/qZQFqlau9c+Vl3FdG15ZFA2tYyMKdILKLTOKMmIzGkZAC7xYRMirPzgihs+jw/+JT/pPmB76wCMSPXZiCD5LrUuzVvMLkozPPmqcxOjbm9Yw5rSsBm+INMh+LJxx8clRHscYvPrY0XaWfwZ3p2rafIa6e66m6D/rB9pXP0RdLn+kGCzRCu2y392kN7imNnVx+0grjs57Eqh35FAWlLOZ4U3KLTp7bjTIVnf+NqCQ/qLbf1VirrnCpvow/WkN5HTMylB2ysjWKvpZnqnx7C/musmQ5t0zR7HREHJbSiRh+bJ6Di9MHGe4hct0PspZJ76h4GureH0T5M4e16aQ4++jnYMBV/RWgGICk0slMCeHVUEckNv8ZL4qvI233ORrdAsopz0V20OZE0T14WFPeo/iG6/5aUq5j0bXopqCPeVzRJJqq40je09bYiTrF5K/GMc0RNTzwdToKrH6Jgnk6GCzIeOBo00OIZfUlXjlXLMJcsMWuhzcROVpxPga89ybBXhqpxvpfZafwf4NGJgZpupWm/VnhEABa+DeHBVdXEcIByOU0v5L3IZq8puhNFnCLpuTPCM41E6+3vOu08AFqS8zdjyAb94eQjpdV20oQ6fY00MrE16GN57fJ2i1Hhh9iv10QYdx10XMGOhif0XrbBRCspwFJfCICh7rlQ8nZomFa07nuaxBtI0fyaBJtcA/8I/FcSJgyi9ID+B992u88wVFobhHoVqKuoLaroIIiyIq3uhBS6kxejj"}';
    $timestampHeader = '2025-11-24 15:58:49.153131788 +0800 CST m=+366257.905465286';
    $nonce = '436337865';
    $signature = hash('sha256', $timestampHeader . $nonce . $encryptKey . $rawBody);

    $result = $server->serve([
        'X-Lark-Request-Timestamp' => $timestampHeader,
        'X-Lark-Request-Nonce' => $nonce,
        'X-Lark-Signature' => $signature,
    ], $rawBody, toleranceSeconds: 1_000_000_000);

    expect($result['status'] ?? null)->toBe('success');
    expect(data_get($result, 'data.header.event_type'))->toBe('card.action.trigger');
    expect(data_get($result, 'data.event.operator.union_id'))->toBe('on_5dc9c48fea8392163fb84e9f35a21b5a');
    expect(data_get($result, 'data.event.action.tag'))->toBe('select_person');
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
