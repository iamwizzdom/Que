<?php

namespace que\middleware;

use que\common\exception\QueException;
use que\http\HTTP;
use que\http\input\Input;
use que\http\request\Request;
use que\route\Route;
use que\security\CSRF;
use que\security\Middleware;
use que\security\MiddlewareResponse;
use que\support\Arr;

class VerifyCsrfToken extends Middleware
{
    public function handle(Input $input): MiddlewareResponse
    {
        $route = Route::getCurrentRoute();

        if ($route->isForbidCSRF() === true && !Arr::includes($route->getIgnoredCRSFRequestMethods(), Request::getMethod())) {

            try {

                $this->validateCSRF($input);

            } catch (QueException $e) {

                CSRF::getInstance()->generateToken();
                $this->setAccess(false);
                $this->setTitle($e->getTitle());
                $this->setResponse($e->getMessage());
                $this->setResponseCode(HTTP::EXPIRED_AUTHENTICATION);
                return $this;
            }
        }

        CSRF::getInstance()->generateToken();

        return parent::handle($input); // TODO: Change the autogenerated stub
    }

    /**
     * @param Input $input
     * @throws QueException
     */
    private function validateCSRF(Input $input) {

        $token = $input->getCookie()->get('XSRF-TOKEN', $input->get('X-Csrf-Token'));

        if (empty($token)) {
            foreach (
                [
                    'X-CSRF-TOKEN',
                    'x-csrf-token',
                    'X-XSRF-TOKEN',
                    'X-Xsrf-Token',
                    'x-xsrf-token',
                    'csrf',
                    'xsrf',
                    'Csrf',
                    'Xsrf',
                    'CSRF',
                    'XSRF'
                ] as $key
            ) if (!empty($token = $input->get($key))) break;
        }

        CSRF::getInstance()->validateToken((!is_null($token) ? $token : ""));
    }
}