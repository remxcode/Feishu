<?php

declare(strict_types=1);

namespace Yuxin\Feishu;

use RuntimeException;

class Server
{
    protected array $middleware = [];

    public function __construct(protected ?string $encryptKey = null) {}

    /**
     * Register middleware handler.
     */
    public function with(callable $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Main entry: parse callback and run middleware pipeline.
     *
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     */
    public function serve(?array $headers = null, ?string $rawBody = null, int $toleranceSeconds = 300): array
    {
        if ($headers === null || $rawBody === null) {
            if (! function_exists('request')) {
                throw new RuntimeException('Missing headers/body for callback parsing.');
            }

            $request = request();
            $headers = $this->normalizeHeaders($request->headers->all());
            $rawBody = $rawBody ?? $request->getContent();
        } else {
            $headers = $this->normalizeHeaders($headers);
        }

        if ($rawBody === null) {
            throw new RuntimeException('Empty callback body.');
        }

        $parsed = $this->parse($headers, $rawBody, $toleranceSeconds);

        if (($parsed['is_challenge'] ?? false) && isset($parsed['challenge'])) {
            return ['challenge' => $parsed['challenge']];
        }

        $pipeline = $this->buildPipeline();

        return $pipeline($parsed['payload'] ?? []);
    }

    /**
     * Parse and optionally decrypt callback body; validates signature and timestamp window.
     *
     * @param  array<string,string>  $headers
     * @return array{payload: array<string,mixed>, is_challenge: bool, challenge?: string}
     */
    public function parse(array $headers, string $rawBody, int $toleranceSeconds = 300): array
    {
        $timestamp = (string) ($headers['X-Lark-Request-Timestamp'] ?? $headers['x-lark-request-timestamp'] ?? '');
        $nonce = (string) ($headers['X-Lark-Request-Nonce'] ?? $headers['x-lark-request-nonce'] ?? '');
        $signature = (string) ($headers['X-Lark-Signature'] ?? $headers['x-lark-signature'] ?? '');

        $payload = $this->decodePayload($rawBody);
        $isChallenge = isset($payload['challenge']);

        // Fallback: some legacy url_verification payloads come without signature headers.
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            if ($isChallenge && ($payload['type'] ?? null) === 'url_verification') {
                return [
                    'payload' => $payload,
                    'is_challenge' => true,
                    'challenge' => (string) $payload['challenge'],
                ];
            }

            throw new RuntimeException('Missing Feishu callback headers.');
        }

        if (! $this->withinTolerance($timestamp, $toleranceSeconds)) {
            throw new RuntimeException('Feishu callback timestamp expired.');
        }

        if (! $this->verifySignature($timestamp, $nonce, $rawBody, $signature)) {
            throw new RuntimeException('Invalid Feishu callback signature.');
        }

        return [
            'payload' => $payload,
            'is_challenge' => $isChallenge,
            'challenge' => $isChallenge ? (string) $payload['challenge'] : null,
        ];
    }

    public function verifySignature(string $timestamp, string $nonce, string $rawBody, string $signature): bool
    {
        $baseString = $timestamp . $nonce . (string) $this->encryptKey . $rawBody;
        $computed = hash('sha256', $baseString);

        return hash_equals($computed, $signature);
    }

    public function decrypt(string $cipherText): string
    {
        $key = hash('sha256', (string) $this->encryptKey, true);
        $decoded = base64_decode($cipherText, true);

        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 cipher text.');
        }

        if (strlen($decoded) <= 16) {
            throw new RuntimeException('Invalid encrypted payload: missing IV or cipher text.');
        }

        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt callback payload.');
        }

        return $plain;
    }

    /**
     * @return array<string,mixed>
     */
    public function decodePayload(string $rawBody): array
    {
        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            throw new RuntimeException('Invalid callback json payload.');
        }

        if (array_key_exists('encrypt', $body)) {
            if (! $this->encryptKey) {
                throw new RuntimeException('Encrypt key required for encrypted callback.');
            }

            $decrypted = $this->decrypt((string) $body['encrypt']);
            $body = json_decode($decrypted, true);
            if (! is_array($body)) {
                throw new RuntimeException('Invalid decrypted callback payload.');
            }
        }

        return $body;
    }

    protected function withinTolerance(string $timestamp, int $toleranceSeconds): bool
    {
        if ($timestamp === '') {
            return false;
        }

        $ts = $this->normalizeTimestamp($timestamp);
        $now = time();

        return abs($now - $ts) <= $toleranceSeconds;
    }

    /**
     * Normalize timestamp header which might be an integer seconds or a full datetime string.
     */
    protected function normalizeTimestamp(string $timestamp): int
    {
        // If numeric (or float-like), use as-is
        if (is_numeric($timestamp)) {
            return (int) $timestamp;
        }

        // Strip trailing perf markers like " m=+0.000" if present.
        $timestamp = preg_replace('/\\s+m=.*$/', '', $timestamp);

        // Try to parse datetime strings like "2025-11-24 15:58:49.153131788 +0800 CST"
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?(.*)$/', $timestamp, $matches)) {
            $trimmed = $matches[1] . ($matches[2] ?? '');
            $parsed = strtotime(trim($trimmed));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        $parsed = strtotime($timestamp);
        if ($parsed !== false) {
            return $parsed;
        }

        return 0;
    }

    /**
     * Build middleware pipeline; default terminator returns success + data.
     */
    protected function buildPipeline(): callable
    {
        $next = static fn (array $message): array => [
            'status' => 'success',
            'data' => $message,
        ];

        return array_reduce(
            array_reverse($this->middleware),
            static function ($next, $middleware) {
                return static function (array $message) use ($middleware, $next) {
                    return $middleware($message, $next);
                };
            },
            $next
        );
    }

    /**
     * Normalize incoming headers to a simple string array.
     *
     * @param  array<string,mixed>  $headers
     * @return array<string,string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = (string) end($value);
            } else {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
