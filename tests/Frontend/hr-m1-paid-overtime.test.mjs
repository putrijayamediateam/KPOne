import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR month dry run M1 (owner, 16 Nov 2026): reversing overtime a locked payroll run already paid is allowed, but the
// reverse dialog names that run and says a recovery goes on an adjustment run, in Classic and in Version 2.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the reverse dialog names the locked run that paid the overtime, in Classic and V2', () => {
    for (const file of [
        'resources/js/components/hr/attendance/OvertimeTab.vue',
        'resources/js/components/hr/attendance/v2/OvertimeTabV2.vue',
    ]) {
        const source = read(file);
        assert.match(
            source,
            /v-if="dialog\.mode === 'reverse' && dialog\.target\?\.paidIn"[\s\S]*?data-test="overtime-paid-in"\s*>\s*Paid\s+in\s+the\s+locked\s+\{\{ dialog\.target\.paidIn \}\}\.\s+Reversing\s+it\s+does\s+not\s+change\s+that\s+run\s+or\s+its\s+payslip:\s+add\s+a\s+recovery\s+on\s+an\s+adjustment\s+run\./,
            file,
        );
    }
    assert.match(
        read('resources/js/components/hr/attendance/types.ts'),
        /paidIn: string \| null;/,
    );
});
