<?php

return [
    'default' => \Trace\Constants::JAEGER,
    'enable' => [
        // 控制是否走上报逻辑
        'all' => true,
        // 控制是否上报http请求
        'guzzle' => true,
        // 控制是否上报redis请求
        'redis' => true,
        // 控制是否上报db请求
        'db' => true,
        'method' => true,
        // 控制是否上报响应结果
        'response' => true,
        // 控制是否上报异常响应信息
        'exception' => true,
        // 控制业务日志是否上报
        'biz_log' => true,
    ],
    'tracer' => [
        \Trace\Constants::JAEGER => [
            'app_name' => 'your app name',
            'options' => [
                /*
                 * You can uncomment the sampler lines to use custom strategy.
                 *
                 * For more available configurations,
                 * @see https://github.com/jonahgeorge/jaeger-client-php
                 */
                'sampler' => [
                    'type' => \Jaeger\SAMPLER_TYPE_CONST,
                    'param' => true,
                ],
                'local_agent' => [
                    'reporting_host' => 'your jaeger host',
                    'reporting_port' => 5775,
                ],
                'ip_version' => \Jaeger\Config::IPV4,
            ],
        ],
    ],
    'tags' => [
        'http_client' => [
            'http.url' => 'http.url',
            'http.method' => 'http.method',
            'http.status_code' => 'http.status_code',
        ],
        'redis' => [
            'command' => 'redis.command',
            'cost' => 'redis.cost',
        ],
        'db' => [
            'query' => 'db.query',
            'statement' => 'db.statement',
            'cost' => 'db.cost',
        ],
        'exception' => [
            'class' => 'exception.class',
            'code' => 'exception.code',
            'message' => 'exception.message',
            'stack_trace' => 'exception.stack_trace',
        ],
        'request' => [
            'path' => 'request.path',
            'method' => 'request.method',
            'header' => 'request.header',
            'body' => 'request.body',
            'respcode' => 'resp.code',
            'respbody' => 'resp.body',
        ],
        'coroutine' => [
            'id' => 'coroutine.id',
        ],
        'response' => [
            'status_code' => 'response.status_code',
            'body' => 'response.body',
        ],
    ],
];

