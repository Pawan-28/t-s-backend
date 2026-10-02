<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Tiny field validator that reproduces DRF's field-level behaviour and message
 * texts ("This field is required.", "Ensure this field has no more than N
 * characters." ...) so error bodies stay {"field": ["message"]} exactly like
 * Django. Errors are collected across fields (DRF style) and thrown together.
 */
class DrfFields
{
    private array $errors = [];

    private array $validated = [];

    public function __construct(private array $data, private bool $partial = false) {}

    public function present(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    /** Record an already-shaped (possibly nested, DRF list-serializer style) error value. */
    public function nestedError(string $key, array $value): void
    {
        $this->errors[$key] = $value;
    }

    public function failed(?string $key = null): bool
    {
        return $key === null ? $this->errors !== [] : isset($this->errors[$key]);
    }

    public function validated(): array
    {
        return $this->validated;
    }

    public function set(string $key, mixed $value): void
    {
        $this->validated[$key] = $value;
    }

    /** Throws DRF-shaped 400 when any error was recorded. */
    public function throwIfFailed(): void
    {
        if ($this->errors !== []) {
            throw new HttpResponseException(response()->json($this->errors, 400));
        }
    }

    private function missing(string $key, bool $required): bool
    {
        if ($this->present($key)) {
            return false;
        }
        if ($required && ! $this->partial) {
            $this->error($key, 'This field is required.');
        }

        return true;
    }

    /** Text field. $blank=false => "may not be blank". Empty/null input is treated as ''. */
    public function string(string $key, int $max, bool $required = false, bool $blank = true): ?string
    {
        if ($this->missing($key, $required)) {
            return null;
        }
        $v = $this->data[$key];
        if ($v === null) {
            $v = '';
        }
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (! is_string($v)) {
            $this->error($key, 'Not a valid string.');

            return null;
        }
        $v = trim($v);
        if ($v === '') {
            if (! $blank) {
                $this->error($key, 'This field may not be blank.');

                return null;
            }

            return $this->validated[$key] = '';
        }
        if (mb_strlen($v) > $max) {
            $this->error($key, "Ensure this field has no more than {$max} characters.");

            return null;
        }

        return $this->validated[$key] = $v;
    }

    /** DRF SlugField (blank allowed => '' means "auto-generate"). */
    public function slug(string $key, int $max): ?string
    {
        $v = $this->string($key, $max, required: false, blank: true);
        if ($v === null) {
            return null;
        }
        if ($v !== '' && ! preg_match('/^[-a-zA-Z0-9_]+$/', $v)) {
            $this->error($key, 'Enter a valid "slug" consisting of letters, numbers, underscores or hyphens.');
            unset($this->validated[$key]);

            return null;
        }

        return $v;
    }

    public function bool(string $key): ?bool
    {
        if (! $this->present($key)) {
            return null;
        }
        $raw = $this->data[$key];
        $v = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($v === null) {
            $this->error($key, '"'.(is_scalar($raw) ? $raw : gettype($raw)).'" is not a valid boolean.');

            return null;
        }

        return $this->validated[$key] = $v;
    }

    public function positiveInt(string $key): ?int
    {
        if (! $this->present($key)) {
            return null;
        }
        $raw = $this->data[$key];
        if (is_bool($raw) || ! (is_int($raw) || (is_string($raw) && preg_match('/^-?\d+$/', trim($raw))))) {
            $this->error($key, 'A valid integer is required.');

            return null;
        }
        $n = (int) $raw;
        if ($n < 0) {
            $this->error($key, 'Ensure this value is greater than or equal to 0.');

            return null;
        }
        if ($n > 2147483647) {
            $this->error($key, 'Ensure this value is less than or equal to 2147483647.');

            return null;
        }

        return $this->validated[$key] = $n;
    }

    /** Value must be one of $choices. */
    public function choice(string $key, array $choices): ?string
    {
        if (! $this->present($key)) {
            return null;
        }
        $raw = $this->data[$key];
        if (! is_string($raw) || ! in_array($raw, $choices, true)) {
            $this->error($key, '"'.(is_scalar($raw) ? $raw : gettype($raw)).'" is not a valid choice.');

            return null;
        }

        return $this->validated[$key] = $raw;
    }
}
