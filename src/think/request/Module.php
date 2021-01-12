<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\request;

use common\service\FieldService;
use think\Request;
use think\Storage;

class Module
{
    protected $fieldService;

    public function __construct()
    {
        $this->fieldService = new FieldService();
    }

    /**
     * 获取缓存模块字段数据字典
     */
    public function getModuleFieldMapData()
    {
        // 读取配置缓存
        $configCacheJson = Storage::get(CACHE_PATH . 'module_dict.php', 'content', '');
        if (!empty($configCacheJson)) {
            $cacheData = json_decode($configCacheJson, true);
            Request::$moduleDictData = $cacheData;
        } else {
            // 获取所有注册模块
            $moduleDictData = $this->fieldService->generateModuleFieldCache();

            // 写入request 对象
            Request::$moduleDictData = $moduleDictData;

            // 写入runtime缓存
            Storage::put(CACHE_PATH . 'module_dict.php', json_encode($moduleDictData));
        }
    }
}
