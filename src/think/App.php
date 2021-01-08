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
namespace think;

use think\exception\HttpResponseException;
use think\exception\HttpException;

class App
{
    /**
     * @var bool 是否初始化过
     */
    protected static $init = false;

    /**
     * @var string 当前模块路径
     */
    public static $modulePath;

    /**
     * @var string 应用类库命名空间
     */
    public static $namespace = 'App';

    /**
     * @var bool 应用类库后缀
     */
    public static $suffix = false;

    /**
     * @var bool 应用路由检测
     */
    protected static $routeCheck;

    /**
     * @var bool 严格路由检测
     */
    protected static $routeMust;

    /**
     * @var array 请求调度分发
     */
    protected static $dispatch;


    /**
     * 应用程序初始化
     * @throws Exception
     */
    public static function init()
    {
        // 日志目录转换为绝对路径 默认情况下存储到公共模块下面
        C('LOG_PATH', realpath(LOG_PATH) . '/Common/');

        // 定义当前请求的系统常量
        if (!defined('NOW_TIME')) {
            define('NOW_TIME', $_SERVER['REQUEST_TIME']);
        }

        if (!IS_CLI) {
            define('REQUEST_METHOD', $_SERVER['REQUEST_METHOD']);
            define('IS_GET', REQUEST_METHOD == 'GET' ? true : false);
            define('IS_POST', REQUEST_METHOD == 'POST' ? true : false);
            define('IS_PUT', REQUEST_METHOD == 'PUT' ? true : false);
            define('IS_DELETE', REQUEST_METHOD == 'DELETE' ? true : false);
        }

        if (C('REQUEST_VARS_FILTER')) {
            // 全局安全过滤
            array_walk_recursive($_GET, 'think_filter');
            array_walk_recursive($_POST, 'think_filter');
            array_walk_recursive($_REQUEST, 'think_filter');
        }

        if (!IS_CLI) {
            define('IS_AJAX', ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || !empty($_POST[C('VAR_AJAX_SUBMIT')]) || !empty($_GET[C('VAR_AJAX_SUBMIT')])) ? true : false);

            // TMPL_EXCEPTION_FILE 改为绝对地址
            C('TMPL_EXCEPTION_FILE', realpath(C('TMPL_EXCEPTION_FILE')));
        }

        // 加载动态应用公共文件和配置
        load_ext_file(COMMON_PATH);

        return;
    }


    /**
     * 初始化应用，并返回配置信息
     * @return mixed
     * @throws Exception
     */
    public static function initCommon()
    {
        if (empty(self::$init)) {
            self::init();

            self::$init = true;
        }

        return C();
    }

    /**
     * 运行应用实例 入口文件使用的快捷方法
     * @param Request|null $request
     * @return array|Response
     * @throws Exception
     * @throws \ReflectionException
     */
    public static function run(Request $request = null)
    {
        $request = is_null($request) ? Request::instance() : $request;

        $config = C();

        $header = [];

        try {
            // 模块/控制器绑定
            if (defined('BIND_MODULE')) {
                BIND_MODULE && Route::bind(BIND_MODULE);
            } elseif (C('AUTO_BIND_MODULE')) {
                // 入口自动绑定
                $name = pathinfo($request->baseFile(), PATHINFO_FILENAME);
                if ($name && 'index' != $name && is_dir(APP_PATH . $name)) {
                    Route::bind($name);
                }
            }

            // 验证请求Token，排除登陆方法
            Hook::listen("request", $request);

            $request->filter(C('DEFAULT_FILTER'));

            // 获取应用调度信息
            $dispatch = self::$dispatch;

            // 未设置调度信息则进行 URL 路由检测
            if (empty($dispatch)) {
                $dispatch = self::routeCheck($request, $config);
            }

            // 记录当前调度信息
            $request->dispatch($dispatch);

            // URL调度结束标签
            Hook::listen('url_dispatch');

            // 应用初始化标签
            Hook::listen('app_init');

            // 请求缓存检查
            $request->cache(
                $config['REQUEST_CACHE'],
                $config['REQUEST_CACHE_EXPIRE'],
                $config['REQUEST_CACHE_EXCEPT']
            );

            // 应用开始标签
            Hook::listen('app_begin');

            // 处理跨域
            $checkCorsResult = Cors::check($request);
            if ($checkCorsResult instanceof Response) {
                // Options请求直接返回
                $data = $checkCorsResult;
            } else {
                $header = $checkCorsResult;
                $data = self::exec($dispatch, $config);
            }

        } catch (HttpResponseException $exception) {
            $data = $exception->getResponse();
        }


        // 清空类的实例化
        Loader::clearInstance();

        // 输出数据到客户端
        if ($data instanceof Response) {
            $response = $data->header($header);
        } elseif (!is_null($data)) {
            // 默认自动识别响应输出类型
            $type = $request->isAjax() ?
                $config['DEFAULT_AJAX_RETURN'] :
                $config['DEFAULT_RETURN_TYPE'];

            $response = Response::create($data, $type)->header($header);
        } else {
            $response = Response::create()->header($header);
        }

        // 记录应用初始化时间
        G('initTime');

        // 应用结束标签
        Hook::listen('app_end');

        return $response;
    }

    /**
     * 执行模块
     * @access public
     * @param array $result 模块/控制器/操作
     * @param array $config 配置参数
     * @param bool $convert 是否自动转换控制器和操作名
     * @return mixed
     * @throws \ReflectionException
     */
    public static function module($result, $config, $convert = null)
    {
        if (is_string($result)) {
            $result = explode('/', $result);
        }

        $request = Request::instance();

        if ($config['MULTI_MODULE']) {
            // 多模块部署
            $module = strip_tags($result[0] ?: $config['DEFAULT_MODULE']);
            $bind = Route::getBind('module');
            $available = false;

            if ($bind) {
                // 绑定模块
                list($bindModule) = explode('/', $bind);

                if (empty($result[0])) {
                    $module = $bindModule;
                    $available = true;
                } elseif ($module == $bindModule) {
                    $available = true;
                }
            } elseif (!in_array($module, $config['MODULE_DENY_LIST']) && is_dir(APP_PATH . $module)) {
                $available = true;
            }

            // 模块初始化
            if ($module && $available) {

                // 初始化模块
                $request->module($module);

                // 模块请求缓存检查
                $request->cache(
                    $config['REQUEST_CACHE'],
                    $config['REQUEST_CACHE_EXPIRE'],
                    $config['REQUEST_CACHE_EXCEPT']
                );
            } else {
                throw new HttpException(404, 'module not exists:' . $module);
            }
        } else {
            // 单一模块部署
            $module = strip_tags($result[0] ?: $config['DEFAULT_MODULE']);
            $request->module($module);
        }

        // 设置默认过滤机制
        $request->filter($config['DEFAULT_FILTER']);

        // 当前模块路径
        App::$modulePath = APP_PATH . ($module ? $module . DS : '');

        // 是否自动转换控制器和操作名
        $convert = is_bool($convert) ? $convert : $config['URL_CONVERT'];

        // 获取控制器名
        $controller = strip_tags($result[1] ?: $config['DEFAULT_CONTROLLER']);

        if (!preg_match('/^[A-Za-z](\w|\.)*$/', $controller)) {
            throw new HttpException(404, 'controller not exists:' . $controller);
        }

        $controller = $convert ? strtolower($controller) : $controller;

        // 获取操作名
        $actionName = strip_tags($result[2] ?: $config['DEFAULT_ACTION']);
        if (!empty($config['ACTION_CONVERT'])) {
            $actionName = Loader::parseName($actionName, 1);
        } else {
            $actionName = $convert ? strtolower($actionName) : $actionName;
        }

        // 设置当前请求的控制器、操作
        $request->controller(Loader::parseName($controller, 1))->action($actionName);

        try {
            $instance = Loader::controller(
                $controller,
                $config['URL_CONTROLLER_LAYER'],
                $config['CONTROLLER_SUFFIX'],
                $config['EMPTY_CONTROLLER']
            );
        } catch (ClassNotFoundException $e) {
            throw new HttpException(404, 'controller not exists:' . $e->getClass());
        }

        // 获取当前操作名
        $action = $actionName . $config['ACTION_SUFFIX'];

        $vars = [];
        if (is_callable([$instance, $action])) {
            // 执行操作方法
            $call = [$instance, $action];
            // 严格获取当前操作方法名
            $reflect = new \ReflectionMethod($instance, $action);
            $methodName = $reflect->getName();
            $suffix = $config['ACTION_SUFFIX'];
            $actionName = $suffix ? substr($methodName, 0, -strlen($suffix)) : $methodName;
            $request->action($actionName);

        } elseif (is_callable([$instance, '_empty'])) {
            // 空操作
            $call = [$instance, '_empty'];
            $vars = [$actionName];
        } else {
            // 操作不存在
            throw new HttpException(404, 'method not exists:' . get_class($instance) . '->' . $action . '()');
        }

        return self::invokeMethod($call, $vars);
    }

    /**
     * URL路由检测（根据PATH_INFO)
     * @access public
     * @param  \think\Request $request 请求实例
     * @param  array $config 配置信息
     * @return array
     * @throws \think\Exception
     */
    public static function routeCheck($request, array $config)
    {
        $path = $request->path();
        $depr = $config['URL_PATHINFO_DEPR'];
        $result = false;

        // 路由检测
        $check = !is_null(self::$routeCheck) ? self::$routeCheck : $config['URL_ROUTE_ON'];
        if ($check) {
            // 开启路由
            if (is_file(RUNTIME_PATH . 'route.php')) {
                // 读取路由缓存
                $rules = include RUNTIME_PATH . 'route.php';
                is_array($rules) && Route::rules($rules);
            } else {
                $files = $config['ROUTE_CONFIG_FILE'];
                foreach ($files as $file) {
                    if (is_file(COMMON_PATH . 'config/' . $file . CONF_EXT)) {
                        // 导入路由配置
                        $rules = include COMMON_PATH . 'config/' . $file . CONF_EXT;
                        is_array($rules) && Route::import($rules);
                    }
                }
            }

            // 路由检测（根据路由定义返回不同的URL调度）
            $result = Route::check($request, $path, $depr, $config['URL_DOMAIN_DEPLOY']);
            $must = !is_null(self::$routeMust) ? self::$routeMust : $config['URL_ROUTE_MUST'];

            if ($must && false === $result) {
                // 路由无效
                StrackE('Invalid route config.', -404);
            }
        }

        // 路由无效 解析模块/控制器/操作/参数... 支持控制器自动搜索
        if (false === $result) {
            $result = Route::parseUrl($path, $depr, $config['CONTROLLER_AUTO_SEARCH']);
        }

        return $result;
    }


    /**
     * 执行应用程序
     * @return mixed
     * @throws \ReflectionException
     */
    public static function exec($dispatch, $config)
    {
        switch ($dispatch['type']) {
            case 'redirect': // 重定向跳转
                $data = Response::create($dispatch['url'], 'redirect')
                    ->code($dispatch['status']);
                break;
            case 'module': // 模块/控制器/操作
                $data = self::module(
                    $dispatch['module'],
                    $config,
                    isset($dispatch['convert']) ? $dispatch['convert'] : null
                );
                break;
            case 'controller': // 执行控制器操作
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = Loader::action(
                    $dispatch['controller'],
                    $vars,
                    $config['URL_CONTROLLER_LAYER'],
                    $config['CONTROLLER_SUFFIX']
                );
                break;
            case 'method': // 回调方法
                $vars = array_merge(Request::instance()->param(), $dispatch['var']);
                $data = self::invokeMethod($dispatch['method'], $vars);
                break;
            case 'function': // 闭包
                $data = self::invokeFunction($dispatch['function']);
                break;
            case 'response': // Response 实例
                $data = $dispatch['response'];
                break;
            default:
                throw new \InvalidArgumentException('dispatch type not support');
        }

        return $data;
    }

    /**
     * 执行Action操作
     * @param $module
     * @param $action
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    public static function invokeAction($module, $action)
    {
        if (!preg_match('/^[A-Za-z](\w)*$/', $action)) {
            // 非法操作
            throw new \ReflectionException();
        }
        //执行当前操作
        $method = new \ReflectionMethod($module, $action);
        if ($method->isPublic() && !$method->isStatic()) {
            $class = new \ReflectionClass($module);

            // 前置操作
            if ($class->hasMethod('_before_' . $action)) {
                $before = $class->getMethod('_before_' . $action);
                if ($before->isPublic()) {
                    $before->invoke($module);
                }
            }
            // URL参数绑定检测
            if ($method->getNumberOfParameters() > 0 && C('URL_PARAMS_BIND')) {
                switch ($_SERVER['REQUEST_METHOD']) {
                    case 'POST':
                        $vars = array_merge($_GET, $_POST);
                        break;
                    case 'PUT':
                        parse_str(file_get_contents('php://input'), $vars);
                        break;
                    default:
                        $vars = $_GET;
                }
                $params = $method->getParameters();
                $paramsBindType = C('URL_PARAMS_BIND_TYPE');
                foreach ($params as $param) {
                    $name = $param->getName();
                    if (1 == $paramsBindType && !empty($vars)) {
                        $args[] = array_shift($vars);
                    } elseif (0 == $paramsBindType && isset($vars[$name])) {
                        $args[] = $vars[$name];
                    } elseif ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } else {
                        StrackE(L('_PARAM_ERROR_') . ':' . $name);
                    }
                }
                // 开启绑定参数过滤机制
                if (C('URL_PARAMS_SAFE')) {
                    $filters = C('URL_PARAMS_FILTER') ?: C('DEFAULT_FILTER');
                    if ($filters) {
                        $filters = explode(',', $filters);
                        foreach ($filters as $filter) {
                            $args = array_map_recursive($filter, $args); // 参数过滤
                        }
                    }
                }
                array_walk_recursive($args, 'think_filter');
                $content = $method->invokeArgs($module, $args);
            } else {
                $content = $method->invoke($module);
            }
            // 后置操作
            if ($class->hasMethod('_after_' . $action)) {
                $after = $class->getMethod('_after_' . $action);
                if ($after->isPublic()) {
                    $after->invoke($module);
                }
            }

            // 返回输出内容
            return $content;
        } else {
            // 操作方法不是Public 抛出异常
            throw new \ReflectionException();
        }
    }

    /**
     * 设置当前请求的调度信息
     * @access public
     * @param array|string $dispatch 调度信息
     * @param string $type 调度类型
     * @return void
     */
    public static function dispatch($dispatch, $type = 'module')
    {
        self::$dispatch = ['type' => $type, $type => $dispatch];
    }

    /**
     * 执行函数或者闭包方法 支持参数调用
     * @access public
     * @param string|array|\Closure $function 函数或者闭包
     * @param array $vars 变量
     * @return mixed
     * @throws \ReflectionException
     */
    public static function invokeFunction($function, $vars = [])
    {
        $reflect = new \ReflectionFunction($function);
        $args = self::bindParams($reflect, $vars);

        // 记录执行信息
        APP_DEBUG && Log::record('[ RUN ] ' . $reflect->__toString(), 'info');

        return $reflect->invokeArgs($args);
    }


    /**
     * 绑定参数
     * @param $reflect
     * @param array $vars
     * @return array
     * @throws \ReflectionException
     */
    private static function bindParams($reflect, $vars = [])
    {
        if (empty($vars)) {
            // 自动获取请求变量
            if (C('URL_PARAMS_BIND_TYPE')) {
                $vars = Request::instance()->route();
            } else {
                $vars = Request::instance()->param();
            }
        }
        $args = [];
        // 判断数组类型 数字数组时按顺序绑定参数
        reset($vars);
        $type = key($vars) === 0 ? 1 : 0;
        if ($reflect->getNumberOfParameters() > 0) {
            $params = $reflect->getParameters();
            foreach ($params as $param) {
                $name = $param->getName();
                $class = $param->getClass();
                if ($class) {
                    $className = $class->getName();
                    $bind = Request::instance()->$name;
                    if ($bind instanceof $className) {
                        $args[] = $bind;
                    } else {
                        if (method_exists($className, 'invoke')) {
                            $method = new \ReflectionMethod($className, 'invoke');
                            if ($method->isPublic() && $method->isStatic()) {
                                $args[] = $className::invoke(Request::instance());
                                continue;
                            }
                        }
                        $args[] = method_exists($className, 'instance') ? $className::instance() : new $className;
                    }
                } elseif (1 == $type && !empty($vars)) {
                    $args[] = array_shift($vars);
                } elseif (0 == $type && isset($vars[$name])) {
                    $args[] = $vars[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    throw new \InvalidArgumentException('method param miss:' . $name);
                }
            }
        }
        return $args;
    }


    /**
     * 调用反射执行类的实例化 支持依赖注入
     * @access public
     * @param string $class 类名
     * @param array $vars 变量
     * @return object
     * @throws \ReflectionException
     */
    public static function invokeClass($class, $vars = [])
    {
        $reflect = new \ReflectionClass($class);
        $constructor = $reflect->getConstructor();
        if ($constructor) {
            $args = self::bindParams($constructor, $vars);
        } else {
            $args = [];
        }
        return $reflect->newInstanceArgs($args);
    }


    /**
     * 调用反射执行类的方法 支持参数绑定
     * @access public
     * @param string|array $method 方法
     * @param array $vars 变量
     * @return mixed
     * @throws \ReflectionException
     */
    public static function invokeMethod($method, $vars = [])
    {
        if (is_array($method)) {
            $class = is_object($method[0]) ? $method[0] : self::invokeClass($method[0]);
            $reflect = new \ReflectionMethod($class, $method[1]);
        } else {
            // 静态方法
            $reflect = new \ReflectionMethod($method);
        }
        $args = self::bindParams($reflect, $vars);

        APP_DEBUG && Log::record('[ RUN ] ' . $reflect->class . '->' . $reflect->name . '[ ' . $reflect->getFileName() . ' ]', Log::INFO);
        return $reflect->invokeArgs(isset($class) ? $class : null, $args);
    }


    /**
     * tp logo
     * @return string
     */
    public static function logo()
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAADAAAAAwCAYAAABXAvmHAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyBpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMC1jMDYwIDYxLjEzNDc3NywgMjAxMC8wMi8xMi0xNzozMjowMCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENTNSBXaW5kb3dzIiB4bXBNTTpJbnN0YW5jZUlEPSJ4bXAuaWlkOjVERDVENkZGQjkyNDExRTE5REY3RDQ5RTQ2RTRDQUJCIiB4bXBNTTpEb2N1bWVudElEPSJ4bXAuZGlkOjVERDVENzAwQjkyNDExRTE5REY3RDQ5RTQ2RTRDQUJCIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6NURENUQ2RkRCOTI0MTFFMTlERjdENDlFNDZFNENBQkIiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6NURENUQ2RkVCOTI0MTFFMTlERjdENDlFNDZFNENBQkIiLz4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+IDw/eHBhY2tldCBlbmQ9InIiPz5fx6IRAAAMCElEQVR42sxae3BU1Rk/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';
    }
}
