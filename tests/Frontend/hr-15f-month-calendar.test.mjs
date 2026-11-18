import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15f (owner, via B.E.R.N.I.S.S, 18 Nov 2026; layout by Daniel): a month calendar on My roster, Classic and V2.
const calendar = readFileSync(
    'resources/js/components/hr/roster/MonthCalendar.vue',
    'utf8',
);
const composable = readFileSync(
    'resources/js/composables/hr/useRosterPage.ts',
    'utf8',
);
const pages = {
    classic: readFileSync('resources/js/pages/HR/Roster.vue', 'utf8'),
    v2: readFileSync('resources/js/pages/HR/V2/Roster.vue', 'utf8'),
};

test('Week stays the default and the month is in the address, nothing stored', () => {
    assert.match(
        composable,
        /visit\(\{ myView: 'month', month: month \?\? props\.roster\.month\?\.key \}\)/,
    );
    assert.match(
        composable,
        /visit\(\{ myView: undefined, month: undefined \}\)/,
    );
    assert.doesNotMatch(calendar, /localStorage|sessionStorage/);
});

for (const [name, page] of Object.entries(pages)) {
    test(`${name}: a Week | Month switch on My roster and month navigation`, () => {
        assert.match(page, /data-test="roster-view-switch"/);
        assert.match(page, /Show my roster by/);
        assert.match(page, /data-test="month-switcher"/);
        assert.match(page, /showMonth\(roster\.month\.previous\)/);
        assert.match(page, /showMonth\(roster\.month\.next\)/);
        assert.match(page, /<MonthCalendar/);
        assert.match(
            page,
            /props\.roster\.tab === 'my' && props\.roster\.view === 'month'/,
        );
    });
}

test('Classic marks the chosen view with aria-pressed', () => {
    assert.match(pages.classic, /:aria-pressed="!isMonth"/);
    assert.match(pages.classic, /:aria-pressed="isMonth"/);
});

test('the grid is a real table with a caption and weekday headers', () => {
    assert.match(calendar, /<table/);
    assert.match(calendar, /<caption class="sr-only">/);
    assert.match(calendar, /scope="col"/);
    assert.match(
        calendar,
        /\['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'\]/,
    );
});

test('only an in-month day with items is a button, named in words; today is more than a ring', () => {
    assert.match(calendar, /v-if="day\.inMonth && count\(day\) > 0"/);
    assert.match(
        calendar,
        /:aria-label="`\$\{day\.label\}\$\{day\.today \? ', today' : ''\}, \$\{countWords\(day\)\}`"/,
    );
    assert.match(calendar, /min-h-11/);
    assert.match(
        calendar,
        /<span v-if="day\.today" class="sr-only">Today<\/span>/,
    );
});

test('narrow cells show a count; wide cells show 2 lines (3 from 1280) then "+N more"', () => {
    assert.match(calendar, /lg:hidden/);
    assert.match(calendar, /shortCount\(day\)/);
    assert.match(calendar, /\+\{\{ lines\(day\)\.length - 2 \}\} more/);
    assert.match(calendar, /\+\{\{ lines\(day\)\.length - 3 \}\} more/);
    // An ask is a dashed outline with its word, never shape only.
    assert.match(calendar, /text: `\$\{a\.shortLabel\} \$\{a\.startLabel\}`/);
    assert.match(calendar, /border-dashed/);
});

test('the day sheet and the phone list carry the full lines; hours are approved shifts only', () => {
    assert.match(calendar, /data-test="month-day-sheet"/);
    assert.match(
        calendar,
        /Hours count approved shifts only\.\s+Times are in each/,
    );
    assert.match(calendar, /\{\{ month\.hoursLabel \}\} approved this month/);
    assert.match(calendar, /No shifts this month\./);
    assert.match(calendar, /\{\{\s+ask\.label\s+\}\}/);
});

test('Daniel round 2: short cell words, shared AM/PM, confirmed counted apart, a clear selected view', () => {
    assert.match(calendar, /\$\{pending\(day\)\} not approved/);
    assert.match(calendar, /'confirmed shift not approved yet'/);
    assert.match(
        calendar,
        /text: `\$\{compact\(s\.startLabel, s\.endLabel\)\} \$\{s\.branch\}`/,
    );
    assert.match(pages.classic, /bg-primary text-primary-foreground/);
});
