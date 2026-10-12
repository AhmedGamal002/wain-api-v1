<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * بيحوّل أي خطأ على /api/* لشكل العقد: { message, code, errors? } (06 القسم 1).
 *
 * الرسالة بلغة الطلب من lang/{locale}/api.php. أكواد الدومين (PLACE_NOT_AVAILABLE,
 * INVALID_CREDENTIALS...) بتتطلع بـ ApiException.
 */
final class ApiErrorRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function toResponse(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::envelope($e->status, $e->errorCode, $e->getMessage(), $e->errors, $e->headers),
            $e instanceof ValidationException => self::envelope(422, 'VALIDATION_ERROR', '', $e->errors()),
            $e instanceof AuthenticationException => self::envelope(401, 'UNAUTHENTICATED'),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => self::envelope(403, 'FORBIDDEN'),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::envelope(404, 'NOT_FOUND'),
            $e instanceof ThrottleRequestsException,
            $e instanceof TooManyRequestsHttpException => self::envelope(429, 'TOO_MANY_REQUESTS', '', null, $e->getHeaders()),
            // في الـ debug بنسيب Laravel يعرض التفاصيل
            config('app.debug') => null,
            $e instanceof HttpExceptionInterface => self::envelope($e->getStatusCode(), 'ERROR', '', null, $e->getHeaders()),
            default => self::envelope(500, 'SERVER_ERROR'),
        };
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    private static function envelope(int $status, string $code, string $message = '', ?array $errors = null, array $headers = []): JsonResponse
    {
        $body = [
            'message' => $message !== '' ? $message : __("api.{$code}"),
            'code' => $code,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
