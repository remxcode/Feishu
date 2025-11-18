---
outline: deep
---

# API 示例

## 基本使用示例

### 发送文本消息

```php
<?php

require_once 'vendor/autoload.php';

use Yuxin\Feishu\Message;
use Yuxin\Feishu\Enums\MessageTypeEnum;

$message = new Message('your_app_id', 'your_app_secret');

// 发送给用户
$message->send(
    'user_open_id',
    MessageTypeEnum::Text->value,
    'Hello, World!'
);

// 发送给群组
$message->send(
    'chat_id',
    MessageTypeEnum::Text->value,
    '这是一条群组消息'
);
```

### 搜索群组

```php
<?php

use Yuxin\Feishu\Group;

$group = new Group('your_app_id', 'your_app_secret');

// 搜索群组
$chatId = $group->search('测试群组');
echo "群组ID: " . $chatId;
```

### 获取用户ID

```php
<?php

use Yuxin\Feishu\User;
use Yuxin\Feishu\Enums\UserIDTypeEnum;

$user = new User('your_app_id', 'your_app_secret');

// 根据邮箱获取用户ID
$userId = $user->getId('user@example.com', UserIDTypeEnum::OpenID->value);

// 根据手机号获取用户ID
$userId = $user->getId('13800138000', UserIDTypeEnum::UnionID->value);
```

### 多维表格记录

```php
<?php

use Yuxin\Feishu\Bitable;

$bitable = new Bitable('your_app_id', 'your_app_secret');

// 创建记录
$record = $bitable->createRecord(
    'app_token',
    'table_id',
    [
        '客户名称' => '测试公司',
        '手机号' => '13800138000',
    ],
    userIdType: 'user_id',
    clientToken: 'uuid-1234',
    ignoreConsistencyCheck: false
);

$bitable->createRecordByUrl(
    'https://foo.feishu.cn/base/bascn123?table=tbl456',
    ['客户名称' => 'URL 解析']
);

// 更新状态
$bitable->updateRecord(
    'app_token',
    'table_id',
    $record['record_id'],
    ['跟进状态' => '已联系'],
    userIdType: 'user_id',
    ignoreConsistencyCheck: true
);

// 查询
$list = $bitable->listRecords('app_token', 'table_id', [
    'page_size' => 20,
]);

### Wiki 节点

```php
<?php

use Yuxin\Feishu\Wiki;

$wiki = new Wiki('your_app_id', 'your_app_secret');

$node = $wiki->getNode('wikcn123456', 'bitable');

echo $node['node']['obj_token'];
```
```

## 消息类型示例

### 富文本消息

```php
<?php

use Yuxin\Feishu\Message;
use Yuxin\Feishu\Enums\MessageTypeEnum;

$message = new Message('your_app_id', 'your_app_secret');

$content = [
    'zh_cn' => [
        'title' => '系统通知',
        'content' => [
            [
                'tag' => 'text',
                'text' => '您有一条新的系统通知'
            ],
            [
                'tag' => 'a',
                'text' => '点击查看详情',
                'href' => 'https://example.com/notification'
            ]
        ]
    ]
];

$message->send(
    'user_open_id',
    MessageTypeEnum::Post->value,
    $content
);
```

### 卡片消息

```php
<?php

use Yuxin\Feishu\Message;
use Yuxin\Feishu\Enums\MessageTypeEnum;

$message = new Message('your_app_id', 'your_app_secret');

$content = [
    'config' => [
        'wide_screen_mode' => true
    ],
    'header' => [
        'title' => [
            'tag' => 'plain_text',
            'content' => '任务提醒'
        ],
        'template' => 'blue'
    ],
    'elements' => [
        [
            'tag' => 'div',
            'text' => [
                'tag' => 'lark_md',
                'content' => '**任务名称**: 完成项目文档\n**截止时间**: 2024-01-15 18:00'
            ]
        ]
    ]
];

$message->send(
    'user_open_id',
    MessageTypeEnum::Interactive->value,
    $content
);
```

## 错误处理示例

```php
<?php

use Yuxin\Feishu\Message;
use Yuxin\Feishu\Enums\MessageTypeEnum;
use Yuxin\Feishu\Exceptions\HttpException;
use Yuxin\Feishu\Exceptions\InvalidArgumentException;

$message = new Message('your_app_id', 'your_app_secret');

try {
    $message->send(
        'user_id',
        MessageTypeEnum::Text->value,
        'Hello, World!'
    );
    echo "消息发送成功！\n";
} catch (HttpException $e) {
    echo "API错误: " . $e->getMessage() . "\n";
} catch (InvalidArgumentException $e) {
    echo "参数错误: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "未知错误: " . $e->getMessage() . "\n";
}
```
