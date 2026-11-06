<?php

namespace App\Domain\Clinical\Dispensary\Labels;

/**
 * Print-only layout of the drug label; never alters clinical storage validation or truncates content.
 *
 * DSP-10 (owner decisions 2026-11-06): the clinic's landscape label, 100 × 62 mm provisional until the real printer
 * and sticker test. Monospace rows of 48 cells, at most 13 rows inside the border; the controlled-medicine line sits
 * in a strip below the border. Every row is a fixed-width string, so the server alone decides what fits: a field or
 * block over its limit blocks the label, it is never cut, shrunk or split.
 */
final class DrugLabelLayout
{
    public const DRUG_LABEL_PAGE_SIZE = ['width' => 100, 'height' => 62];

    public const COLUMNS = 48;

    public const MAX_ROWS = 13;

    /** The medicine name wraps in its own column; QTY sits in the last cells of its first row. */
    public const NAME_COLUMNS = 36;

    public const QTY_CELLS = 11;

    /** "Precaution: " and the other labels take this many cells; their values wrap with a hanging indent. */
    public const LABEL_CELLS = 12;

    /** The DSP-6 REPRINT mark sits in the last cells of the first row; the clinic name stays clear of them. */
    public const REPRINT_CELLS = 8;

    /** DSP-3, DSP-10: printed below the border for a catalogue item marked controlled (the owner's sample wording). */
    public const CONTROLLED_MARKER = 'Ubat Terkawal/Controlled Medicine';

    /** Character limits per saved field (checked before layout). */
    public const FIELD_BUDGETS = [
        'clinic' => 40, 'address' => 96, 'phone' => 41, 'patient' => 96, 'name' => 72, 'quantity' => 6,
        'dosage' => 96, 'frequency' => 96, 'instruction' => 144,
        'precaution' => 72, 'duration' => 36, 'indication' => 72,
    ];

    /** Row limits per printed block. */
    public const ROW_LIMITS = [
        'clinic' => 1, 'address' => 2, 'phone' => 1, 'patient' => 2, 'name' => 2, 'directions' => 3,
        'precaution' => 2, 'duration' => 1, 'indication' => 2,
    ];

    private const LABELS = ['precaution' => 'Precaution:', 'duration' => 'Duration:', 'indication' => 'Indication:'];

    /**
     * @param  array<string, string|null>  $fields  clinic, address, phone, patient, name, quantity, dosage, frequency,
     *                                              instruction, precaution, duration, indication
     * @param  string|null  $expiry  dd/mm/yyyy when the line used exactly one batch with a real expiry, otherwise null
     * @return array{lines: list<string>, emphasis: list<int>, ruleAfter: int, controlled: ?string, errors: list<string>}
     */
    public function render(array $fields, string $date, ?string $expiry, bool $controlled): array
    {
        $errors = [];
        foreach ($fields as $name => $text) {
            $length = mb_strlen($text ?? '', 'UTF-8');
            $limit = self::FIELD_BUDGETS[$name];
            if ($length > $limit) {
                $errors[] = $this->title($name).": {$length} characters; label limit {$limit}.";
            }
            // Refuse invisible/control/bidi overrides rather than changing saved clinical text.
            if (preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $text ?? '')) {
                $errors[] = $this->title($name).': unsupported control characters; label limit 0.';
            }
        }
        foreach (['dosage', 'frequency'] as $name) {
            if (trim($fields[$name] ?? '') === '') {
                $errors[] = $this->title($name).': 0 characters; minimum 1.';
            }
        }

        $lines = [];
        $emphasis = [];
        $add = function (array $rows, bool $bold) use (&$lines, &$emphasis): void {
            foreach ($rows as $row) {
                if ($bold) {
                    $emphasis[] = count($lines);
                }
                $lines[] = $row;
            }
        };
        $block = function (string $name, array $rows, string $text) use (&$errors): array {
            if (count($rows) > self::ROW_LIMITS[$name]) {
                $errors[] = $this->title($name).': '.count($rows).' rows ('.mb_strlen($text, 'UTF-8').' characters); label limit '.self::ROW_LIMITS[$name].' '.(self::ROW_LIMITS[$name] === 1 ? 'row' : 'rows').'.';
            }

            return $rows;
        };

        // Header: clinic name (capitals, clear of the REPRINT cells), address, phone; a rule follows it.
        $clinic = mb_strtoupper($this->clean($fields['clinic'] ?? ''), 'UTF-8');
        $add($block('clinic', $this->wrap($clinic, self::COLUMNS - self::REPRINT_CELLS), $clinic), true);
        $address = $this->clean($fields['address'] ?? '');
        if ($address !== '') {
            $add($block('address', $this->wrap($address, self::COLUMNS), $address), false);
        }
        $phone = $this->clean($fields['phone'] ?? '');
        if ($phone !== '') {
            $tel = 'Tel: ('.$phone.')';
            $add($block('phone', $this->wrap($tel, self::COLUMNS), $tel), true);
        }
        $ruleAfter = count($lines);

        $patient = $this->clean($fields['patient'] ?? '');
        $add($block('patient', $this->wrap($patient, self::COLUMNS), $patient), true);

        // Medicine row: the name wraps in its column; "QTY: n" is right-aligned on the first row and never moves.
        $name = $this->clean($fields['name'] ?? '');
        $nameRows = $block('name', $this->wrap($name, self::NAME_COLUMNS), $name);
        $qty = 'QTY: '.$this->clean($fields['quantity'] ?? '');
        $nameRows[0] = $this->pad($nameRows[0], self::COLUMNS - self::QTY_CELLS).$this->padLeft($qty, self::QTY_CELLS);
        $add($nameRows, true);

        // Directions: "dosage, frequency, instruction" in capitals (print only; the saved text is unchanged).
        $directions = mb_strtoupper(implode(', ', array_filter(array_map(fn (string $f): string => $this->clean($fields[$f] ?? ''), ['dosage', 'frequency', 'instruction']), fn (string $t): bool => $t !== '')), 'UTF-8');
        $add($block('directions', $this->wrap($directions, self::COLUMNS), $directions), false);

        // Labelled rows, values in capitals and aligned in one column; an empty value leaves its row out.
        foreach (self::LABELS as $field => $label) {
            $value = mb_strtoupper($this->clean($fields[$field] ?? ''), 'UTF-8');
            if ($value === '') {
                continue;
            }
            $rows = $block($field, $this->wrap($value, self::COLUMNS - self::LABEL_CELLS), $value);
            $add(array_map(fn (string $row, int $i): string => $this->pad($i === 0 ? $label : '', self::LABEL_CELLS).$row, $rows, array_keys($rows)), false);
        }

        // Bottom row: dispensing date on the left, expiry on the right ("EXP: -" when not exactly one batch).
        $exp = 'EXP: '.($expiry ?? '-');
        $add([$this->pad($date, self::COLUMNS - mb_strwidth($exp, 'UTF-8')).$exp], false);

        if (count($lines) > self::MAX_ROWS) {
            $errors[] = 'Label rows: '.count($lines).' rows; label limit '.self::MAX_ROWS.'.';
        }
        foreach ($lines as $line) {
            // A last safety net: a fixed-width row never runs past the border.
            if (mb_strwidth($line, 'UTF-8') > self::COLUMNS) {
                $errors[] = 'Label rows: a row is '.mb_strwidth($line, 'UTF-8').' cells wide; label limit '.self::COLUMNS.'.';
                break;
            }
        }
        $errors = array_values(array_unique($errors));

        return $errors === []
            ? ['lines' => $lines, 'emphasis' => $emphasis, 'ruleAfter' => $ruleAfter, 'controlled' => $controlled ? self::CONTROLLED_MARKER : null, 'errors' => []]
            : ['lines' => [], 'emphasis' => [], 'ruleAfter' => 0, 'controlled' => null, 'errors' => $errors];
    }

    /** Decimal display only: never round or convert through binary floating point. */
    public static function displayQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    private function title(string $name): string
    {
        return ['phone' => 'Phone', 'name' => 'Medicine name', 'clinic' => 'Clinic name'][$name] ?? ucfirst($name);
    }

    /** One printed line per block: newlines and runs of spaces in saved text become single spaces. */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function pad(string $text, int $cells): string
    {
        return $text.str_repeat(' ', max(0, $cells - mb_strwidth($text, 'UTF-8')));
    }

    private function padLeft(string $text, int $cells): string
    {
        return str_repeat(' ', max(0, $cells - mb_strwidth($text, 'UTF-8'))).$text;
    }

    /**
     * Deterministic server wrapping at word boundaries, counting wide Unicode characters as two cells.
     * A word longer than the row is split; no character is ever dropped.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $columns): array
    {
        if ($text === '') {
            return [''];
        }
        preg_match_all('/\X/u', $text, $matches);
        $result = [];
        $line = '';
        $width = 0;
        foreach ($matches[0] as $character) {
            $cells = max(1, mb_strwidth($character, 'UTF-8'));
            if ($width + $cells > $columns) {
                $space = mb_strrpos($line, ' ', 0, 'UTF-8');
                $cut = $space !== false && $space > 0 ? $space : mb_strlen($line, 'UTF-8');
                $result[] = rtrim(mb_substr($line, 0, $cut, 'UTF-8'));
                $line = ltrim(mb_substr($line, $cut, null, 'UTF-8'));
                $width = mb_strwidth($line, 'UTF-8');
                if ($character === ' ' && $line === '') {
                    continue;
                }
            }
            $line .= $character;
            $width += $cells;
        }
        $result[] = $line;

        return $result;
    }
}
