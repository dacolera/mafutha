<?php
namespace Mafutha\Web;

/**
 * The Web Application is responsible for:
 * - load http request;
 * - load router;
 * - find the apropriate route for http request;
 * - dispatch apropriate controller/action
 *
 * @author Rubens Takiguti Ribeiro <rubs33@gmail.com>
 */
class Application extends \Mafutha\AbstractApplication
{
    use \Mafutha\Behavior\Object\Hook;

    /**
     * Hook points
     * (some of them are the same point, but with different names)
     */
    const BEFORE_FIND_ROUTE    = 1;
    const AFTER_FIND_ROUTE     = 2;
    const BEFORE_CALL_ACTION   = 2;
    const AFTER_CALL_ACTION    = 3;
    const BEFORE_SEND_RESPONSE = 3;
    const AFTER_SEND_RESPONSE  = 4;

    /**
     * HTTP request
     *
     * @var \Psr\Http\Message\RequestInterface
     */
    protected $request;

    /**
     * HTTP response
     *
     * @var \Psr\Http\Message\ResponseInterface
     */
    protected $response;

    /**
     * Router is responsible for load application routes and detect
     * apropriate route for an http request
     *
     * @var \Mafutha\Web\Mvc\Router\Router
     */
    protected $router;

    /**
     * Route that match the http request by router
     *
     * @var string
     */
    protected $route;

    /**
     * Microtime when the application finished the main execution
     *
     * @var float
     */
    protected $finishTime;

    /**
     * {@inheritdoc}
     *
     * @return int Exit status (AbstractApplication::STATUS_... constants)
     */
    public function run()
    {
        try {
            $this->executeHook(self::BEFORE_FIND_ROUTE);

            $this->loadRouter();
            $this->loadRequest();
            $this->loadResponse();

            $this->route = $this->router->findRoute($this->request);

            $this->executeHook(self::BEFORE_CALL_ACTION);

            if (!$this->route) {
                throw new \Mafutha\Web\Mvc\Router\RouteNotFoundException($this->request);
            }
            $this->callAction($this->route['controller'], $this->route['action']);
            $exitStatus = self::STATUS_SUCCESS;
        } catch (\Mafutha\Web\Mvc\Router\RouteNotFoundException $exception) {
            $this->route['controller']          = $this->router->normalizeController($this->config['not_found_route']['controller']);
            $this->route['action']              = $this->router->normalizeAction($this->config['not_found_route']['action']);
            $this->route['params']['exception'] = $exception;

            $this->response->withStatus(404);
            if (isset($this->config['exception_handler'])) {
                call_user_func($this->config['exception_handler'], $exception, $this->response);
            }
            $this->callAction($this->route['controller'], $this->route['action']);
            $exitStatus = self::STATUS_ACTION_NOT_FOUND;
        } catch (\Exception $exception) {
            $this->route['controller']            = $this->router->normalizeController($this->config['error_route']['controller']);
            $this->route['action']                = $this->router->normalizeAction($this->config['error_route']['action']);
            $this->route['params']['exception']   = $exception;
            $this->route['params']['show_errors'] = $this->config['show_errors'];

            $this->response->withStatus(503);
            if (isset($this->config['exception_handler'])) {
                call_user_func($this->config['exception_handler'], $exception, $this->response);
            }
            $this->callAction($this->route['controller'], $this->route['action']);
            $exitStatus = self::STATUS_ERROR;
        } finally {
            $this->finishTime = microtime(true);
        }

        $this->executeHook(self::BEFORE_SEND_RESPONSE);

//TODO debug
$this->response->getBody()->write(sprintf('<p>Time: %0.7f</p>', $this->finishTime - $_SERVER['REQUEST_TIME_FLOAT']));
$this->response->getBody()->write(sprintf('<p>Memory: %0.2fM</p>', memory_get_peak_usage(true) / (1024 * 1024)));
$this->response->getBody()->write(sprintf('<p>Included files: %d</p>', count(get_included_files())));
$this->response->getBody()->write(sprintf('<p>Included files:</p><pre>%s</pre>', var_export(get_included_files(), true)));

        $this->sendResponse();

        $this->executeHook(self::AFTER_SEND_RESPONSE);

        return $exitStatus;
    }

    /**
     * Call the specific controller/action
     *
     * @param string $controllerClass
     * @param string $actionMethod
     * @return void
     */
    protected function callAction($controllerClass, $actionMethod)
    {
        // Assert Controller
        assert(
            sprintf(
                '(new \ReflectionClass(%s))->isSubclassOf(%s)',
                var_export($controllerClass, true),
                var_export(\Mafutha\Web\Mvc\Controller\AbstractController::class, true)
            ),
            'Controller must be an instance of ' . \Mafutha\Web\Mvc\Controller\AbstractController::class
        );

        // Assert Action
        assert(
            sprintf(
                '(new \ReflectionClass(%s))->hasMethod(%s)',
                var_export($controllerClass, true),
                var_export($actionMethod, true)
            ),
            'Controller must have the "' . $actionMethod . '" method'
        );

        // Create controller and call action
        $controller = new $controllerClass();
        $controller->setRequest($this->request);
        $controller->setResponse($this->response);
        $controller->setRouter($this->router);
        $controller->setRoute($this->route);
        call_user_func([$controller, $actionMethod]);
    }

    /**
     * Load the Router by adding the web routes to it
     *
     * @return $this
     */
    protected function loadRouter()
    {
        assert(
            sprintf(
                'is_file(%s) && is_readable(%s)',
                var_export($this->config['web_routes'], true),
                var_export($this->config['web_routes'], true)
            ),
            'Directive web_routes must be a readable file in config'
        );

        $cachedFile = 'file://' . $this->config['cache_dir'] . DIRECTORY_SEPARATOR . 'webRoutes.php';
        $routes = null;
        if (is_file($cachedFile)) {
            if (filemtime($cachedFile) > filemtime($this->config['web_routes'])) {
                $routes = require($cachedFile);
            }
        }

        if (is_null($routes)) {
            $routerParser = new \Mafutha\Web\Mvc\Router\Parser();
            $routerParser->parseFile($this->config['web_routes']);
            $this->addHook(
                self::AFTER_SEND_RESPONSE,
                function() use ($routerParser, $cachedFile) {
                    $routerParser->writeRoutes($cachedFile, true);
                }
            );
            $routes = $routerParser->getRoutes();
        }

        $this->router = new \Mafutha\Web\Mvc\Router\Router();
        $this->router->setControllerNamespace($this->config['controller_namespace']);
        foreach ($routes as $name => $route) {
            $this->router->addRoute($name, $route);
        }

        return $this;
    }

    /**
     * Load the HTTP Request with its data
     *
     * @return $this
     */
    protected function loadRequest()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (substr_compare($key, 'HTTP_', 0, 5) === 0) {
                $httpKey = str_replace('_', '-', substr($key, 5));
                $headers[$httpKey] = $value;
            }
        }

        $url = sprintf(
            '%s://%s%s%s%s%s',
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'] . '@' : '',
            $_SERVER['HTTP_HOST'],
            isset($_SERVER['SERVER_PORT']) ? ':' . $_SERVER['SERVER_PORT'] : 80,
            $_SERVER['REQUEST_URI'],
            $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''
        );

        $this->request = new \Mafutha\Web\Message\Request(
            $_SERVER['REQUEST_METHOD'],
            $url,
            $headers,
            file_get_contents('php://input'),
            substr($_SERVER['SERVER_PROTOCOL'], 5)
        );

        // Set base path of application, to hint router
        $this->request->setBasePath(parse_url($this->config['web_url'], PHP_URL_PATH));

        return $this;
    }

    /**
     * Load the default HTTP Response
     *
     * @return $this
     */
    protected function loadResponse()
    {
        $this->response = new \GuzzleHttp\Psr7\Response();
        return $this;
    }

    /**
     * Send response to the client
     *
     * @return void
     */
    protected function sendResponse()
    {
        http_response_code($this->response->getStatusCode());
        foreach ($this->response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }
        echo($this->response->getBody());
    }
}