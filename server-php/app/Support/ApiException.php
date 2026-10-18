<?php
declare(strict_types=1);

namespace App\Support;

/**
 * An error whose message is written for the API caller (validation, conflict,
 * forbidden, not configured). The front controller shows its message in every
 * environment — unlike an unexpected exception, which production hides.
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string,mixed> $errors */
    public function __construct(string $message, int $status = 422, private array $errors = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /** @return array<string,mixed> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param array<string,mixed> $errors */
    public static function validation(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 404);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message, 503);
    }
}
