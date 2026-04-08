<?php

namespace App\Support;

final class Validator
{
    private array $errors = [];

    public function __construct(private readonly array $data) {}

    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field]) || trim((string) $this->data[$field]) === '') {
                $this->errors[$field] = "$field ist erforderlich";
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

    public function fails(): bool
    {
        return count($this->errors) > 0;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): array
    {
        if ($this->fails()) {
            Response::json(['message' => 'Validierungsfehler', 'errors' => $this->errors], 422);
        }
        return $this->data;
    }
}
