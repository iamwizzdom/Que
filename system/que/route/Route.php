<?php
/**
 * Created by PhpStorm.
 * User: Wisdom Emenike
 * Date: 12/27/2018
 * Time: 7:44 AM
 */

namespace que\route;

use que\common\exception\PreviousException;
use que\common\exception\RouteException;
use que\common\structure\Add;
use que\common\structure\Api;
use que\common\structure\Edit;
use que\common\structure\Info;
use que\common\structure\Page;
use que\common\structure\Receiver;
use que\common\structure\Resource;
use que\common\validate\Track;
use que\error\RuntimeError;
use que\http\output\response\Html;
use que\http\output\response\Json;
use que\http\output\response\Jsonp;
use que\http\output\response\Plain;
use que\security\CSRF;
use que\security\permission\RoutePermission;
use que\template\Composer;

final class Route extends RouteCompiler
{

    /**
     * @var string
     */
    private static string $method = "GET";

    public static function init() {

        try {

            ob_start();

            $uri = self::getRequestUri();

            if (!empty(APP_ROOT_FOLDER) && in_array(APP_ROOT_FOLDER, $uriTokens = self::tokenizeUri($uri))) {
                http()->_server()->add('REQUEST_URI_ORIGINAL', $uri);

                $uri_extract = array_extract($uriTokens, (($pos = strpos_in_array($uriTokens, APP_ROOT_FOLDER,
                        STRPOS_IN_ARRAY_OPT_ARRAY_INDEX)) + 1), (array_size($uriTokens) - 1));

                http()->_server()->add('REQUEST_URI', $uri = implode("/", $uri_extract));
            }

            self::$method = $method = strtoupper(http()->_server()->get("REQUEST_METHOD"));

            if (!($method === 'GET' || $method === 'POST')) {
                throw new RouteException("Sorry, ({$method} request) is an unsupported request method",
                    "Route Error", HTTP_METHOD_NOT_ALLOWED);
            }


            $path = APP_PATH . DIRECTORY_SEPARATOR . $uri;

            if (str_contains($path, "#"))
                $path = substr($path, 0, strpos($path, "#"));

            if (str_contains($path, "?"))
                $path = substr($path, 0, strpos($path, "?"));

            if (is_file($path)) {
                render_file($path, pathinfo($path, PATHINFO_FILENAME));
                exit;
            }

            self::compile();

            self::resolve();

            self::render();

        } catch (RouteException $e) {

            RuntimeError::render(E_USER_NOTICE, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(),
                method_exists($e, 'getTitle') ? ($e->getTitle() ?: "Route Error") : "Route Error",
                $e->getCode() ?: HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private static function render() {

        try {

            $route = self::getCurrentRoute();

            if (!empty($route)) {

                switch ($route->getType()) {
                    case "web":
                        self::render_web_route($route);
                        break;
                    case "api":
                        self::render_api_route($route);
                        break;
                    case "resource":
                        self::render_resource_route($route);
                        break;
                    default:
                        throw new RouteException(sprintf("%s is an invalid url", current_url()), "Route Error", HTTP_NOT_FOUND);
                }

            } else throw new RouteException(sprintf("%s is an invalid url", current_url()), "Route Error", HTTP_NOT_FOUND);

        } catch (RouteException $e) {

            RuntimeError::render(E_USER_NOTICE, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(),
                method_exists($e, 'getTitle') ? ($e->getTitle() ?: "Route Error") : "Route Error",
                $e->getCode() ?: HTTP_INTERNAL_SERVER_ERROR);

        }

    }

    /**
     * @param RouteEntry $route
     */
    private static function render_web_route(RouteEntry $route) {

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header("Content-Type: text/html");

        try {

            $http = http();

            //check if JWT is required
            if ($route->isRequireJWTAuth() === true) RouteInspector::validateJWT($http);

            if (!empty($module = $route->getModule())) {

                if (!class_exists($module, true))
                    throw new RouteException(sprintf(
                        "Sorry, the module (%s) bound to the current route \n(%s) does not exist\n",
                        $module, current_url()));

                $instance = new $module();

                if ($instance instanceof RoutePermission && !$instance->hasPermission($route))
                    throw new RouteException(sprintf("You dont have permission to the current route (%s)",
                        current_url()), "Access denied", HTTP_UNAUTHORIZED);

                $args = $http->_server()->get("URI_ARGS");

                if ($instance instanceof Add) {

                    if (self::$method === "GET") {
                        if ($route->isRequireCSRFAuth() === true) CSRF::getInstance()->generateToken();
                        $instance->onLoad($args);
                    } else {
                        if ($route->isRequireCSRFAuth() === true) RouteInspector::validateCSRF();
                        $instance->onReceive($args);
                    }

                    $instance->setTemplate(Composer::getInstance());

                } elseif ($instance instanceof Edit) {

                    if (self::$method === "GET") {
                        if ($route->isRequireCSRFAuth() === true) CSRF::getInstance()->generateToken();
                        $instance->onLoad($args, $instance->info($args));
                    } else {
                        if ($route->isRequireCSRFAuth() === true) RouteInspector::validateCSRF();
                        $instance->onReceive($args, $instance->info($args));
                    }

                } elseif ($instance instanceof Info) {

                    if (self::$method === "GET") {

                        if ($route->isRequireCSRFAuth() === true) CSRF::getInstance()->generateToken();
                        $instance->onLoad($args, $instance->info($args));

                    } else {

                        if (!$instance instanceof Receiver)
                            throw new RouteException(sprintf(
                                "Sorry, the current route (%s)\n does not support (%s request).\n",
                                current_url(), self::$method), "Route Error", HTTP_METHOD_NOT_ALLOWED);

                        if ($route->isRequireCSRFAuth() === true) RouteInspector::validateCSRF();
                        $instance->onReceive($args, $instance->info($args));
                    }

                } elseif ($instance instanceof Page) {

                    if (self::$method === "GET") {

                        if ($route->isRequireCSRFAuth() === true) CSRF::getInstance()->generateToken();
                        $instance->onLoad($args);

                    } else {

                        if (!$instance instanceof Receiver)
                            throw new RouteException(sprintf(
                                "Sorry, the current route (%s)\n does not support (%s request).\n",
                                current_url(), self::$method), "Route Error", HTTP_METHOD_NOT_ALLOWED);

                        if ($route->isRequireCSRFAuth() === true) RouteInspector::validateCSRF();
                        $instance->onReceive($args);
                    }

                } else throw new RouteException(sprintf(
                    "Sorry, the module bound to the current route \n(%s) " .
                    "is registered as a web module but does not implement \n" .
                    "a valid web module interface\n", current_url()));

                $instance->setTemplate(Composer::getInstance());

            } else throw new RouteException(sprintf(
                "Sorry, the current route (%s)\n is not bound to a module", current_url()));

        } catch (RouteException $e) {

            RuntimeError::render(E_USER_NOTICE, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(),
                method_exists($e, 'getTitle') ? ($e->getTitle() ?: "Route Error") : "Route Error",
                $e->getCode() ?: HTTP_INTERNAL_SERVER_ERROR);

        }
    }

    /**
     * @param RouteEntry $route
     */
    private static function render_api_route(RouteEntry $route) {

        header('Access-Control-Allow-Headers: *');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        try {

            $http = http();

            //check if JWT is required
            if ($route->isRequireJWTAuth() === true) RouteInspector::validateJWT($http);

            if (!empty($module = $route->getModule())) {

                if (!class_exists($module, true))
                    throw new RouteException(sprintf(
                        "Sorry, the module (%s) bound to the current route \n(%s) does not exist\n",
                        $module, current_url()));

                $instance = new $module();

                if ($instance instanceof RoutePermission && !$instance->hasPermission($route))
                    throw new RouteException(sprintf("You dont have permission to the current route (%s)",
                        current_url()), "Access denied", HTTP_UNAUTHORIZED);

                if (!$instance instanceof Api) throw new RouteException(sprintf(
                    "Sorry, the module bound to the current route \n(%s) " .
                    "is registered as an API module but does not implement \n" .
                    "a valid API module interface\n", current_url()));

                if ($route->isRequireCSRFAuth() === true) {
                    RouteInspector::validateCSRF();
                    header("X-Xsrf-Token: " . CSRF::getInstance()->getToken());
                    header("X-Track-Token: " . Track::generateToken());
                }

                $response = $instance->process($http->_server()->get("URI_ARGS"));

                if ($response instanceof Json) {
                    header("Content-Type: application/json", true);
                    $data = $response->getJson();
                    if (!$data) throw new RouteException("Failed to output response", "Output Error",
                        HTTP_NO_CONTENT, PreviousException::getInstance(1));
                    echo $data;
                } elseif ($response instanceof Jsonp) {
                    header("Content-Type: " . mime_type_from_extension('js'), true);
                    $data = $response->getJsonp();
                    if (!$data) throw new RouteException("Failed to output response", "Output Error",
                        HTTP_NO_CONTENT, PreviousException::getInstance(1));
                    echo $data;
                } elseif ($response instanceof Html) {
                    header("Content-Type: " . mime_type_from_extension('html'), true);
                    echo $response->getHtml();
                } elseif ($response instanceof Plain) {
                    header("Content-Type: " . mime_type_from_extension('txt'), true);
                    echo $response->getData();
                } elseif (is_array($response)) {

                    header("Content-Type: application/json", true);

                    if (isset($response['code']) && is_numeric($response['code']))
                        $http->http_response_code(intval($response['code']));

                    $option = 0; $depth = 512;

                    if (isset($response['option']) && is_numeric($response['option'])) {
                        $option = intval($response['option']);
                        unset($response['option']);
                    }

                    if (isset($response['depth']) && is_numeric($response['depth'])) {
                        $depth = intval($response['depth']);
                        unset($response['depth']);
                    }

                    $data = json_encode($response, $option, $depth);
                    if (!$data) throw new RouteException("Failed to output response");
                    echo $data;

                } else throw new RouteException(sprintf(
                    "Sorry, the API module bound to the current route (%s) did not return a valid response", current_url()));

            } else throw new RouteException(sprintf(
                "Sorry, the current route (%s) is not bound to a module", current_url()));

        } catch (RouteException $e) {

            RuntimeError::render(E_USER_NOTICE, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(),
                method_exists($e, 'getTitle') ? ($e->getTitle() ?: "Route Error") : "Route Error",
                $e->getCode() ?: HTTP_INTERNAL_SERVER_ERROR);

        }

    }

    /**
     * @param RouteEntry $route
     */
    private static function render_resource_route(RouteEntry $route) {

        header('Access-Control-Allow-Headers: *');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        try {

            $http = http();

            //check if JWT is required
            if ($route->isRequireJWTAuth() === true) RouteInspector::validateJWT($http);

            if (!empty($module = $route->getModule())) {

                if (!class_exists($module, true))
                    throw new RouteException(sprintf(
                        "Sorry, the module (%s) bound to the current route \n(%s) does not exist\n",
                        $module, current_url()));

                $instance = new $module();

                if ($instance instanceof RoutePermission && !$instance->hasPermission($route))
                    throw new RouteException(sprintf("You dont have permission to the current route (%s)",
                        current_url()), "Access denied", HTTP_UNAUTHORIZED);

                if (!$instance instanceof Resource) throw new RouteException(sprintf(
                    "Sorry, the module bound to the current route \n(%s) " .
                    "is registered as a resource module but does not implement \n" .
                    "a valid resource module interface\n", current_url()));

                if ($route->isRequireCSRFAuth() === true) {
                    RouteInspector::validateCSRF();
                    header("X-Xsrf-Token: " . CSRF::getInstance()->getToken());
                    header("X-Track-Token: " . Track::generateToken());
                }

                $instance->render($http->_server()->get("URI_ARGS"));

            } else throw new RouteException(sprintf(
                "Sorry, the current route (%s) is not bound to a module", current_url()));

        } catch (RouteException $e) {

            RuntimeError::render(E_USER_NOTICE, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace(),
                method_exists($e, 'getTitle') ? ($e->getTitle() ?: "Route Error") : "Route Error",
                $e->getCode() ?: HTTP_INTERNAL_SERVER_ERROR);

        }

    }

    /**
     * @return RouteRegistrar
     */
    public static function register() {
        return RouteRegistrar::register();
    }

    /**
     * @param string $uri
     * @param string|null $type
     * @return RouteEntry|null
     */
    public static function getRouteEntry(string $uri, string $type = null): ?RouteEntry {

        $uriTokens = self::tokenizeUri($uri);
        $routeEntries = self::getRouteEntries();

        foreach ($routeEntries as $routeEntry) {
            if (!$routeEntry instanceof RouteEntry) continue;
            if (!is_null($type) && strcmp($routeEntry->getType(), $type) != 0) continue;
            if (implode('--', $uriTokens) === implode('--', $routeEntry->uriTokens)) return $routeEntry;
            else {

                $routeArgs = RouteInspector::getRouteArgs($routeEntry->getUri());

                $matches = 0;

                foreach ($routeArgs as $key => $routeArg) {

                    $routeArgList = [$routeArg];

                    if (str_contains($routeArg, ":"))
                        $routeArgList = explode(":", $routeArg, 2);

                    $key = array_search('{' . $routeArg . '}', $routeEntry->uriTokens);

                    if (!isset($uriTokens[$key])) break;

                    $uriArgValue = $uriTokens[$key];

                    if (isset($routeArgList[0]) && strcmp($routeArgList[0], current(RouteInspector::getRouteArgs($uriArgValue))) == 0) $matches++;
                    elseif (isset($routeArgList[1]) && strcmp($routeArgList[1], "any") != 0) {
                        try {
                            RouteInspector::validateArgDataType($routeArgList[1], $uriArgValue);
                            $uriTokens[$key] = '--';
                            $matches++;
                        } catch (RouteException $e) {
                            break;
                        }
                    } else $matches++;

                }

                if (($size = array_size($routeArgs)) > 0 && $size == $matches) {
                    $entryUri = preg_replace("/\{(.*?)\}/", "--", implode('--', $routeEntry->uriTokens));
                    $uri = preg_replace("/\{(.*?)\}/", "--", implode('--', $uriTokens));
                    if ($entryUri === $uri) return $routeEntry;
                } elseif (implode('--', $uriTokens) === implode('--', $routeEntry->uriTokens)) return $routeEntry;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public static function &getRouteEntries(): array
    {
        return self::register()->getRouteEntries();
    }

    /**
     * @return string
     */
    public static function getRequestUri(): string {
        return http()->_server()->get("REQUEST_URI", "/");
    }

}