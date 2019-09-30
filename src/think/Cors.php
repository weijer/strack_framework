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
namespace think;

/**
 * 处理Cors跨域
 */
class Cors
{

    /**
     * @param Request $request
     * @return array|Response
     */
    public static function check(Request $request)
    {
        if (!empty(C('web_url'))) {
            $allowUrl = explode(',', C('web_url'));
        } else {
            $allowUrl = ['*'];
        }

        $header = [
            //'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE',
            'Access-Control-Allow-Credentials' => true,
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Token, If-Match, If-Modified-Since, If-None-Match, If-Unmodified-Since, X-Requested-With',
        ];

        foreach ($allowUrl as $origin){
            $header[ 'Access-Control-Allow-Origin'] = $origin;
        }

        if ($request->method(true) == 'OPTIONS') {
            return Response::create()->code(204)->header($header);
        }

        return $header;
    }

}
