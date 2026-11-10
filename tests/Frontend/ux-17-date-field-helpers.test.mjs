import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// UX-17: the KPOne date fields show and accept dd/mm/yyyy whatever the browser language is,
// and send the same yyyy-mm-dd, yyyy-mm-ddTHH:mm and yyyy-mm values as a native input.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const lib = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/date-field.ts'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    { exports: lib.exports, Intl, Date },
);
const h = lib.exports;

test('a date shows as dd/mm/yyyy and empty or invalid values show nothing', () => {
    assert.equal(h.formatDisplayDate('2026-11-10'), '10/11/2026');
    assert.equal(h.formatDisplayDate('2026-01-05'), '05/01/2026');
    assert.equal(h.formatDisplayDate(''), '');
    assert.equal(h.formatDisplayDate(null), '');
    assert.equal(h.formatDisplayDate('2026-02-30'), '');
    assert.equal(h.formatDisplayDate('10/11/2026'), '');
});

test('typed and pasted dates are read day first', () => {
    for (const text of [
        '10/11/2026',
        '10-11-2026',
        '10.11.2026',
        '10112026',
        '2026-11-10',
        ' 10/11/2026 ',
    ]) {
        assert.equal(h.parseDisplayDate(text), '2026-11-10', text);
    }

    assert.equal(h.parseDisplayDate('1/2/2026'), '2026-02-01');
    assert.equal(h.parseDisplayDate('05/01/2026'), '2026-01-05');
});

test('a date that is not a real calendar date is refused', () => {
    for (const text of [
        '31/04/2026',
        '30/02/2026',
        '29/02/2026',
        '00/01/2026',
        '10/13/2026',
        '10/11/26',
        '10/11',
        'abc',
        '',
        '1/1/20266',
    ]) {
        assert.equal(h.parseDisplayDate(text), null, text);
    }

    assert.equal(h.parseDisplayDate('29/02/2028'), '2028-02-29');
    assert.equal(h.parseDisplayDate('29/02/2100'), null);
});

test('the box adds the slash after a full day or month and never rewrites a delete', () => {
    assert.equal(h.maskDateInput('1', ''), '1');
    assert.equal(h.maskDateInput('10', '1'), '10/');
    assert.equal(h.maskDateInput('10/1', '10/'), '10/1');
    assert.equal(h.maskDateInput('10/11', '10/1'), '10/11/');
    assert.equal(h.maskDateInput('10/11/2026', '10/11/202'), '10/11/2026');
    assert.equal(h.maskDateInput('10/11/20269', '10/11/2026'), '10/11/2026');
    assert.equal(h.maskDateInput('10/', '10/1'), '10/');
    assert.equal(h.maskDateInput('1/', '1'), '1/');
    assert.equal(h.maskDateInput('1/2', '1/'), '1/2');
    assert.equal(h.maskDateInput('10-11', '10-1'), '10/11/');
    assert.equal(h.maskDateInput('ab1', ''), '1');
});

test('min and max are inclusive', () => {
    assert.equal(h.withinRange('2026-11-10', '2026-11-10', '2026-11-10'), true);
    assert.equal(h.withinRange('2026-11-09', '2026-11-10', null), false);
    assert.equal(h.withinRange('2026-11-11', null, '2026-11-10'), false);
    assert.equal(h.withinRange('2026-11-11', undefined, undefined), true);
});

test('months read mm/yyyy', () => {
    assert.equal(h.formatDisplayMonth('2026-11'), '11/2026');
    assert.equal(h.formatDisplayMonth('2026-13'), '');
    assert.equal(h.parseDisplayMonth('11/2026'), '2026-11');
    assert.equal(h.parseDisplayMonth('1/2026'), '2026-01');
    assert.equal(h.parseDisplayMonth('2026-11'), '2026-11');
    assert.equal(h.parseDisplayMonth('13/2026'), null);
    assert.equal(h.parseDisplayMonth('11/26'), null);
    assert.equal(h.maskMonthInput('11', '1'), '11/');
    assert.equal(h.maskMonthInput('11/2026', '11/202'), '11/2026');
});

test('time is 12-hour with AM and PM and converts both ways', () => {
    assert.deepEqual(
        { ...h.to12Hour('17:16') },
        { hour: '5', minute: '16', meridiem: 'PM' },
    );
    assert.deepEqual(
        { ...h.to12Hour('00:05') },
        { hour: '12', minute: '05', meridiem: 'AM' },
    );
    assert.deepEqual(
        { ...h.to12Hour('12:00') },
        { hour: '12', minute: '00', meridiem: 'PM' },
    );
    assert.equal(h.to12Hour('24:00'), null);
    assert.equal(h.from12Hour('5', '16', 'PM'), '17:16');
    assert.equal(h.from12Hour('12', '05', 'AM'), '00:05');
    assert.equal(h.from12Hour('12', '00', 'PM'), '12:00');
    assert.equal(h.from12Hour('11', '59', 'PM'), '23:59');
    assert.equal(h.from12Hour('0', '10', 'AM'), null);
    assert.equal(h.from12Hour('13', '10', 'AM'), null);
    assert.equal(h.from12Hour('5', '60', 'AM'), null);
    assert.deepEqual(
        { ...h.splitDateTime('2026-11-10T17:16') },
        { date: '2026-11-10', time: '17:16' },
    );
    assert.deepEqual({ ...h.splitDateTime('') }, { date: '', time: '' });
});

test('the calendar grid starts on Monday and covers the whole month', () => {
    const weeks = h.calendarWeeks(2026, 11);

    assert.equal(weeks[0][0].iso, '2026-10-26');
    assert.equal(weeks[0][0].inMonth, false);
    assert.equal(weeks[0][6].iso, '2026-11-01');
    assert.equal(weeks[0][6].inMonth, true);
    assert.ok(weeks.every((week) => week.length === 7));
    assert.equal(weeks.flat().filter((cell) => cell.inMonth).length, 30);

    const february = h.calendarWeeks(2028, 2);

    assert.equal(february.flat().filter((cell) => cell.inMonth).length, 29);
    assert.equal(february[0][0].iso, '2028-01-31');
});

test('moving by days and months keeps dates real', () => {
    assert.equal(h.addDaysIso('2026-12-31', 1), '2027-01-01');
    assert.equal(h.addDaysIso('2026-03-01', -1), '2026-02-28');
    assert.equal(h.addMonthsIso('2026-01-31', 1), '2026-02-28');
    assert.equal(h.addMonthsIso('2026-11-10', -11), '2025-12-10');
    assert.equal(h.addMonthsIso('2028-01-31', 1), '2028-02-29');
});

test('today is the Kuala Lumpur date whatever the computer time zone is', () => {
    assert.equal(h.todayIso(new Date('2026-11-09T17:30:00Z')), '2026-11-10');
    assert.equal(h.todayIso(new Date('2026-11-10T15:59:00Z')), '2026-11-10');
    assert.equal(h.todayIso(new Date('2026-11-10T16:00:00Z')), '2026-11-11');
});

test('a paste is read whole, not one character at a time', () => {
    assert.equal(h.maskDateInput('10112026', ''), '10/11/2026');
    assert.equal(h.maskDateInput('2026-11-10', ''), '10/11/2026');
    assert.equal(h.maskMonthInput('2026-11', ''), '11/2026');
});

test('errors say what is wrong and show the range', () => {
    assert.equal(h.dateProblem('', undefined, undefined), '');
    assert.equal(h.dateProblem('abc', undefined, undefined), 'Enter a date as dd/mm/yyyy.');
    assert.equal(h.dateProblem('31/04/2026', undefined, undefined), '31/04/2026 is not a real date.');
    assert.equal(
        h.dateProblem('01/11/2026', '2026-11-05', '2026-11-20'),
        'Choose a date from 05/11/2026 to 20/11/2026.',
    );
    assert.equal(h.dateProblem('10/11/2026', '2026-11-05', '2026-11-20'), '');
    assert.match(h.dateProblem('31/04/2026', undefined, undefined, 'ms'), /bukan tarikh yang sah/);
    assert.equal(h.monthProblem('13/2026', undefined, undefined), '13/2026 is not a real month.');
});

test('the echo and the Malay words', () => {
    assert.equal(h.formatEchoDate('2026-11-10'), '10 Nov 2026');
    assert.equal(h.formatEchoDate('2026-05-01', 'ms'), '1 Mei 2026');
    assert.equal(h.formatEchoTime('17:16'), '5:16 PM');
    assert.equal(h.DATE_FIELD_TEXT.ms.choose, 'Pilih tarikh (dd/mm/yyyy)');
    assert.equal(h.DATE_FIELD_TEXT.en.weekdays[0], 'Mon');
    assert.equal(h.DATE_FIELD_TEXT.ms.weekdays[0], 'Isn');
});
