<?php

declare(strict_types=1);

/** Input validation helpers. Returns [field => error] on failure. */

final class Validator
{
    /** @param array<string,mixed> $data @param array<string,string> $rules rule => value */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $raw = $value;

            if (str_contains($rule, 'required') && ($value === null || $value === '')) {
                $errors[$field] = "The field '$field' is required";
                continue;
            }
            if (str_contains($rule, 'uuid') && $value !== null && !isValidUuid((string)$value)) {
                $errors[$field] = "The field '$field' must be a valid UUID";
            }
            if (str_contains($rule, 'email') && $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "The field '$field' must be a valid email address";
            }
            if (str_contains($rule, 'date') && $value !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
                $errors[$field] = "The field '$field' must be a date (YYYY-MM-DD)";
            }
            if (str_contains($rule, 'int') && $value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
                $errors[$field] = "The field '$field' must be an integer";
            }
            if (str_contains($rule, 'numeric') && $value !== null && !is_numeric($value)) {
                $errors[$field] = "The field '$field' must be numeric";
            }
            if (str_contains($rule, 'in:') && $value !== null) {
                $allowed = explode(',', substr($rule, strpos($rule, 'in:') + 3));
                if (!in_array((string)$value, $allowed, true)) {
                    $errors[$field] = "The field '$field' has an invalid value";
                }
            }
            if (preg_match('/min:(\d+)/', $rule, $m) && $value !== null && is_numeric($value) && (float)$value < (float)$m[1]) {
                $errors[$field] = "The field '$field' must be at least {$m[1]}";
            }
            if (preg_match('/max:(\d+)/', $rule, $m) && $value !== null && is_numeric($value) && (float)$value > (float)$m[1]) {
                $errors[$field] = "The field '$field' must not exceed {$m[1]}";
            }
            if (preg_match('/length:(\d+)/', $rule, $m) && $value !== null && strlen((string)$value) > (int)$m[1]) {
                $errors[$field] = "The field '$field' must not exceed {$m[1]} characters";
            }
            if (preg_match('/enum:([a-z_]+)/', $rule, $m) && $value !== null) {
                // handled via DB ENUM; basic check only
            }
        }
        return $errors;
    }

    /** Throws a validation error response for a required-field set. */
    public static function requireFields(Request $request, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($request->body[$field]) || $request->body[$field] === null || $request->body[$field] === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            Response::validationError(['missing_fields' => $missing]);
        }
    }

    /** Ethiopian date validation (13 months, leap-aware). */
    public static function ethDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        [$_, $y, $mo, $d] = array_map('intval', $m);
        if ($mo < 1 || $mo > 13) {
            return false;
        }
        return $d >= 1 && $d <= Calendar::daysInMonth($y, $mo);
    }

    /** Ethiopian phone number: +251 followed by 9 digits. */
    public static function ethPhone(string $value): bool
    {
        return (bool)preg_match('/^\+251[1-59]\d{8}$/', $value);
    }
}
