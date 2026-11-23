import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// G2b (owner, 16 Nov 2026): unpaid leave cancelled or approved and worked hours corrected after a month's payroll was
// locked are flagged on their dialogs, listed on the locked run, and go on an adjustment run only when HR adds them.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the leave dialogs say the month is locked, in Classic, from one composable line', () => {
    const composable = read('resources/js/composables/hr/useLeavePage.ts');

    assert.match(
        composable,
        /action\.kind === 'approve'[\s\S]*?row\.lockedMonth[\s\S]*?'s payroll is already locked: after approving, add this on an adjustment run\./,
    );
    assert.match(
        composable,
        /action\.kind === 'manager-cancel'[\s\S]*?row\.takenInMonth[\s\S]*?'s payroll is already locked and took this leave off pay: after cancelling, add it back on an adjustment run\./,
    );
    assert.match(composable, /actionPayrollLine,/);

    for (const file of ['resources/js/pages/HR/Leave.vue']) {
        assert.match(
            read(file),
            /v-if="actionPayrollLine"[\s\S]*?data-test="leave-locked-month"[\s\S]*?\{\{ actionPayrollLine \}\}/,
            file,
        );
    }
});

test('the correction approve dialog says the month is locked, in Classic', () => {
    for (const file of ['resources/js/pages/HR/Attendance.vue']) {
        assert.match(
            read(file),
            /decision\.approve && decision\.target\?\.lockedMonth[\s\S]*?data-test="correction-locked-month"[\s\S]*?\{\{ decision\.target\.lockedMonth \}\}'s payroll is already locked:\s+after approving, add the hours on an adjustment run\./,
            file,
        );
    }

    assert.match(
        read('resources/js/composables/hr/useAttendancePage.ts'),
        /lockedMonth\?: string \| null;/,
    );
});

test('the run card shows each kind with its own word and the change in words, never money', () => {
    // The run page's script lives in its shared composable; both are read.
    const source = `${read('resources/js/pages/HR/PayrollRun.vue')}\n${read('resources/js/composables/hr/usePayrollRunPage.ts')}`;

    assert.match(
        source,
        /type LateKind = 'overtime' \| 'leave_cancelled' \| 'leave_approved' \| 'hours';/,
    );
    // Overtime-only cards keep their G2a words; a mixed card says "changed".
    assert.match(
        source,
        /v-if="lateOnlyOvertime">Late overtime[\s\S]*?v-else>Late changes[\s\S]*?Approved after this run was locked[\s\S]*?Changed after this run was locked/,
    );
    // Each row: a neutral dot-plus-word chip and the change in days or hours, in the table and the cards.
    assert.match(
        source,
        /data-test="run-late-kind"[\s\S]*?bg-muted-foreground[\s\S]*?\{\{ item\.kindLabel \}\}[\s\S]*?data-test="run-late-change"[\s\S]*?\{\{ item\.change \}\}/,
    );
    assert.match(
        source,
        /data-test="run-late-card"[\s\S]*?\{\{ item\.kindLabel \}\}[\s\S]*?\{\{ item\.change \}\}/,
    );
    assert.doesNotMatch(
        source.slice(
            source.indexOf('data-test="run-late"'),
            source.indexOf('<EmptyState'),
        ),
        /myr\(|amountSen|rmText/,
    );
    // Overtime keeps its G2a request; other kinds send their kind.
    assert.match(
        source,
        /item\.kind === 'overtime'\s*\? post\(`late\/\$\{item\.id\}`, \{\}\)\s*: post\(`late\/\$\{item\.id\}`, \{ kind: item\.kind \}\)/,
    );
    assert.match(
        source,
        /post\(`late\/\$\{item\.itemId\}\/remove`, \{ kind: item\.kind \}\)/,
    );
    // Daniel: no blank "Decided on" cell.
    assert.match(source, /item\.approvedAt \? on\(item\.approvedAt\) : '—'/);
    // Suresh m1: runs that did not record paid hours say corrections cannot be checked.
    assert.match(
        source,
        /v-if="run\.lateHoursUnknown > 0"[\s\S]*?data-test="run-late-hours-unknown"[\s\S]*?Hours changed after this lock can't be checked for/,
    );
    // The add and take-off buttons say which item, kind included, to screen readers.
    assert.match(
        source,
        /Add to this adjustment<span[\s\S]*?\{\{ item\.name \}\},\s*\{\{ item\.kindLabel \}\},/,
    );
});
