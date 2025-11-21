# Feishu SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zhaiyuxin/feishu?style=for-the-badge)](https://packagist.org/packages/zhaiyuxin/feishu)
[![Total Downloads on Packagist](https://img.shields.io/packagist/dt/zhaiyuxin/feishu?style=for-the-badge)](https://packagist.org/packages/zhaiyuxin/feishu)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=for-the-badge)](LICENSE)
[![Build Status](https://img.shields.io/github/actions/workflow/status/zhaiyuxin103/feishu/tests.yml?style=for-the-badge)](https://github.com/zhaiyuxin103/feishu/actions)
[![Code Coverage](https://img.shields.io/codecov/c/github/zhaiyuxin103/feishu?style=for-the-badge)](https://codecov.io/gh/zhaiyuxin103/feishu)

A clean and powerful PHP SDK for Feishu (Lark) API with Laravel integration.

## Installation

```bash
composer require zhaiyuxin/feishu
```

## Quick Start

```php
use Yuxin\Feishu\Facades\Feishu;

// Send a message
Feishu::message()->send('user_id', 'text', 'Hello, World!');

// Search for a group
$chatId = Feishu::group()->search('group_name');

// Get user info
$userInfo = Feishu::user()->getInfo('user_id');

// Get access token
$token = Feishu::accessToken()->getToken();

// Manage Bitable records
$record = Feishu::bitable()->createRecord('app_token', 'table_id', [
    '客户名称' => '测试公司',
]);

// Or use URL directly
Feishu::bitable()->createRecordByUrl(
    'https://foo.feishu.cn/base/bascn123?table=tbl456',
    ['客户名称' => 'URL']
);

// Fetch Wiki node info
$node = Feishu::wiki()->getNode('wikcn123', 'bitable');
```

## Callbacks (Card/Event)

Feishu callbacks are verified & decrypted via the built-in `Server` helper.

```php
use Yuxin\Feishu\Facades\Feishu;

$server = Feishu::server(); // uses config('feishu.encrypt_key') if set

// Register middleware-style handlers
$server->with(function (array $message, Closure $next) {
    // your logic, e.g. route by $message['action']['value']
    return $next($message);
});

// Serve request (Laravel example)
// You can omit args to read current request (Laravel):
// $result = $server->serve();
$result = $server->serve(request()->headers->all(), request()->getContent());

// Challenge handshake
if (isset($result['challenge'])) {
    return response()->json(['challenge' => $result['challenge']]);
}

// Successful processing
return response()->json($result); // ['status' => 'success', 'data' => $message]
```

Notes:
- Headers `X-Lark-Request-Timestamp/Nonce/Signature` are verified; optional AES-256-CBC decrypt when body carries `encrypt`.
- Signature algorithm follows Feishu docs: `sha256(timestamp + nonce + encryptKey + rawBody)` (hex string). `encryptKey` is required if your app configures callback encryption; keep it blank for plain callbacks.
- Encrypted callbacks expect base64-encoded payload where the first 16 bytes are IV, followed by AES-256-CBC ciphertext; IV is extracted from the decoded buffer.
- Out-of-window (default 5 minutes) or bad signatures throw exceptions; you can wrap/handle as needed.

## Documentation

For complete documentation, visit our [documentation site](https://feishu-nine.vercel.app/).

## Contributing

Please see [CONTRIBUTING.md](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## License

The Feishu SDK is open-sourced software licensed under the [MIT license](LICENSE).
