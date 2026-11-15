import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-4c: the Audit log shows where Call next was used (owner decisions 2026-11-06).
const page = readFileSync(
    new URL('../../resources/js/pages/AuditLogs/Index.vue', import.meta.url),
    'utf8',
);

test('the audit log shows a short detail line only when the server sends one', () => {
    assert.match(page, /detail\?: string \| null;/);
    assert.match(
        page,
        /v-if="log\.detail"\s*class="text-xs text-muted-foreground"\s*data-testid="audit-detail"\s*>\s*\{\{ log\.detail \}\}/,
    );
});
