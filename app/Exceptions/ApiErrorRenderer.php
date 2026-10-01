<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns ANY error on an /api/* route into one predictable JSON shape, so the mobile app
 * parses every failure the same way (phase-5-authentication.md § 3):
 *
 *   { "message": "Taglish text for the user", "code": "MACHINE_CODE", "errors": { "field": [...] } }
 *
 * Stack traces and internal messages are never included, except under "debug" when
 * APP_DEBUG=true on a developer's machine.
 */
class ApiErrorRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $errors, $headers] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->errors, []],

            $e instanceof ValidationException => [
                422, 'VALIDATION_FAILED', __('api.validation_failed'), $e->errors(), [],
            ],

            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Kailangan mong mag-login ulit.', [], []],

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', __('api.forbidden'), [], []],

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', __('api.not_found'), [], []],

            $e instanceof ThrottleRequestsException => [
                429, 'TOO_MANY_ATTEMPTS', __('api.too_many_attempts'), [], $e->getHeaders(),
            ],

            $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', __('api.method_not_allowed'), [], []],

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(), 'HTTP_ERROR', __('api.http_error'), [], $e->getHeaders(),
            ],

            default => [500, 'SERVER_ERROR', __('api.server_error'), [], []],
        };

        $body = ['message' => $message, 'code' => $code];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        if ($status === 429 && isset($headers['Retry-After'])) {
            $body['retry_after'] = (int) $headers['Retry-After']; // seconds, for "try again in N s"
        }

        // Developer help only on a local machine with APP_DEBUG=true; never in production.
        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return response()->json($body, $status, $headers);
    }
}
