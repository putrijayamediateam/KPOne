<?php

namespace App\Domain\Agents\Chat;

/**
 * Builds the "Figures" block shown under an answer from the tool result itself (docs/agents/AI-CHATBOX-DESIGN.md section 8), so the
 * numbers on screen come from KPOne and never from the model's wording. Amounts are exact: a value ending in "Sen" is shown as
 * ringgit with two decimals, computed with integers only.
 */
final class ChatFigures
{
    public const MAX = 48;

    /** Report page that holds each question's figures (the person may open it: it is behind the Insights permission). */
    private const REPORTS = [
        'clinic_day_summary' => '/insights/in-clinic',
        'visits_by_state' => '/insights/in-clinic',
        'queue_now' => '/insights/in-clinic',
        'money_day_summary' => '/insights/payments',
        'sales_summary' => '/insights/sales',
        'panel_claims_outstanding' => '/reports/panel-summary',
        'panel_claims_made' => '/reports/panel-summary',
        'stock_alerts' => '/insights/inventory',
        'stock_balances_and_counts' => '/insights/inventory',
    ];

    /** @return array{url: string, label: string}|null */
    public static function report(string $tool): ?array
    {
        return isset(self::REPORTS[$tool]) ? ['url' => self::REPORTS[$tool], 'label' => 'Open the report'] : null;
    }

    /**
     * @param  array<string, mixed>  $result  a connector answer
     * @return list<array{tool: string, group: string|null, label: string, text: string, asOf: string|null}>
     */
    public static function from(string $tool, array $result): array
    {
        $asOf = is_string($result['asOf'] ?? null) ? $result['asOf'] : null;
        $figures = [];
        $entries = is_array($result['branches'] ?? null) && array_is_list($result['branches']) ? $result['branches'] : [$result];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $group = is_string($entry['branchName'] ?? null) ? $entry['branchName'] : (is_string($entry['branch'] ?? null) ? $entry['branch'] : null);
            self::collect($figures, $tool, $group, $asOf, $entry, '');
        }

        return array_slice($figures, 0, self::MAX);
    }

    /**
     * @param  list<array{tool: string, group: string|null, label: string, text: string, asOf: string|null}>  $figures
     * @param  array<string, mixed>  $values
     */
    private static function collect(array &$figures, string $tool, ?string $group, ?string $asOf, array $values, string $prefix): void
    {
        foreach ($values as $key => $value) {
            if (count($figures) >= self::MAX || in_array($key, ['branch', 'branchName', 'date', 'from', 'to', 'previousFrom', 'previousTo', 'limits', 'asOf', 'definitions', 'scope'], true)) {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $figures[] = ['tool' => $tool, 'group' => $group, 'label' => $prefix.self::label($key), 'text' => self::format($key, $value), 'asOf' => $asOf];
            } elseif (is_array($value) && ! array_is_list($value) && $prefix === '') {
                self::collect($figures, $tool, $group, $asOf, $value, self::label($key).' · ');
            }
        }
    }

    private static function label(string $key): string
    {
        $name = preg_replace(['/Sen$/', '/([a-z0-9])([A-Z])/'], ['', '$1 $2'], $key);

        return ucfirst(mb_strtolower(trim(str_replace('_', ' ', (string) $name))));
    }

    private static function format(string $key, int|float $value): string
    {
        if (str_ends_with($key, 'Sen') && is_int($value)) {
            $negative = $value < 0;
            $abs = abs($value);

            return ($negative ? '-' : '').'RM '.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        }
        if (str_ends_with($key, 'Minutes')) {
            return number_format((float) $value, 0).' min';
        }

        return is_int($value) ? number_format($value) : rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
