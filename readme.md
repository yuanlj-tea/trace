### Installation

Use composer：

```
composer require yuanlj-tea/trace
```

### Usage

- 配置文件

`cp vendor/yuanlj-tea/trace/publish/opentracing.php {your config path}`

```php
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
```

- 封装`TraceClient`单例

```php
<?php

namespace common\trace;

use OpenTracing\Tracer;
use Yii;
use Trace\SpanTagManager;
use Trace\SwitchManager;
use Trace\TracerFactory;

class TraceClient
{
    private static $instance = null;

    /**
     * @var mixed
     */
    private $config;

    /**
     * @var \OpenTracing\Tracer
     */
    public static $tracer;

    private function __construct()
    {
        $this->config = Yii::$app->params['trace'];
    }

    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return \Jaeger\Tracer|null
     * @throws \Trace\exception\InvalidParam
     */
    public function getTracer()
    {
        $enable = $this->config['enable']['all'] ?? true;
        if (!$enable) {
            return null;
        }
        if (!self::$tracer instanceof Tracer) {
            self::$tracer = TracerFactory::getInstance($this->config)->initTracer();
        }

        return self::$tracer;
    }

    public function flush()
    {
        if (self::$tracer instanceof Tracer) {
            self::$tracer->flush();
        }
    }

    /**
     * @return SpanTagManager
     */
    public function getSpanTagManager()
    {
        $spanTagManager = new SpanTagManager();
        $spanTagManager->apply($this->config['tags'] ?? []);

        return $spanTagManager;
    }

    /**
     * @return SwitchManager
     */
    public function getSwitchManager()
    {
        $switchManager = new SwitchManager();
        $switchManager->apply($this->config['enable'] ?? []);

        return $switchManager;
    }
}
```

- `TraceSwitch`控制是否上报

```php
<?php

namespace common\trace;

class TraceSwitch
{
    private static $switch = true;

    public static function setSwitch(bool $bool)
    {
        self::$switch = $bool;
    }

    public static function getSwitch()
    {
        return self::$switch;
    }
}
```

- `SpanHelper`

```php
<?php

namespace common\trace\span;

use Yii;
use OpenTracing\Span;
use common\trace\Resp;
use Trace\SpanStarter;
use OpenTracing\Tracer;
use Trace\SwitchManager;
use Trace\SpanTagManager;
use common\helper\UtilHelper;
use common\trace\TraceSwitch;
use common\trace\TraceClient;
use ua_api\helper\RequestEnv;
use common\libs\collection\Str;
use const OpenTracing\Formats\TEXT_MAP;
use const OpenTracing\Tags\SPAN_KIND_RPC_SERVER;

class SpanHelper
{
    use SpanStarter;

    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @var SwitchManager
     */
    private $switch;

    /**
     * @var SpanTagManager
     */
    private $spanTag;

    /**
     * 不上报的接口
     * @var string[]
     */
    private $noTracePath = [
        'metrics*',
    ];

    public function __construct()
    {
        $traceClient = TraceClient::getInstance();

        $this->tracer = $traceClient->getTracer();
        $this->switch = $traceClient->getSwitchManager();
        $this->spanTag = $traceClient->getSpanTagManager();
    }

    /**
     * is record trace
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    private function isAddTrace(): bool
    {
        if (!TraceSwitch::getSwitch()) {
            return false;
        }

        $path = Yii::$app->request->getPathInfo();

        foreach ($this->noTracePath as $v) {
            if (Str::is($v, $path)) {
                TraceSwitch::setSwitch(false);
                return false;
            }
        }
        return true;
    }

    /**
     * build request span
     * @return Span|null
     * @throws \yii\base\InvalidConfigException
     */
    public function buildRequestSpan(): ?Span
    {
        if (!$this->isAddTrace()) {
            return null;
        }
        $request = Yii::$app->request;
        $path = !empty(RequestEnv::getEnv('request_path')) ? RequestEnv::getEnv('request_path') : $request->getPathInfo();
        $method = $request->getMethod();
        $header = $request->getHeaders()->toArray();

        $span = $this->startSpan('request ' . $path, [], SPAN_KIND_RPC_SERVER, $header);
        if ($span instanceof Span) {
            $span->setTag($this->spanTag->get('request', 'path'), $path);
            $span->setTag($this->spanTag->get('request', 'method'), $method);
            $span->setTag($this->spanTag->get('request', 'header'), cjson_encode($header));
            $span->setTag('trace_id', RequestEnv::getRequestFloatNumber());
            if (!empty($orderNo = RequestEnv::getEnv('order_no'))) {
                $span->setTag('order_no', $orderNo);
            }
            $body = transfer_large_param(array_merge($request->queryParams, $request->bodyParams));
            $span->setTag($this->spanTag->get('request', 'body'), cjson_encode($body));
            return $span;
        }
        return null;
    }

    /**
     * appen exception to span
     * @param Span $span
     * @param \Throwable $t
     */
    public function appendExceptionToSpan(\Throwable $t)
    {
        if (!$this->isAddTrace()) {
            return;
        }
        if (!$this->switch->isEnable('exception')) {
            return;
        }

        $span = $this->startSpan('exception');
        if ($span instanceof Span) {
            $span->setTag($this->spanTag->get('exception', 'class'), get_class($t));
            $span->setTag($this->spanTag->get('exception', 'code'), (string)$t->getCode());
            $span->setTag($this->spanTag->get('exception', 'message'), $t->getMessage());
            $span->setTag($this->spanTag->get('exception', 'stack_trace'), $t->getTraceAsString());
            if (!empty($orderNo = RequestEnv::getEnv('order_no'))) {
                $span->setTag('order_no', $orderNo);
            }
            $span->finish();
        }
    }

    /**
     * appen response to span
     * @param $statusCode
     * @param null $resp
     */
    public function appendResponseToSpan($statusCode, $resp = null)
    {
        if (!$this->isAddTrace()) {
            return;
        }
        if (!$this->switch->isEnable('response')) {
            return;
        }
        $body = Resp::getRawRespData() ?? (is_array($resp) ? cjson_encode($resp) : (string)$resp);
        $span = $this->startSpan('response');
        if ($span instanceof Span) {
            $span->setTag($this->spanTag->get('response', 'body'), $body);
            $span->setTag($this->spanTag->get('response', 'status_code'), $statusCode);
            if (!empty($orderNo = RequestEnv::getEnv('order_no'))) {
                $span->setTag('order_no', (string)$orderNo);
            }
            if (!empty($mobile = RequestEnv::getEnv('mobile'))) {
                $span->setTag('mobile', (string)$mobile);
            }
            $span->finish();
        }
    }

    /**
     * append sql to span
     * @param $sql
     * @param $cost
     * @return \OpenTracing\Span|void
     */
    public function appendSqlToSpan($sql): ?Span
    {
        if (!$this->switch->isEnable('db') || !$this->isSqlReport($sql)) {
            return null;
        }
        $span = $this->startSpan('mysql');
        if ($span instanceof Span) {
            $span->setTag($this->spanTag->get('db', 'query'), $sql);
            return $span;
        }
        return null;
    }

    /**
     * 判断sql是否需要上报
     * @param $sql
     * @return bool
     */
    private function isSqlReport($sql)
    {
        $patttern = ['SHOW', 'database', 'information_schema'];
        foreach ($patttern as $v) {
            if (strpos($sql, $v) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * append redis command to span
     * @param $command
     * @return null
     * @throws \Trace\exception\InvalidParam
     */
    public function appendRedisToSpan($command): ?Span
    {
        if (!$this->switch->isEnable('redis')) {
            return null;
        }
        $span = $this->startSpan('redis');
        if ($span instanceof Span) {
            $span->setTag($this->spanTag->get('redis', 'command'), $command);
            return $span;
        }
        return null;
    }

    /**
     * append http request to span
     * @param string $path
     * @param string $method
     * @param $options
     * @return array
     */
    public function appendHttpRequestToSpan(string $path, string $method, $options): array
    {
        if (!$this->switch->isEnable('guzzle')) {
            return [null, $options];
        }
        $purePath = strpos($path, '?') === false ? $path : strstr($path, '?', true);
        $span = $this->startSpan('http_request ' . $purePath);
        if ($span instanceof Span) {
            // add trace header
            $appendHeaders = [];
            $tracer = $this->tracer;
            $tracer->inject($span->getContext(), TEXT_MAP, $appendHeaders);
            $options['headers'] = array_replace($options['headers'] ?? [], $appendHeaders);

            // set tag
            $span->setTag($this->spanTag->get('request', 'path'), $path);
            $span->setTag($this->spanTag->get('request', 'method'), $method);
            $span->setTag($this->spanTag->get('request', 'body'), cjson_encode($options));
            if (!empty($orderNo = RequestEnv::getEnv('order_no'))) {
                $span->setTag('order_no', $orderNo);
            }
            return [$span, $options];
        }
        return [null, $options];
    }

    /**
     * append trace to span
     */
    public function appendTraceToSpan()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        array_shift($trace);
        $trace = array_reverse($trace);

        $span = $this->startSpan('trace_info');
        if ($span instanceof Span) {
            $span->setTag('request_trace', UtilHelper::jsonEncode($trace));
            $span->finish();
        }
    }

    /**
     * @param $log
     * @param string $message_tag
     */
    public function appendBizLogToSpan($log, $message_tag = 'biz_log')
    {
        if (!$this->switch->isEnable('biz_log')) {
            return;
        }
        $tagName = !empty($message_tag) ? $message_tag : 'biz_log';
        $span = $this->startSpan($tagName);
        if ($span instanceof Span) {
            $span->setTag('log', cjson_encode($log));
            $span->finish();
        }
    }

    public function finish($span)
    {
        if ($span instanceof Span) {
            $span->finish();
        }
    }

    public function setTag($span, string $key, $value)
    {
        if ($span instanceof Span) {
            $span->setTag($key, $value);
        }
    }

    public function batchSetTag($span, array $tag)
    {
        if ($span instanceof Span) {
            foreach ($tag as $key => $value) {
                $span->setTag($key, $value);
            }
        }
    }
}
```

