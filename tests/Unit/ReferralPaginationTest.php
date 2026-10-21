<?php

namespace Tests\Unit;

use App\Domain\Clinical\Documents\ReferralPagination;
use PHPUnit\Framework\TestCase;

class ReferralPaginationTest extends TestCase
{
    public function test_long_clinical_text_retains_all_characters_and_short_consent_is_not_split(): void
    {
        $text = str_repeat('Medicine directions and relevant clinical history.  雪 é ', 150);
        $pages = (new ReferralPagination)->pages([['History', $text], ['Consent', 'Specific recipient agreement. Written procedure remains required.']]);
        $this->assertGreaterThan(2, count($pages));
        $body = '';
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(ReferralPagination::LINES_PER_PAGE, count($page));
            foreach ($page as $line) {
                if (! $line['heading'] && $line['text'] !== 'Specific recipient agreement. Written procedure remains required.') {
                    $body .= $line['text'];
                }
                if (! $line['heading']) {
                    $this->assertLessThanOrEqual(ReferralPagination::COLUMNS, mb_strwidth($line['text'], 'UTF-8'));
                }
            }
        }
        $this->assertSame($text, $body);
        $last = $pages[array_key_last($pages)];
        $this->assertContains(['text' => 'Consent', 'heading' => true], $last);
        $this->assertContains(['text' => 'Specific recipient agreement. Written procedure remains required.', 'heading' => false], $last);
    }

    public function test_normal_words_are_not_split_but_long_unbroken_values_still_fit(): void
    {
        $pages = (new ReferralPagination)->pages([['History', str_repeat('specialist ', 40)]]);
        foreach ($pages[0] as $line) {
            if (! $line['heading']) {
                $this->assertMatchesRegularExpression('/^(specialist )*$/', $line['text']);
            }
        }
        $long = str_repeat('x', 300);
        $pages = (new ReferralPagination)->pages([['Finding', $long]]);
        $this->assertSame($long, implode('', array_column(array_filter($pages[0], fn ($l) => ! $l['heading']), 'text')));
    }
}
