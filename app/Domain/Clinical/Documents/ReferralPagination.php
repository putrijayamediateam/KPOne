<?php

namespace App\Domain\Clinical\Documents;

final class ReferralPagination
{
    public const COLUMNS = 72;

    public const LINES_PER_PAGE = 27;

    public const KEEP_TOGETHER_LINES = 8;

    /** No truncation: every grapheme and whitespace character is retained.
     * @param  list<array{string, string}>  $sections
     * @return list<list<array{text: string, heading: bool}>>
     */
    public function pages(array $sections): array
    {
        $pages = [];
        $current = [];
        foreach ($sections as [$heading, $text]) {
            $block = [['text' => $heading, 'heading' => true]];
            foreach (explode("\n", $text) as $paragraph) {
                foreach ($this->wrap($paragraph) as $line) {
                    $block[] = ['text' => $line, 'heading' => false];
                }
            }
            $block[] = ['text' => '', 'heading' => false];
            // Keep genuinely short sections together, without moving a long
            // section wholesale off a partly used page. Never orphan a heading.
            if ($current !== [] && ((count($block) <= self::KEEP_TOGETHER_LINES && count($current) + count($block) > self::LINES_PER_PAGE) || count($current) >= self::LINES_PER_PAGE - 1)) {
                $pages[] = $current;
                $current = [];
            }
            foreach ($block as $lineIndex => $line) {
                // The generated separator must not become a header-only page.
                // User-entered blank lines are separate body lines and are retained.
                if ($lineIndex === count($block) - 1 && count($current) === self::LINES_PER_PAGE) {
                    continue;
                }
                if (count($current) === self::LINES_PER_PAGE) {
                    $pages[] = $current;
                    $current = [];
                }
                $current[] = $line;
            }
        }
        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages;
    }

    /** @return list<string> */
    private function wrap(string $paragraph): array
    {
        preg_match_all('/\X/u', $paragraph, $matches);
        $result = [];
        $line = '';
        foreach ($matches[0] as $grapheme) {
            if (mb_strwidth($line.$grapheme, 'UTF-8') > self::COLUMNS) {
                $space = mb_strrpos($line, ' ', 0, 'UTF-8');
                $cut = $space !== false && $space > 0 ? $space + 1 : mb_strlen($line, 'UTF-8');
                $result[] = mb_substr($line, 0, $cut, 'UTF-8');
                $line = mb_substr($line, $cut, null, 'UTF-8');
            }
            $line .= $grapheme;
        }
        $result[] = $line;

        return $result;
    }
}
