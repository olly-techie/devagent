<?php
declare(strict_types=1);

namespace DevAgent\Security;

/**
 * Chainable input validator.
 *
 * Usage:
 *   $v = Validator::make($body)
 *       ->required('owner')
 *       ->string('owner', 1, 100)
 *       ->matches('owner', '/^[a-zA-Z0-9\-]+$/', 'Invalid GitHub username')
 *       ->required('task')
 *       ->string('task', 10, 4000);
 *
 *   if ($v->fails()) jsonError($v->firstError());
 */
final class Validator
{
    private array $data;
    private array $errors = [];

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    // ── Rules ──────────────────────────────────────────────

    /**
     * Field must be present and non-empty string.
     */
    public function required(string $field): self
    {
        $val = $this->data[$field] ?? null;
        if ($val === null || (is_string($val) && trim($val) === '') || $val === '') {
            $this->errors[$field][] = "{$field} is required";
        }
        return $this;
    }

    /**
     * Field must be a string within min/max byte length.
     */
    public function string(string $field, int $min = 1, int $max = 255): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        if ($val === null) return $this;

        if (!is_string($val)) {
            $this->errors[$field][] = "{$field} must be a string";
            return $this;
        }

        $len = mb_strlen(trim($val), 'UTF-8');
        if ($len < $min) {
            $this->errors[$field][] = "{$field} must be at least {$min} character" . ($min > 1 ? 's' : '');
        }
        if ($len > $max) {
            $this->errors[$field][] = "{$field} must be no more than {$max} characters";
        }
        return $this;
    }

    /**
     * Field value must match a regex pattern.
     */
    public function matches(string $field, string $pattern, string $message = ''): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        if ($val === null || !is_string($val)) return $this;

        if (!preg_match($pattern, $val)) {
            $this->errors[$field][] = $message ?: "{$field} has an invalid format";
        }
        return $this;
    }

    /**
     * Field value must NOT match a regex (block dangerous patterns).
     */
    public function notMatches(string $field, string $pattern, string $message = ''): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        if ($val === null || !is_string($val)) return $this;

        if (preg_match($pattern, $val)) {
            $this->errors[$field][] = $message ?: "{$field} contains invalid characters";
        }
        return $this;
    }

    /**
     * Field must be a positive integer.
     */
    public function positiveInt(string $field): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        if ($val === null) return $this;

        if (!is_int($val) && !ctype_digit((string) $val)) {
            $this->errors[$field][] = "{$field} must be a positive integer";
            return $this;
        }
        if ((int) $val <= 0) {
            $this->errors[$field][] = "{$field} must be greater than 0";
        }
        return $this;
    }

    /**
     * Field must be one of a set of allowed values.
     */
    public function in(string $field, array $allowed): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        if ($val === null) return $this;

        if (!in_array($val, $allowed, strict: true)) {
            $list = implode(', ', $allowed);
            $this->errors[$field][] = "{$field} must be one of: {$list}";
        }
        return $this;
    }

    /**
     * Apply a custom closure rule: fn($value): ?string (return error message or null).
     */
    public function custom(string $field, \Closure $fn): self
    {
        if (isset($this->errors[$field])) return $this;
        $val = $this->data[$field] ?? null;
        $err = $fn($val);
        if ($err !== null) {
            $this->errors[$field][] = $err;
        }
        return $this;
    }

    // ── Results ────────────────────────────────────────────

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    /** All errors, keyed by field. */
    public function errors(): array
    {
        return $this->errors;
    }

    /** First error message across all fields. */
    public function firstError(): string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? 'Validation failed';
        }
        return 'Validation failed';
    }

    /**
     * Get a validated, trimmed string value.
     * Returns null if missing or not a string.
     */
    public function get(string $field): ?string
    {
        $val = $this->data[$field] ?? null;
        return is_string($val) ? trim($val) : null;
    }

    /**
     * Abort with 422 Unprocessable Entity if validation failed.
     */
    public function abortIfFails(): void
    {
        if ($this->fails()) {
            jsonResponse(['error' => $this->firstError(), 'details' => $this->errors()], 422);
        }
    }
}
