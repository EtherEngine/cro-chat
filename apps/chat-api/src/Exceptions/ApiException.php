<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Application-level exception that maps to an HTTP error response.
 *
 * Throw this anywhere in Service / Repository / Controller code.
 * The global ErrorHandler catches it and returns a structured JSON response.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        public readonly string $errorCode = 'BAD_REQUEST',
        public readonly ?array $errors = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    // ── Named constructors for common cases ──

    public static function notFound(string $message = 'Nicht gefunden', string $code = 'NOT_FOUND'): static
    {
        return new static($message, 404, $code);
    }

    public static function forbidden(string $message = 'Keine Berechtigung', string $code = 'FORBIDDEN'): static
    {
        return new static($message, 403, $code);
    }

    public static function unauthorized(string $message = 'Nicht eingeloggt', string $code = 'UNAUTHORIZED'): static
    {
        return new static($message, 401, $code);
    }

    public static function validation(string $message = 'Validierungsfehler', array|string $errorsOrCode = [], ?array $errors = null): static
    {
        if (is_string($errorsOrCode)) {
            return new static($message, 422, $errorsOrCode, $errors);
        }
        return new static($message, 422, 'VALIDATION_ERROR', $errorsOrCode ?: $errors);
    }

    public static function conflict(string $message = 'Konflikt', string $code = 'CONFLICT', ?array $errors = null): static
    {
        return new static($message, 409, $code, $errors);
    }

    public static function internal(string $message = 'Interner Fehler', string $code = 'INTERNAL_ERROR'): static
    {
        return new static($message, 500, $code);
    }
}
