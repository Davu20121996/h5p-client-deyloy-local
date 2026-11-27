<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->validateCsrfTokens(except: ['hub/provision', 'hub/provision/accounts', 'lti/authorize', 'lti/token', 'lti/courses/*/scores', 'lti/deep-link/return', 'lti/tenant-account/return']);
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response) {
            $request = request();
            if ($response->getStatusCode() === 419 && $request->isMethod('POST') && $request->is('logout') && ! $request->expectsJson()) {
                // A stale page may outlive its session. Do not bypass CSRF or
                // log out an active session using an invalid token.
                return redirect('/')->with('status', 'Phiên hoặc trang đăng nhập đã cũ. Vui lòng kiểm tra trạng thái đăng nhập và thử lại nếu cần.');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('hub/provision', 'hub/provision/accounts', 'lti/token', 'lti/courses/*') || $request->expectsJson());
    })->create();
