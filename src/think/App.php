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

use Psr\Container\ContainerInterface;
use think\exception\HttpResponseException;
use think\exception\HttpException;
use think\Exception\ExceptionHandler;
use think\Exception\ExceptionHandlerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

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
     * @var bool
     */
    protected static $_supportStaticFiles = true;

    /**
     * @var bool
     */
    protected static $_supportPHPFiles = false;

    /**
     * @var array
     */
    protected static $_callbacks = [];

    /**
     * @var Worker
     */
    protected static $_worker = null;

    /**
     * @var ContainerInterface
     */
    protected static $_container = null;

    /**
     * @var Logger
     */
    protected static $_logger = null;

    /**
     * @var string
     */
    protected static $_publicPath = '';

    /**
     * @var string
     */
    protected static $_configPath = '';

    /**
     * @var TcpConnection
     */
    protected static $_connection = null;

    /**
     * @var Request
     */
    protected static $_request = null;

    /**
     * @var int
     */
    protected static $_maxRequestCount = 1000000;

    /**
     * @var int
     */
    protected static $_gracefulStopTimer = null;


    /**
     * App constructor.
     * @param Worker $worker
     * @param $container
     * @param $logger
     * @param $app_path
     * @param $public_path
     */
    public function __construct(Worker $worker, $container, $logger, $app_path, $public_path)
    {
        static::$_worker = $worker;
        static::$_container = $container;
        static::$_logger = $logger;
        static::$_publicPath = $public_path;
        static::loadController($app_path);

        $max_requst_count = (int)C('SERVER.max_request');
        if ($max_requst_count > 0) {
            static::$_maxRequestCount = $max_requst_count;
        }
        static::$_supportStaticFiles = true;
    }


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

        // request hook
        Hook::listen("request", $request);

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
     * @param \think\Request $request 请求实例
     * @param array $config 配置信息
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
     * @param TcpConnection $connection
     * @param \Webman\Http\Request $request
     * @return null
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        static $request_count = 0;

        if (++$request_count > static::$_maxRequestCount) {
            static::tryToGracefulExit();
        }

        try {
            static::$_request = $request;
            static::$_connection = $connection;
            $path = $request->path();
            $key = $request->method() . $path;

            static::send($connection, $key, $request);
        } catch (\Throwable $e) {
            static::send($connection, $e->getMessage(), $request);
        }
        return null;
    }

    protected static function exceptionResponse(\Throwable $e, $request)
    {
        try {
            $app = $request->app ?: '';
            $exception_config = Config::get('exception');
            $default_exception = $exception_config[''] ?? ExceptionHandler::class;
            $exception_handler_class = $exception_config[$app] ?? $default_exception;

            /** @var ExceptionHandlerInterface $exception_handler */
            $exception_handler = static::$_container->make($exception_handler_class, [
                'logger' => static::$_logger,
                'debug' => Config::get('app.debug')
            ]);
            $exception_handler->report($e);
            $response = $exception_handler->render($request, $e);
            return $response;
        } catch (\Throwable $e) {
            return Config::get('app.debug') ? (string)$e : $e->getMessage();
        }
    }

    /**
     * @param $app
     * @param $call
     * @param null $args
     * @param bool $with_global_middleware
     * @param RouteObject $route
     * @return \Closure|mixed
     */
    protected static function getCallback($app, $call, $args = null, $with_global_middleware = true, $route = null)
    {
        $args = $args === null ? null : \array_values($args);
        if ($args === null) {
            $callback = $call;
        } else {
            $callback = function ($request) use ($call, $args) {
                return $call($request, ...$args);
            };
        }
        return $callback;
    }

    /**
     * @return ContainerInterface
     */
    public static function container()
    {
        return static::$_container;
    }

    /**
     * @return Request
     */
    public static function request()
    {
        return static::$_request;
    }

    /**
     * @return TcpConnection
     */
    public static function connection()
    {
        return static::$_connection;
    }

    /**
     * @return Worker
     */
    public static function worker()
    {
        return static::$_worker;
    }

    /**
     * @param $connection
     * @param $path
     * @param $key
     * @param Request $request
     * @return bool
     */
    protected static function findRoute($connection, $path, $key, Request $request)
    {
        $ret = Route::dispatch($request->method(), $path);
        if ($ret[0] === Dispatcher::FOUND) {
            $ret[0] = 'route';
            $callback = $ret[1]['callback'];
            $route = $ret[1]['route'];
            $app = $controller = $action = '';
            $args = !empty($ret[2]) ? $ret[2] : null;
            if (\is_array($callback) && isset($callback[0]) && $controller = \get_class($callback[0])) {
                $app = static::getAppByController($controller);
                $action = static::getRealMethod($controller, $callback[1]) ?? '';
            }
            $callback = static::getCallback($app, $callback, $args, true, $route);
            static::$_callbacks[$key] = [$callback, $app, $controller ? $controller : '', $action];
            list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
            static::send($connection, $callback($request), $request);
            if (\count(static::$_callbacks) > 1024) {
                static::clearCache();
            }
            return true;
        }
        return false;
    }


    /**
     * @param $connection
     * @param $path
     * @param $key
     * @param $request
     * @return bool
     */
    protected static function findFile($connection, $path, $key, $request)
    {
        $public_dir = static::$_publicPath;
        $file = \realpath("$public_dir/$path");
        if (false === $file || false === \is_file($file)) {
            return false;
        }

        // Security check
        if (strpos($file, $public_dir) !== 0) {
            static::send($connection, new Response(400), $request);
            return true;
        }
        if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            if (!static::$_supportPHPFiles) {
                return false;
            }
            static::$_callbacks[$key] = [function ($request) use ($file) {
                return static::execPhpFile($file);
            }, '', '', ''];
            list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
            static::send($connection, static::execPhpFile($file), $request);
            return true;
        }

        if (!static::$_supportStaticFiles) {
            return false;
        }

        static::$_callbacks[$key] = [static::getCallback('__static__', function ($request) use ($file) {
            return (new Response())->file($file);
        }, null, false), '', '', ''];
        list($callback, $request->app, $request->controller, $request->action) = static::$_callbacks[$key];
        static::send($connection, $callback($request), $request);
        return true;
    }

    /**
     * @param TcpConnection $connection
     * @param $response
     * @param Request $request
     */
    protected static function send(TcpConnection $connection, $response, Request $request)
    {
        $keep_alive = $request->header('connection');
        if (($keep_alive === null && $request->protocolVersion() === '1.1')
            || $keep_alive === 'keep-alive' || $keep_alive === 'Keep-Alive'
        ) {
            $connection->send($response);
            return;
        }
        $connection->close($response);
    }

    /**
     * @param TcpConnection $connection
     * @param $request
     */
    protected static function send404(TcpConnection $connection, $request)
    {
        static::send($connection, new Response(404, [], file_get_contents(static::$_publicPath . '/404.html')), $request);
    }

    /**
     * @param $path
     * @return array|bool
     */
    protected static function parseControllerAction($path)
    {
        if ($path === '/' || $path === '') {
            $controller_class = 'app\controller\Index';
            $action = 'index';
            if (\class_exists($controller_class, false) && \is_callable([static::$_container->get($controller_class), $action])) {
                return [
                    'app' => '',
                    'controller' => \app\controller\Index::class,
                    'action' => static::getRealMethod($controller_class, $action)
                ];
            }
            $controller_class = 'app\index\controller\Index';
            if (\class_exists($controller_class, false) && \is_callable([static::$_container->get($controller_class), $action])) {
                return [
                    'app' => 'index',
                    'controller' => \app\index\controller\Index::class,
                    'action' => static::getRealMethod($controller_class, $action)
                ];
            }
            return false;
        }
        if ($path && $path[0] === '/') {
            $path = \substr($path, 1);
        }
        $explode = \explode('/', $path);
        $action = 'index';

        $controller = $explode[0];
        if ($controller === '') {
            return false;
        }
        if (!empty($explode[1])) {
            $action = $explode[1];
        }
        $controller_class = "app\\controller\\$controller";
        if (\class_exists($controller_class, false) && \is_callable([static::$_container->get($controller_class), $action])) {
            return [
                'app' => '',
                'controller' => \get_class(static::$_container->get($controller_class)),
                'action' => static::getRealMethod($controller_class, $action)
            ];
        }

        $app = $explode[0];
        $controller = $action = 'index';
        if (!empty($explode[1])) {
            $controller = $explode[1];
            if (!empty($explode[2])) {
                $action = $explode[2];
            }
        }
        $controller_class = "app\\$app\\controller\\$controller";
        if (\class_exists($controller_class, false) && \is_callable([static::$_container->get($controller_class), $action])) {
            return [
                'app' => $app,
                'controller' => \get_class(static::$_container->get($controller_class)),
                'action' => static::getRealMethod($controller_class, $action)
            ];
        }
        return false;
    }

    /**
     * @param $controller_calss
     * @return string
     */
    protected static function getAppByController($controller_calss)
    {
        if ($controller_calss[0] === '\\') {
            $controller_calss = \substr($controller_calss, 1);
        }
        $tmp = \explode('\\', $controller_calss, 3);
        if (!isset($tmp[1])) {
            return '';
        }
        return $tmp[1] === 'controller' ? '' : $tmp[1];
    }

    /**
     * @param $file
     * @return string
     */
    public static function execPhpFile($file)
    {
        \ob_start();
        // Try to include php file.
        try {
            include $file;
        } catch (\Exception $e) {
            echo $e;
        }
        return \ob_get_clean();
    }

    /**
     * @return void
     */
    public static function loadController($path)
    {
        if (\strpos($path, 'phar://') === false) {
            foreach (\glob($path . '/controller/*.php') as $file) {
                require_once $file;
            }
            foreach (\glob($path . '/*/controller/*.php') as $file) {
                require_once $file;
            }
        } else {
            $dir_iterator = new \RecursiveDirectoryIterator($path);
            $iterator = new \RecursiveIteratorIterator($dir_iterator);
            foreach ($iterator as $file) {
                if (is_dir($file)) {
                    continue;
                }
                $fileinfo = new \SplFileInfo($file);
                $ext = $fileinfo->getExtension();
                if (\strpos($file, '/controller/') !== false && $ext === 'php') {
                    require_once $file;
                }
            }
        }
    }

    /**
     * Clear cache.
     */
    public static function clearCache()
    {
        static::$_callbacks = [];
    }

    /**
     * @param $class
     * @param $method
     * @return string
     */
    protected static function getRealMethod($class, $method)
    {
        $method = \strtolower($method);
        $methods = \get_class_methods($class);
        foreach ($methods as $candidate) {
            if (\strtolower($candidate) === $method) {
                return $candidate;
            }
        }
        return $method;
    }

    /**
     * @return void
     */
    protected static function tryToGracefulExit()
    {
        if (static::$_gracefulStopTimer === null) {
            static::$_gracefulStopTimer = Timer::add(rand(1, 10), function () {
                if (\count(static::$_worker->connections) === 0) {
                    Worker::stopAll();
                }
            });
        }
    }
}
