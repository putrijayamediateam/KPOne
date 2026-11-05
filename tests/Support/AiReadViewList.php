<?php

namespace Tests\Support;

/**
 * AG-7: compares the reviewed ai_read view list with the views in the database and explains any difference, so a
 * team whose migration dropped or added a view is told which one and where the rule is documented.
 */
final class AiReadViewList
{
    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     * @return list<string> one message per kind of difference; empty when the lists match
     */
    public static function problems(array $expected, array $actual): array
    {
        $problems = [];
        $missing = array_values(array_diff($expected, $actual));
        $added = array_values(array_diff($actual, $expected));

        if ($missing !== []) {
            $problems[] = 'These ai_read views are missing, so a migration dropped them (for example with CASCADE) without '
                .'recreating them: ai_read.'.implode(', ai_read.', $missing).'. Recreate each view in the same migration; '
                .'see docs/DEVELOPMENT.md, "Ask KPOne read login".';
        }
        if ($added !== []) {
            $problems[] = 'New ai_read views need a privacy review before AiReadSchemaMaskingTest lists them: ai_read.'
                .implode(', ai_read.', $added).'.';
        }

        return $problems;
    }
}
