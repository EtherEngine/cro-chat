<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;

final class Validator
{
    private array $errors = [];

    public function __construct(private readonly array $data)
    {
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field]) || trim((string) $this->data[$field]) === '') {
                $this->errors[$field] = "$field ist erforderlich";
            }
        }
        return $this;
    }

    /**
     * Ensure fields are scalar strings (reject arrays / objects from JSON input).
     */
    public function string(string ...$fields): self
    {
        foreach ($fields as $field) {
            $val = $this->data[$field] ?? null;
            if ($val !== null && !is_string($val)) {
                $this->errors[$field] = "$field muss ein String sein";
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        $val = $this->data[$field] ?? '';
        if ($val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "Ungültige E-Mail-Adresse";
        }
        return $this;
    }

    public function minLength(string $field, int $min): self
    {
        $val = $this->data[$field] ?? '';
        if (mb_strlen((string) $val) < $min) {
            $this->errors[$field] = "$field muss mindestens $min Zeichen lang sein";
        }
        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        $val = $this->data[$field] ?? '';
        if (mb_strlen((string) $val) > $max) {
            $this->errors[$field] = "$field darf maximal $max Zeichen lang sein";
        }
        return $this;
    }

    public function integer(string $field): self
    {
        $val = $this->data[$field] ?? null;
        if ($val !== null && $val !== '' && !is_numeric($val)) {
            $this->errors[$field] = "$field muss eine Zahl sein";
        }
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        $val = $this->data[$field] ?? null;
        if ($val !== null && $val !== '' && !in_array($val, $allowed, true)) {
            $this->errors[$field] = "$field muss einer der Werte sein: " . implode(', ', $allowed);
        }
        return $this;
    }

    public function boolean(string $field): self
    {
        $val = $this->data[$field] ?? null;
        if ($val !== null && !is_bool($val) && !in_array($val, [0, 1, '0', '1'], true)) {
            $this->errors[$field] = "$field muss ein Boolean sein";
        }
        return $this;
    }

    public function fails(): bool
    {
        return count($this->errors) > 0;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * If validation failed, throw ApiException with 422 + field errors.
     * Returns the validated data on success.
     *
     * @throws ApiException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw ApiException::validation('Validierungsfehler', $this->errors);
        }
        return $this->data;
    }
}
