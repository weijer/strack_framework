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

use think\Request;

/**
 * 行为扩展：代理检测
 */
class AgentCheckBehavior
{
    public function run(&$params)
    {
        // 代理访问检测
        $limitProxyVisit = C('LIMIT_PROXY_VISIT', null, true);

        $request = Request::instance();
        if ($limitProxyVisit && ($request->header('X_FORWARDED_FOR') || $request->header('VIA') || $request->header('PROXY_CONNECTION') || $request->header('USER_AGENT_VIA'))) {
            // 禁止代理访问
            StrackE('Access Denied');
        }
    }
}
