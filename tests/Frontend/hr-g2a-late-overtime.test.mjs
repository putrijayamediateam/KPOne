import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// G2a (owner, 16 Nov 2026): overtime approved after its month's payroll was locked is flagged on the approve dialog,
// listed on the locked run, and paid only when HR adds it to a draft adjustment run.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the approve dialog says the month is locked, in Classic and V2', () => {
    for (const file of [
        'resources/js/components/hr/attendance/OvertimeTab.vue',
        'resources/js/components/hr/attendance/v2/OvertimeTabV2.vue',
    ]) {
        assert.match(
            read(file),
            /dialog\.mode === 'approve' && dialog\.target\?\.lockedMonth[\s\S]*?data-test="overtime-locked-month"\s*>\s*\{\{ dialog\.target\.lockedMonth \}\}'s\s+payroll\s+is\s+already\s+locked:\s+after\s+approving,\s+add\s+this\s+on\s+an\s+adjustment\s+run\./,
            file,
        );
    }

    assert.match(
        read('resources/js/components/hr/attendance/types.ts'),
        /lockedMonth: string \| null;/,
    );
});

test('the run page lists late overtime and adds it only on a draft adjustment', () => {
    const source = read('resources/js/pages/HR/PayrollRun.vue');
    assert.match(source, /late: LateItem\[\];/);
    assert.match(
        source,
        /v-if="run\.late\.length"[\s\S]*?Approved after this run was locked/,
    );
    assert.match(
        source,
        /v-if="run\.kind === 'adjustment' && run\.canEdit"[\s\S]*?v-if="item\.state === 'waiting'"[\s\S]*?data-test="run-late-add"[\s\S]*?Add to this adjustment[\s\S]*?v-else-if="item\.state === 'here'"[\s\S]*?data-test="run-late-remove"[\s\S]*?Take off/,
    );
    assert.match(source, /post\(`late\/\$\{item\.id\}`, \{\}\)/);
    assert.match(source, /post\(`late\/\$\{item\.itemId\}\/remove`, \{\}\)/);
});
