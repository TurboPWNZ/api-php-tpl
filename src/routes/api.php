<?php
use FastRoute\RouteCollector;

return FastRoute\simpleDispatcher(function(RouteCollector $r) {

//    $r->get('/', 'HomeController@index');
    $r->get('/v1/auth/check', [
        'handler' => 'AuthController@check',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

    $r->get('/v1/auth/get-account', [
        'handler' => 'AuthController@getAccount',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

    /**
     * Авторизация получение JWT токена
     */
    $r->post('/v1/auth/login', 'AuthController@login');

    $r->post('/v1/game/spin', [
        'handler' => 'GameController@spin',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

//    $r->post('/v1/auth/login', [
//        'handler' => 'AuthController@login',
//        'middleware' =>
//            function (\Symfony\Component\HttpFoundation\Request $request) {
//                $captchaCheck = \Api\Middleware::captcha($request);
//                if ($captchaCheck !== true) {
//                    return $captchaCheck;
//                }
//
//                return true;
//            }
//    ]);

    $r->post('/account/change-password', [
        'handler' => 'AuthController@changePassword',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

//    $r->get('/users/{id:\d+}', 'UserController@show');

    $r->post('/payment/create-invoice/{provider}', [
        'handler' => 'PaymentController@createInvoice',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

    $r->get('/payment/list', [
        'handler' => 'PaymentController@list',
        'middleware' =>
            function (\Symfony\Component\HttpFoundation\Request $request) {
                $authResult = \Api\Middleware::auth($request);
                if ($authResult !== true) {
                    return $authResult;
                }

                return true;
            }
    ]);

    $r->post('/payment/notyfication/{provider}', 'NotificationController@request');

//    $r->post('/payment/notyfication/{provider}', function (\Symfony\Component\HttpFoundation\Request $request, $provider) {
//
//        $log = sprintf(
//            "[%s]\nProvider: %s\nIP: %s\nHeaders: %s\nBody: %s\n\n",
//            date('Y-m-d H:i:s'),
//            $provider,
//            $request->getClientIp(),
//            json_encode($request->headers->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
//            $request->getContent()
//        );
//
//        file_put_contents(
//            __DIR__ . '/payment_notification.log',
//            $log,
//            FILE_APPEND | LOCK_EX
//        );
//
//        return new \Symfony\Component\HttpFoundation\JsonResponse([
//            'success' => true
//        ]);
//    });

});
