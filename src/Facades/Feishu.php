<?php

declare(strict_types=1);

namespace Yuxin\Feishu\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Yuxin\Feishu\AccessToken accessToken()
 * @method static \Yuxin\Feishu\Group group()
 * @method static \Yuxin\Feishu\Message message()
 * @method static \Yuxin\Feishu\Bitable bitable()
 * @method static \Yuxin\Feishu\User user()
 * @method static \Yuxin\Feishu\Wiki wiki()
 * @method static \Yuxin\Feishu\Server server(?string $encryptKey = null)
 */
class Feishu extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'feishu';
    }
}
