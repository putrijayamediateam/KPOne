<?php

namespace Tests\Unit\Agents;

use PHPUnit\Framework\TestCase;
use Tests\Support\AiReadViewList;

/** AG-7: the masking test names a missing or new ai_read view instead of failing with a bare list difference. */
class AiReadViewListTest extends TestCase
{
    public function test_matching_lists_have_no_problems(): void
    {
        $this->assertSame([], AiReadViewList::problems(['branches', 'visits'], ['branches', 'visits']));
    }

    public function test_a_missing_view_is_named_with_the_rule_to_follow(): void
    {
        $this->assertSame([
            'These ai_read views are missing, so a migration dropped them (for example with CASCADE) without recreating them: '
                .'ai_read.panel_claims, ai_read.visits. Recreate each view in the same migration; see docs/DEVELOPMENT.md, '
                .'"Ask KPOne read login".',
        ], AiReadViewList::problems(['branches', 'panel_claims', 'visits'], ['branches']));
    }

    public function test_a_new_view_asks_for_a_privacy_review(): void
    {
        $this->assertSame([
            'New ai_read views need a privacy review before AiReadSchemaMaskingTest lists them: ai_read.salaries.',
        ], AiReadViewList::problems(['branches'], ['branches', 'salaries']));
    }
}
