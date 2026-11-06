import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/Queue/Index.vue', import.meta.url),
    'utf8',
);

test('the Completed tab says when it lists only the latest entries of a bigger count', () => {
    assert.match(
        page,
        /const completedHidden = computed\(\s*\(\) => \(live\.value\.tabCounts\?\.removed \?\? 0\) - live\.value\.removed\.length,?\s*\)/,
    );
    const line = page.match(
        /<p\s+v-if="activeTab === 'removed' && completedHidden > 0"([\s\S]*?)<\/p>/,
    );
    assert.ok(
        line,
        'the count line is shown only on the Completed tab, and only when rows are hidden',
    );
    assert.match(line[1], /data-testid="completed-shown-count"/);
    assert.match(line[1], /text-muted-foreground/);
    assert.match(
        line[1],
        /Showing the latest \{\{ live\.removed\.length \}\} of\s+\{\{ live\.tabCounts\?\.removed \}\}/,
    );
});

test('the Completed tab count is still the server count of every completed entry', () => {
    assert.match(
        page,
        /label: 'Consultation Completed',\s*count: live\.value\.tabCounts\?\.removed,/,
    );
});
