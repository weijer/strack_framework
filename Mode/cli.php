<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

/**
 * ThinkPHP 普通模式定义
 */
return array(
    // 配置文件
    'config' => array(
        THINK_PATH . 'Conf/convention.php', // 系统惯例配置
        CONF_PATH . 'config' . CONF_EXT, // 应用公共配置
    ),

    // 公共函数
    'function' => [
        THINK_PATH . 'helper.php',
        APP_PATH . 'helper.php',
    ],

    // 别名定义
    'alias'  => array(
        'Think\Log'               => CORE_PATH . 'Log' . EXT,
        'Think\Log\Driver\File'   => CORE_PATH . 'Log/Driver/File' . EXT,
        'Think\Exception'         => CORE_PATH . 'Exception' . EXT,
        'Think\Model'             => CORE_PATH . 'Model' . EXT,
        'Think\Db'                => CORE_PATH . 'Db' . EXT,
        'Think\Cache'             => CORE_PATH . 'Cache' . EXT,
        'Think\Cache\Driver\File' => CORE_PATH . 'Cache/Driver/File' . EXT,
        'Think\Storage'           => CORE_PATH . 'Storage' . EXT,
    ),

    // 函数和类文件
    'core'   => array(
        CORE_PATH . 'Hook' . EXT,
        CORE_PATH . 'App' . EXT,
        CORE_PATH . 'Dispatcher' . EXT,
        CORE_PATH . 'Log'.EXT,
        CORE_PATH . 'Route' . EXT,
        CORE_PATH . 'Controller' . EXT,
        CORE_PATH . 'Request' . EXT,
        CORE_PATH . 'Response' . EXT
    ),

    // 行为扩展定义
    'tags'   => array(
        'app_init'        => array(
            'Behavior\BuildLiteBehavior', // 生成运行Lite文件
        )
    ),
);
