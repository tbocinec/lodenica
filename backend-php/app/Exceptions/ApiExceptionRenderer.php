<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Translates exceptions into Lodenica's stable error response shape:
 *
 *   {
 *     statusCode, error, code?, message, details?, path, timestamp
 *   }
 */
final class ApiExceptionRenderer
{
    public static function render(\Throwable $e, Request $request): JsonResponse
    {
        $body = self::toBody($e, $request->path());

        return new JsonResponse($body, $body['statusCode']);
    }

    private static function toBody(\Throwable $e, string $path): array
    {
        $timestamp = now()->toIso8601String();

        if ($e instanceof DomainException) {
            $status = $e->httpStatus();

            return [
                'statusCode' => $status,
                'error' => Response::$statusTexts[$status] ?? 'Error',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details,
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof ValidationException) {
            return [
                'statusCode' => Response::HTTP_BAD_REQUEST,
                'error' => 'Bad Request',
                'code' => 'VALIDATION_ERROR',
                'message' => self::flattenValidationMessages($e->errors()),
                'details' => $e->errors(),
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof ModelNotFoundException) {
            return [
                'statusCode' => Response::HTTP_NOT_FOUND,
                'error' => 'Not Found',
                'code' => 'RESOURCE_NOT_FOUND',
                'message' => 'Záznam nebol nájdený.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof NotFoundHttpException) {
            return [
                'statusCode' => Response::HTTP_NOT_FOUND,
                'error' => 'Not Found',
                'message' => 'Cesta nebola nájdená.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return [
                'statusCode' => Response::HTTP_METHOD_NOT_ALLOWED,
                'error' => 'Method Not Allowed',
                'message' => 'HTTP metóda nie je povolená pre túto cestu.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof AuthenticationException) {
            return [
                'statusCode' => Response::HTTP_UNAUTHORIZED,
                'error' => 'Unauthorized',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Pre túto operáciu sa musíte prihlásiť.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof AuthorizationException) {
            return [
                'statusCode' => Response::HTTP_FORBIDDEN,
                'error' => 'Forbidden',
                'message' => $e->getMessage(),
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        if ($e instanceof QueryException) {
            return self::handleQueryException($e, $path, $timestamp);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return [
                'statusCode' => $status,
                'error' => Response::$statusTexts[$status] ?? 'Error',
                'message' => $e->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        // Fallback: in debug mode, surface the exception class+message for
        // easier troubleshooting; otherwise a generic Slovak message.
        $debug = config('app.debug');

        return [
            'statusCode' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'error' => 'Internal Server Error',
            'message' => $debug
                ? sprintf('%s: %s', $e::class, $e->getMessage())
                : 'Neočakávaná chyba servera.',
            'path' => '/'.$path,
            'timestamp' => $timestamp,
        ];
    }

    private static function handleQueryException(QueryException $e, string $path, string $timestamp): array
    {
        $sqlState = $e->getCode();
        $message = strtolower($e->getMessage());

        // 23P01 — exclusion_violation (Postgres). Our daterange GIST exclusion
        // constraint that prevents overlapping CONFIRMED reservations.
        if ($sqlState === '23P01'
            || str_contains($message, 'reservations_no_overlap_excl')
            || str_contains($message, 'exclusion')) {
            return [
                'statusCode' => Response::HTTP_CONFLICT,
                'error' => 'Conflict',
                'code' => 'RESERVATION_OVERLAP',
                'message' => 'Vybraný zdroj je v zadanom termíne už rezervovaný.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        // 23505 — unique_violation
        if ($sqlState === '23505') {
            return [
                'statusCode' => Response::HTTP_CONFLICT,
                'error' => 'Conflict',
                'code' => 'UNIQUE_CONSTRAINT',
                'message' => 'Záznam s týmito hodnotami už existuje.',
                'path' => '/'.$path,
                'timestamp' => $timestamp,
            ];
        }

        return [
            'statusCode' => Response::HTTP_BAD_REQUEST,
            'error' => 'Bad Request',
            'code' => 'DATABASE_ERROR',
            'message' => 'Chyba databázy.',
            'path' => '/'.$path,
            'timestamp' => $timestamp,
        ];
    }

    private static function flattenValidationMessages(array $errors): array
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = $field.': '.$message;
            }
        }

        return $flat;
    }
}
