<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/7/14
 * Time: 下午8:03
 */

namespace Inhere\Route;

/**
 * Class SRoute - this is static class version
 * @package Inhere\Route
 *
 * @method static get(string $route, mixed $handler, array $opts = [])
 * @method static post(string $route, mixed $handler, array $opts = [])
 * @method static put(string $route, mixed $handler, array $opts = [])
 * @method static delete(string $route, mixed $handler, array $opts = [])
 * @method static options(string $route, mixed $handler, array $opts = [])
 * @method static head(string $route, mixed $handler, array $opts = [])
 * @method static search(string $route, mixed $handler, array $opts = [])
 * @method static trace(string $route, mixed $handler, array $opts = [])
 * @method static any(string $route, mixed $handler, array $opts = [])
 */
class SRouter extends AbstractRouter
{
    const DEFAULT_TWO_LEVEL_KEY = '_NO_';

    /** @var int  */
    private static $routeCounter = 0;

    /**
     * some available patterns regex
     * $router->get('/user/{num}', 'handler');
     * @var array
     */
    private static $globalParams = [
        'any' => '[^/]+',   // match any except '/'
        'num' => '[0-9]+',  // match a number
        'id'  => '[1-9][0-9]*',  // match a ID number
        'act' => '[a-zA-Z][\w-]+', // match a action name
        'all' => '.*'
    ];

    /** @var string  */
    private static $currentGroupPrefix = '';

    /** @var array  */
    private static $currentGroupOption = [];

    /** @var bool  */
    private static $initialized = false;

    /**
     * static Routes - no dynamic argument match
     * 整个路由 path 都是静态字符串 e.g. '/user/login'
     * @var array
     * @see ORouter::$staticRoutes
     */
    private static $staticRoutes = [];

    /**
     * regular Routes - have dynamic arguments, but the first node is normal.
     * 第一节是个静态字符串，称之为有规律的动态路由。按第一节的信息进行存储
     * @var array[]
     * @see ORouter::$regularRoutes
     */
    private static $regularRoutes = [];

    /**
     * vague Routes - have dynamic arguments,but the first node is exists regex.
     * 第一节就包含了正则匹配，称之为无规律/模糊的动态路由
     * @var array
     * @see ORouter::$vagueRoutes
     */
    private static $vagueRoutes = [];

    /**
     * There are last route caches
     * @var array
     * @see ORouter::$routeCaches
     */
    private static $routeCaches = [];

    /**
     * some setting for self
     * @var array
     */
    private static $config = [
        // the routes php file.
        'routesFile' => null,

        // ignore last '/' char. If is True, will clear last '/'.
        'ignoreLastSep' => false,

        // 'tmpCacheNumber' => 100,
        'tmpCacheNumber' => 0,

        // intercept all request.
        // 1. If is a valid URI path, will intercept all request uri to the path.
        // 2. If is a closure, will intercept all request then call it
        // eg: '/site/maintenance' or `function () { echo 'System Maintaining ... ...'; }`
        'intercept' => '',

        // auto route match @like yii framework
        // If is True, will auto find the handler controller file.
        'autoRoute' => false,
        // The default controllers namespace, is valid when `'enable' = true`
        'controllerNamespace' => '', // eg: 'app\\controllers'
        // controller suffix, is valid when `'enable' = true`
        'controllerSuffix' => '',    // eg: 'Controller'
    ];

    /** @var DispatcherInterface */
    private static $dispatcher;

    /**
     * @param array $config
     * @throws \LogicException
     */
    public static function setConfig(array $config)
    {
        if (self::$initialized) {
            throw new \LogicException('Routing has been added, and configuration is not allowed!');
        }

        foreach ($config as $name => $value) {
            if (isset(self::$config[$name])) {
                self::$config[$name] = $value;
            }
        }

        // load routes
        if (($file = self::$config['routesFile']) && is_file($file)) {
            require $file;
        }
    }

//////////////////////////////////////////////////////////////////////
/// route collection
//////////////////////////////////////////////////////////////////////

    /**
     * Defines a route callback and method
     * @param string $method
     * @param array $args
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public static function __callStatic($method, array $args)
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException("The method [$method] parameters is required.");
        }

        self::map($method, $args[0], $args[1], isset($args[2]) ? $args[2] : []);
    }

    /**
     * Create a route group with a common prefix.
     * All routes created in the passed callback will have the given group prefix prepended.
     *
     * @ref package 'nikic/fast-route'
     * @param string $prefix
     * @param \Closure $callback
     * @param array $opts
     */
    public static function group($prefix, \Closure $callback, array $opts = [])
    {
        $previousGroupPrefix = self::$currentGroupPrefix;
        self::$currentGroupPrefix = $previousGroupPrefix . $prefix;

        $previousGroupOption = self::$currentGroupOption;
        self::$currentGroupOption = $opts;

        $callback();

        self::$currentGroupPrefix = $previousGroupPrefix;
        self::$currentGroupOption = $previousGroupOption;
    }

    /**
     * @see ORouter::map()
     * @param string|array $methods The match request method.
     * @param string $route The route path string. eg: '/user/login'
     * @param callable|string $handler
     * @param array $opts some option data
     * @return true
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public static function map($methods, $route, $handler, array $opts = [])
    {
        if (!self::$initialized) {
            self::$initialized = true;
        }

        // validate arguments
        $methods = static::validateArguments($methods, $handler);

        if ($route = trim($route)) {
            // always add '/' prefix.
            $route = $route{0} === '/' ? $route : '/' . $route;
        } else {
            $route = '/';
        }

        $route = self::$currentGroupPrefix . $route;

        // setting 'ignoreLastSep'
        if ($route !== '/' && self::$config['ignoreLastSep']) {
            $route = rtrim($route, '/');
        }

        self::$routeCounter++;
        $opts = array_replace([
            'params' => null,
            'domains'  => null,
        ], self::$currentGroupOption, $opts);
        $conf = [
            'methods' => $methods,
            'handler' => $handler,
            'option' => $opts,
        ];

        // no dynamic param params
        if (self::isNoDynamicParam($route)) {
            self::$staticRoutes[$route][] = $conf;

            return true;
        }

        // have dynamic param params

        // replace param name To pattern regex
        $params = self::getAvailableParams(self::$globalParams, $opts['params']);
        list($first, $conf) = static::parseParamRoute($route, $params, $conf);

        // route string have regular
        if ($first) {
            self::$regularRoutes[$first][] = $conf;
        } else {
            self::$vagueRoutes[] = $conf;
        }

        return true;
    }

//////////////////////////////////////////////////////////////////////
/// route match
//////////////////////////////////////////////////////////////////////

    /**
     * find the matched route info for the given request uri path
     * @param string $method
     * @param string $path
     * @return array
     */
    public static function match($path, $method = self::GET)
    {
        // if enable 'intercept'
        if ($intercept = static::$config['intercept']) {
            if (is_string($intercept) && $intercept{0} === '/') {
                $path = $intercept;
            } elseif (is_callable($intercept)) {
                return [self::FOUND, $path, [
                    'handler' => $intercept,
                    'option' => [],
                ]];
            }
        }

        // clear '//', '///' => '/'
        $path = rawurldecode(preg_replace('#\/\/+#', '/', $path));
        $method = strtoupper($method);
        $number = static::$config['tmpCacheNumber'];

        // find in class cache.
        if (self::$routeCaches && isset(self::$routeCaches[$path])) {
            return self::findInStaticRoutes(self::$staticRoutes[$path], $path, $method);
        }

        // is a static path route
        if (self::$staticRoutes && isset(self::$staticRoutes[$path])) {
            return self::findInStaticRoutes(self::$staticRoutes[$path], $path, $method);
        }

        $tmp = trim($path, '/'); // clear first,end '/'
        $first = strpos($tmp, '/') ? strstr($tmp, '/', true) : $tmp;

        // is a regular dynamic route(the first char is 1th level index key).
        if (isset(self::$regularRoutes[$first])) {
            foreach (self::$regularRoutes[$first] as $conf) {
                if (0 === strpos($path, $conf['start']) && preg_match($conf['regex'], $path, $matches)) {
                    // method not allowed
                    if (false === strpos($conf['methods'], $method . ',')) {
                        return [self::METHOD_NOT_ALLOWED, $path, explode(',', trim($conf['methods'], ','))];
                    }

                    // first node is $path
                    $conf['matches'] = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                    // cache latest $number routes.
                    if ($number > 0) {
                        if (count(self::$routeCaches) === $number) {
                            array_shift(self::$routeCaches);
                        }

                        self::$routeCaches[$path][$conf['methods']] = $conf;
                    }

                    return [self::FOUND, $path, $conf];
                }
            }
        }

        // is a irregular dynamic route
        foreach (self::$vagueRoutes as $conf) {
            if ($conf['include'] && false === strpos($path, $conf['include'])) {
                continue;
            }

            if (preg_match($conf['regex'], $path, $matches)) {
                // method not allowed
                if (false === strpos($conf['methods'], $method . ',')) {
                    return [self::METHOD_NOT_ALLOWED, $path, explode(',', trim($conf['methods'], ','))];
                }

                $conf['matches'] = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // cache last $number routes.
                if ($number > 0) {
                    if (count(self::$routeCaches) === $number) {
                        array_shift(self::$routeCaches);
                    }

                    self::$routeCaches[$path][$conf['methods']] = $conf;
                }

                return [self::FOUND, $path, $conf];
            }
        }

        // handle Auto Route
        if (
            self::$config['autoRoute'] &&
            ($handler = ORouter::matchAutoRoute($path, self::$config['controllerNamespace'], self::$config['controllerSuffix']))
        ) {
            return [self::FOUND, $path, [
                'handler' => $handler,
                'option' => [],
            ]];
        }

        // oo ... not found
        return [self::NOT_FOUND, $path, null];
    }

//////////////////////////////////////////////////////////////////////
/// route callback handler dispatch
//////////////////////////////////////////////////////////////////////

    /**
     * Runs the callback for the given request
     * @param DispatcherInterface|array $dispatcher
     * @param null|string $path
     * @param null|string $method
     * @return mixed
     */
    public static function dispatch($dispatcher = null, $path = null, $method = null)
    {
        if ($dispatcher) {
            if ($dispatcher instanceof DispatcherInterface) {
                self::$dispatcher = $dispatcher;
            } elseif (is_array($dispatcher)) {
                self::$dispatcher = new Dispatcher($dispatcher);
            }
        }

        if (!self::$dispatcher) {
            self::$dispatcher = new Dispatcher;
        }

        return self::$dispatcher->setMatcher(function ($p, $m) {
            return self::match($p, $m);
        })->dispatch($path, $method);
    }

//////////////////////////////////////////////////////////////////////
/// helper methods
//////////////////////////////////////////////////////////////////////

    /**
     * @param array $params
     */
    public static function addGlobalParams(array $params)
    {
        foreach ($params as $name => $pattern) {
            self::addGlobalParam($name, $pattern);
        }
    }

    /**
     * @param $name
     * @param $pattern
     */
    public static function addGlobalParam($name, $pattern)
    {
        $name = trim($name, '{} ');
        self::$globalParams[$name] = $pattern;
    }

    /**
     * @return int
     */
    public static function count()
    {
        return self::$routeCounter;
    }

    /**
     * @return array
     */
    public static function getStaticRoutes()
    {
        return self::$staticRoutes;
    }

    /**
     * @return \array[]
     */
    public static function getRegularRoutes()
    {
        return self::$regularRoutes;
    }

    /**
     * @return array
     */
    public static function getVagueRoutes()
    {
        return self::$vagueRoutes;
    }

    /**
     * @return array
     */
    public static function getGlobalParams()
    {
        return self::$globalParams;
    }

    /**
     * @return array
     */
    public static function getConfig()
    {
        return static::$config;
    }

    /**
     * @return DispatcherInterface
     */
    public static function getDispatcher()
    {
        return self::$dispatcher;
    }

    /**
     * @param DispatcherInterface $dispatcher
     */
    public static function setDispatcher(DispatcherInterface $dispatcher)
    {
        self::$dispatcher = $dispatcher;
    }
}
