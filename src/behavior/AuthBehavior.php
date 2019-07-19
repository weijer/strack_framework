<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2009 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace behavior;

use Common\Middleware\AuthMiddleware;

/**
 * 行为扩展：处理token权限验证
 */
class AuthBehavior
{

    /**
     * 处理记录event log
     * @param $params
     * @throws \Exception
     */
    public function run(&$params)
    {
        $authMiddleware = new AuthMiddleware();
        $authMiddleware->checkRequestToken($params);
    }
}
