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

/**
 * ThinkPHP 视图类
 */
class View
{
    // 模板输出变量
    protected $tVar = [];

    // 模板主题
    protected $theme = '';

    // 模板引擎参数
    protected $config = [
        // 视图基础目录（集中式）
        'view_base' => '',
        // 模板起始路径
        'view_path' => '',
        // 模板文件后缀
        'view_suffix' => 'html',
        // 模板文件名分隔符
        'view_depr' => DS,
        // 是否开启模板编译缓存,设为false则每次都会重新编译
        'tpl_cache' => true,
        // 默认模板渲染规则 1 解析为小写+下划线 2 全部转换小写
        'auto_rule' => 1,
    ];

    /**
     * 模板变量赋值
     * @access public
     * @param mixed $name
     * @param mixed $value
     */
    public function assign($name, $value = '')
    {
        if (is_array($name)) {
            $this->tVar = array_merge($this->tVar, $name);
        } else {
            $this->tVar[$name] = $value;
        }
    }

    /**
     * 取得模板变量的值
     * @access public
     * @param string $name
     * @return mixed
     */
    public function get($name = '')
    {
        if ('' === $name) {
            return $this->tVar;
        }
        return isset($this->tVar[$name]) ? $this->tVar[$name] : false;
    }

    /**
     * 加载模板和页面输出 可以返回输出内容
     * @access public
     * @param string $templateFile 模板文件名
     * @param string $charset 模板输出字符集
     * @param string $contentType 输出类型
     * @param string $content 模板输出内容
     * @param string $prefix 模板缓存前缀
     * @return mixed
     */
    public function display($templateFile = '', $charset = '', $contentType = '', $content = '', $prefix = '')
    {
        $data = $this->fetch($templateFile, $content, $prefix);
        return Response::create($data, 'html');
    }

    /**
     * 解析和获取模板内容 用于输出
     * @access public
     * @param string $templateFile 模板文件名
     * @param string $content 模板输出内容
     * @param string $prefix 模板缓存前缀
     * @return string
     */
    public function fetch($templateFile = '', $content = '', $prefix = '')
    {
        if (empty($content)) {
            $templateFile = $this->parseTemplate($templateFile);
            // 模板文件不存在直接返回
            if (!is_file($templateFile)) {
                StrackE(L('_TEMPLATE_NOT_EXIST_') . ':' . $templateFile);
            }

        } else {
            defined('THEME_PATH') or define('THEME_PATH', $this->getThemePath());
        }
        // 页面缓存
        ob_start();
        ob_implicit_flush(0);
        if ('php' == strtolower(C('TMPL_ENGINE_TYPE'))) {
            // 使用PHP原生模板
            $_content = $content;
            // 模板阵列变量分解成为独立变量
            extract($this->tVar, EXTR_OVERWRITE);
            // 直接载入PHP模板
            empty($_content) ? include $templateFile : eval('?>' . $_content);
        } else {
            // 视图解析标签
            $params = array('var' => $this->tVar, 'file' => $templateFile, 'content' => $content, 'prefix' => $prefix);
            Hook::listen('view_parse', $params);
        }
        // 获取并清空缓存
        $content = ob_get_clean();
        // 内容过滤标签
        Hook::listen('view_filter', $content);
        if (APP_DEBUG) { // debug模式时，将后台分配变量输出到浏览器控制台
            $parseVar = empty($this->tVar) ? json_encode(array()) : json_encode($this->tVar);
            $content = $content . '<script type="text/javascript">var PARSE_VAR = ' . $parseVar . ';</script>';
        }
        // 输出模板文件
        return $content;
    }

    /**
     * 自动定位模板文件
     * @access protected
     * @param string $template 模板文件规则
     * @return string
     */
    public function parseTemplate($template = '')
    {
        // 分析模板文件规则
        $request = Request::instance();
        // 获取视图根目录
        if (strpos($template, '@')) {
            // 跨模块调用
            list($module, $template) = explode('@', $template);
        }
        if ($this->config['view_base']) {
            // 基础视图目录
            $module = isset($module) ? $module : $request->module();
            $path = $this->config['view_base'] . ($module ? $module . DS : '');
        } else {
            $path = isset($module) ? APP_PATH . $module . DS . 'view' . DS : $this->config['view_path'];
        }

        $depr = $this->config['view_depr'];
        if (0 !== strpos($template, '/')) {
            $template = str_replace(['/', ':'], $depr, $template);
            $controller = Loader::parseName($request->controller());
            if ($controller) {
                if ('' == $template) {
                    // 如果模板文件名为空 按照默认规则定位
                    $template = str_replace('.', DS, $controller) . $depr . (1 == $this->config['auto_rule'] ? Loader::parseName($request->action(true)) : $request->action());
                } elseif (false === strpos($template, $depr)) {
                    $template = str_replace('.', DS, $controller) . $depr . $template;
                }
            }
        } else {
            $template = str_replace(['/', ':'], $depr, substr($template, 1));
        }
        return $path . ltrim($template, '/') . '.' . ltrim($this->config['view_suffix'], '.');
    }

    /**
     * 获取当前的模板路径
     * @access protected
     * @param  string $module 模块名
     * @return string
     */
    protected function getThemePath($module = '')
    {
        // 获取当前主题名称
        $theme = $this->getTemplateTheme();
        // 获取当前主题的模版路径
        $tmplPath = C('VIEW_PATH'); // 模块设置独立的视图目录
        if (!$tmplPath) {
            // 定义TMPL_PATH 则改变全局的视图目录到模块之外
            $tmplPath = defined('TMPL_PATH') ? TMPL_PATH . $module . '/' : APP_PATH . $module . '/' . C('DEFAULT_V_LAYER') . '/';
        }
        return $tmplPath . $theme;
    }

    /**
     * 设置当前输出的模板主题
     * @access public
     * @param  mixed $theme 主题名称
     * @return View
     */
    public function theme($theme)
    {
        $this->theme = $theme;
        return $this;
    }

    /**
     * 获取当前的模板主题
     * @access private
     * @return string
     */
    private function getTemplateTheme()
    {
        if ($this->theme) {
            // 指定模板主题
            $theme = $this->theme;
        } else {
            /* 获取模板主题名称 */
            $theme = C('DEFAULT_THEME');
            if (C('TMPL_DETECT_THEME')) {
                // 自动侦测模板主题
                $t = C('VAR_TEMPLATE');
                if (isset($_GET[$t])) {
                    $theme = $_GET[$t];
                } elseif (cookie('think_template')) {
                    $theme = cookie('think_template');
                }
                if (!in_array($theme, explode(',', C('THEME_LIST')))) {
                    $theme = C('DEFAULT_THEME');
                }
                cookie('think_template', $theme, 864000);
            }
        }
        defined('THEME_NAME') || define('THEME_NAME', $theme); // 当前模板主题名称
        return $theme ? $theme . '/' : '';
    }

}
