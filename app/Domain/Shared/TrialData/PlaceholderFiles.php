<?php

namespace App\Domain\Shared\TrialData;

use Illuminate\Http\UploadedFile;

/**
 * TRIAL-DATA: plain placeholder images (PNG) and one-page PDFs, each marked "FICTIONAL TRIAL DATA" (owner decision,
 * 21 Nov 2026), for menus that only fill with an uploaded file. Written in plain PHP with a small built-in pixel font,
 * so making them needs no image extension; the services that receive them still check and store them as usual.
 * Temporary files are deleted by cleanUp() at the end of a run.
 */
final class PlaceholderFiles
{
    public const MARK = 'FICTIONAL TRIAL DATA';

    /** 5 x 7 pixel glyphs, one string of five 0/1 cells per row. */
    private const FONT = [
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01110', '10001', '10000', '10000', '10000', '10001', '01110'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01110', '10001', '10000', '10111', '10001', '10001', '01111'],
        'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'I' => ['01110', '00100', '00100', '00100', '00100', '00100', '01110'],
        'J' => ['00111', '00010', '00010', '00010', '00010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '10001', '11001', '10101', '10011', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
        'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'],
        'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
        'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
        '0' => ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
        '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
        '6' => ['00110', '01000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00010', '01100'],
        '-' => ['00000', '00000', '00000', '11111', '00000', '00000', '00000'],
        '.' => ['00000', '00000', '00000', '00000', '00000', '01100', '01100'],
        ':' => ['00000', '01100', '01100', '00000', '01100', '01100', '00000'],
        '/' => ['00001', '00001', '00010', '00100', '01000', '10000', '10000'],
        '(' => ['00010', '00100', '01000', '01000', '01000', '00100', '00010'],
        ')' => ['01000', '00100', '00010', '00010', '00010', '00100', '01000'],
        ' ' => ['00000', '00000', '00000', '00000', '00000', '00000', '00000'],
    ];

    /** @var list<string> */
    private array $temporary = [];

    /**
     * A PNG of the given size: a soft-pink card with a dark border, the mark and a caption.
     */
    public function image(string $caption, int $width = 960, int $height = 540): UploadedFile
    {
        $width = max(120, min($width, 2400));
        $height = max(80, min($height, 2400));
        $canvas = array_fill(0, $height, str_repeat("\xFD\xE8\xEF", $width));
        $border = max(4, intdiv(min($width, $height), 60));
        $this->fill($canvas, 0, 0, $width, $border, "\x33\x33\x33");
        $this->fill($canvas, 0, $height - $border, $width, $border, "\x33\x33\x33");
        $this->fill($canvas, 0, 0, $border, $height, "\x33\x33\x33");
        $this->fill($canvas, $width - $border, 0, $border, $height, "\x33\x33\x33");

        $lines = [[self::MARK, "\xB0\x1E\x4E"], [$this->fitText($caption, 40), "\x22\x22\x22"]];
        $scale = max(1, intdiv($width - 4 * $border, 6 * max(array_map(fn (array $l): int => strlen($l[0]), $lines))));
        $scale = min($scale, intdiv($height - 4 * $border, 2 * 12));
        $top = intdiv($height - (12 + 7) * $scale, 2);
        foreach ($lines as $index => [$text, $colour]) {
            $left = intdiv($width - strlen($text) * 6 * $scale, 2);
            $this->text($canvas, $text, $left, $top + $index * 12 * $scale, $scale, $colour);
        }

        return $this->keep($this->png($canvas, $width, $height), $this->fileName($caption, 'png'), 'image/png');
    }

    /**
     * A one-page A4 PDF with the mark, a title and an optional line of detail.
     */
    public function pdf(string $title, string $detail = ''): UploadedFile
    {
        $lines = [[self::MARK, 28, 760], [$this->fitText($title, 60), 16, 720]];
        if ($detail !== '') {
            $lines[] = [$this->fitText($detail, 80), 11, 696];
        }
        $lines[] = ['Not a real document. Generated for a trial server only.', 10, 120];
        $stream = '';
        foreach ($lines as [$text, $size, $y]) {
            $stream .= sprintf("BT /F1 %d Tf 56 %d Td (%s) Tj ET\n", $size, $y, $this->pdfEscape($text));
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $this->keep($pdf, $this->fileName($title, 'pdf'), 'application/pdf');
    }

    public function cleanUp(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporary = [];
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private function keep(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'kpone-trial-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not write a temporary placeholder file.');
        }
        $this->temporary[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function fileName(string $caption, string $extension): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($caption)), '-');

        return 'fictional-trial-'.($slug === '' ? 'file' : substr($slug, 0, 40)).'.'.$extension;
    }

    private function fitText(string $text, int $max): string
    {
        $text = strtoupper((string) preg_replace('/[^A-Za-z0-9 .:\/()\-]+/', ' ', $text));
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return strlen($text) > $max ? substr($text, 0, $max) : $text;
    }

    /** @param array<int, string> $canvas */
    private function fill(array &$canvas, int $x, int $y, int $w, int $h, string $rgb): void
    {
        $width = intdiv(strlen($canvas[0]), 3);
        $x = max(0, $x);
        $w = min($w, $width - $x);
        if ($w <= 0) {
            return;
        }
        $run = str_repeat($rgb, $w);
        for ($row = max(0, $y); $row < min(count($canvas), $y + $h); $row++) {
            $canvas[$row] = substr_replace($canvas[$row], $run, $x * 3, $w * 3);
        }
    }

    /** @param array<int, string> $canvas */
    private function text(array &$canvas, string $text, int $left, int $top, int $scale, string $rgb): void
    {
        foreach (str_split($text) as $position => $character) {
            $glyph = self::FONT[$character] ?? self::FONT[' '];
            foreach ($glyph as $row => $cells) {
                foreach (str_split($cells) as $column => $cell) {
                    if ($cell === '1') {
                        $this->fill($canvas, $left + ($position * 6 + $column) * $scale, $top + $row * $scale, $scale, $scale, $rgb);
                    }
                }
            }
        }
    }

    /** @param array<int, string> $canvas */
    private function png(array $canvas, int $width, int $height): string
    {
        $raw = '';
        foreach ($canvas as $row) {
            $raw .= "\x00".$row;
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw, 6))
            .$chunk('IEND', '');
    }

    private function pdfEscape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
