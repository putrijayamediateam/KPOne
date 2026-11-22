<?php

namespace App\Domain\Agents\Connector\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Small helpers shared by the connector queries. */
trait ShapesAnswers
{
    public const MAX_ROWS = 25;

    public const MAX_DAYS = 31;

    /** Staff-typed reference text (a Panel or medicine name): no control characters, at most 80 characters. */
    protected function label(mixed $text): string
    {
        return mb_substr(trim((string) preg_replace('/[[:cntrl:]]+/u', ' ', (string) $text)), 0, 80);
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array{shown: int, total: int}}
     */
    protected function cap(array $rows): array
    {
        $rows = array_values($rows);

        return [array_slice($rows, 0, self::MAX_ROWS), ['shown' => min(count($rows), self::MAX_ROWS), 'total' => count($rows)]];
    }

    /** @return array<string, list<string>> date rules; the 31-day limit is checked by assertRange() */
    protected function rangeRules(): array
    {
        return ['from' => ['required_with:to', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']];
    }

    /** @param array<string, mixed> $input */
    public function check(array $input): void
    {
        $this->assertRange($input);
    }

    /** @param array<string, mixed> $input */
    protected function assertRange(array $input): void
    {
        if (isset($input['from'], $input['to']) && CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }
    }
}
