<?php

namespace App\Domain\Clinical\Dispensary\Labels;

/** Print-only budgets; never alter clinical storage validation or truncate content. */
final class DrugLabelLayout
{
    public const DRUG_LABEL_PAGE_SIZE = ['width' => 100, 'height' => 150];

    public const COLUMNS = 42;

    public const MAX_LINES = 35;

    public const MAX_LINES_WITH_LOGO = 33;

    public const FIELD_BUDGETS = [
        'clinic' => 64, 'address' => 128, 'contact' => 64, 'patient' => 96, 'reference' => 64,
        'name' => 96, 'strength' => 32, 'form' => 24, 'quantity' => 48,
        'dosage' => 96, 'frequency' => 96, 'duration' => 48, 'route' => 48,
        'instruction' => 384, 'precaution' => 192,
    ];

    /** @param array<string, string|null> $fields
     * @return array{lines: list<string>, emphasis: list<int>, errors: list<string>}
     */
    public function render(array $fields, string $date, bool $completed, bool $logo, string $generatedAt = ''): array
    {
        $errors = [];
        foreach ($fields as $name => $text) {
            $length = mb_strlen($text ?? '', 'UTF-8');
            $limit = self::FIELD_BUDGETS[$name];
            if ($length > $limit) {
                $errors[] = ucfirst($name).": {$length} characters; label limit {$limit}.";
            }
            // Refuse invisible/control/bidi overrides rather than changing saved clinical text.
            if (preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text ?? '')) {
                $errors[] = ucfirst($name).': unsupported control characters; label limit 0.';
            }
        }
        foreach (['dosage', 'frequency'] as $name) {
            if (trim($fields[$name] ?? '') === '') {
                $errors[] = ucfirst($name).': 0 characters; minimum 1.';
            }
        }
        $lines = [];
        $emphasis = [];
        $prefixes = ['quantity' => 'Kuantiti: ', 'dosage' => 'Dos: ', 'frequency' => 'Kekerapan: ', 'duration' => 'Tempoh: ', 'route' => 'Laluan: ', 'instruction' => 'Arahan: ', 'precaution' => 'Peringatan: '];
        foreach ($fields as $name => $text) {
            if ($text !== null && $text !== '') {
                if (in_array($name, ['patient', 'name'], true)) {
                    array_push($emphasis, ...range(count($lines), count($lines) + count($this->wrap($text)) - 1));
                }
                array_push($lines, ...$this->wrap(($prefixes[$name] ?? '').$text));
            }
        }
        array_push($lines, ...$this->wrap(($completed ? 'Dibekalkan: ' : 'Disediakan: ').$date));
        if ($generatedAt !== '') {
            array_push($lines, ...$this->wrap('Dijana: '.$generatedAt));
        }
        array_push($lines, ...$this->wrap('Rekod semasa; cetakan semula mungkin berbeza.'));
        $limit = $logo ? self::MAX_LINES_WITH_LOGO : self::MAX_LINES;
        if (count($lines) > $limit) {
            $errors[] = 'Label lines: '.count($lines)." lines; label limit {$limit}.";
        }

        return ['lines' => $errors === [] ? $lines : [], 'emphasis' => $errors === [] ? $emphasis : [], 'errors' => $errors];
    }

    /** Decimal display only: never round or convert through binary floating point. */
    public static function displayQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /** Deterministic server wrapping, including explicit newlines and wide Unicode characters.
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        $result = [];
        foreach (explode("\n", $text) as $paragraph) {
            preg_match_all('/\X/u', $paragraph, $matches);
            $line = '';
            $width = 0;
            foreach ($matches[0] as $character) {
                $cells = max(1, mb_strwidth($character, 'UTF-8'));
                if ($width + $cells > self::COLUMNS) {
                    // Prefer a word boundary; retain every character, including spaces.
                    $space = mb_strrpos($line, ' ', 0, 'UTF-8');
                    $cut = $space !== false && $space > 0 ? $space + 1 : mb_strlen($line, 'UTF-8');
                    $result[] = mb_substr($line, 0, $cut, 'UTF-8');
                    $line = mb_substr($line, $cut, null, 'UTF-8');
                    $width = mb_strwidth($line, 'UTF-8');
                }
                $line .= $character;
                $width += $cells;
            }
            $result[] = $line;
        }

        return $result;
    }
}
