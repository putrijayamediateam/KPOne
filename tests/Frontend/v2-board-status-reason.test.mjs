import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Registration/V2/Index.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('board not-ready reason wraps to two lines and keeps its full-text tooltip', () => {
    assert.match(page, /line-clamp-2/);
    assert.match(page, /max-xl:text-right max-sm:text-left/);
    assert.doesNotMatch(page, /callHold\?\.active" class="max-w-36 truncate/);
    assert.match(
        page,
        /:title="\s*item\.visit\.callHold\.reason \?\?\s*undefined\s*"/,
    );
    assert.match(page, /\{\{ item\.visit\.callHold\.reason \}\}/);
});
