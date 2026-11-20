<?php

namespace App\Domain\Clinical\Triage;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A short list of guideline codes: a PostgreSQL text[] (the database checks it) or JSON text on the SQLite test
 * connection. Codes match ^[a-z][a-z0-9_]{1,39}$, so the array literal needs no quoting.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class CodeArrayCast implements CastsAttributes
{
    public const PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}' || $value === '[]') {
            return [];
        }
        if (is_string($value) && str_starts_with($value, '{')) {
            return array_values(array_filter(explode(',', trim($value, '{}')), fn (string $code): bool => $code !== ''));
        }
        $decoded = is_string($value) ? json_decode($value, true, 4, JSON_THROW_ON_ERROR) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::literal($model->getConnection()->getDriverName(), is_array($value) ? $value : []);
    }

    /** @param array<int, mixed> $codes */
    public static function literal(string $driver, array $codes): string
    {
        $codes = array_values($codes);
        foreach ($codes as $code) {
            if (! is_string($code) || preg_match(self::PATTERN, $code) !== 1) {
                throw new InvalidArgumentException('Invalid triage code.');
            }
        }

        return $driver === 'pgsql' ? '{'.implode(',', $codes).'}' : json_encode($codes, JSON_THROW_ON_ERROR);
    }
}
