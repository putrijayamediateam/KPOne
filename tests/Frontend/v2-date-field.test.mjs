import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(read('resources/js/lib/v2-date.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('readDate: day first, every accepted shape gives the same ISO date', async () => {
    const { readDate } = await loadLib();
    const iso = (t) => {
        const r = readDate(t);

        return r.ok ? r.date.iso : null;
    };

    for (const text of [
        '13 Nov 2026',
        '13/11/2026',
        '13-11-2026',
        '13.11.2026',
        '13112026',
        '13 11 2026',
        '13 november 2026',
        '13-NOV-2026',
        '13 / 11 / 2026',
        '  13  Nov  2026  ',
    ]) {
        assert.equal(iso(text), '2026-11-13', text);
    }

    assert.equal(iso('5 Mei 2027'), '2027-05-05');
    assert.equal(iso('1 Okt 2026'), '2026-10-01');
    assert.equal(iso('25 Dis 2026'), '2026-12-25');
    assert.equal(iso('1 Ogos 2026'), '2026-08-01');
    assert.equal(iso('1 Mac 2026'), '2026-03-01');
    assert.equal(iso('1/2/2026'), '2026-02-01');
    assert.equal(iso('29 Feb 2028'), '2028-02-29');
});

test('readDate: display, weekday', async () => {
    const { readDate } = await loadLib();
    const r = readDate('13/11/2026');
    assert.equal(r.date.display, '13 Nov 2026');
    assert.equal(r.date.weekday, 'Fri');
    assert.equal(readDate('1 Jan 2027').date.weekday, 'Fri');
    assert.equal(readDate('29 Feb 2028').date.weekday, 'Tue');
});

test('readDate: impossible dates name the date; nothing is guessed', async () => {
    const { readDate } = await loadLib();
    const msg = (t) => {
        const r = readDate(t);

        return r.ok ? 'OK ' + r.date.iso : r.problem.message;
    };

    assert.equal(msg('31 Nov 2026'), 'There is no 31 Nov.');
    assert.equal(msg('31/04/2026'), 'There is no 31 Apr.');
    assert.equal(msg('30 Feb 2026'), 'There is no 30 Feb.');
    assert.equal(msg('29 Feb 2027'), 'There is no 29 Feb in 2027.');
    assert.equal(msg('29 Feb 2100'), 'There is no 29 Feb in 2100.');
    assert.match(msg('29 Feb 2000'), /^OK 2000-02-29$/);
    assert.equal(
        msg('11/13/2026'),
        'Type the date day first, such as 13 Dec 2026.',
    );
    assert.equal(
        msg('12/13/2026'),
        'Type the date day first, such as 13 Dec 2026.',
    );
    assert.equal(msg('32/13/2026'), 'Enter a real date such as 13 Nov 2026.');
    assert.equal(msg('0 Nov 2026'), 'Enter a real date such as 13 Nov 2026.');
    assert.equal(msg('13/0/2026'), 'Enter a real date such as 13 Nov 2026.');
    assert.equal(msg('13/11/26'), 'Type the full year, such as 2026.');
    assert.equal(msg('13 Nov 202'), 'Type the full year, such as 2026.');

    for (const bad of [
        '',
        'today',
        'tomorrow',
        '13',
        '13 Nov',
        '2026-11-13',
        '13 Foo 2026',
        '1311202',
        '131120266',
    ]) {
        assert.equal(msg(bad), 'Enter a date such as 13 Nov 2026.', bad);
    }
});

test('fromIso, displayFromIso and rangeProblem', async () => {
    const { fromIso, displayFromIso, rangeProblem } = await loadLib();
    assert.equal(displayFromIso('2026-11-13'), '13 Nov 2026');
    assert.equal(displayFromIso('2026-02-30'), '');
    assert.equal(displayFromIso('13/11/2026'), '');
    assert.equal(displayFromIso(''), '');
    assert.equal(fromIso('2026-11-13').weekday, 'Fri');
    assert.equal(
        rangeProblem('2026-01-01', '2026-02-01', undefined),
        'Choose a date on or after 1 Feb 2026.',
    );
    assert.equal(
        rangeProblem('2026-03-01', undefined, '2026-02-01'),
        'Choose a date on or before 1 Feb 2026.',
    );
    assert.equal(
        rangeProblem('2026-02-01', '2026-02-01', '2026-02-01'),
        undefined,
    );
    assert.equal(rangeProblem('2026-02-01'), undefined);
});

test('V2DateField: ISO model, empty on bad text, clear when optional, calendar button, echo is visual, V2 only', () => {
    const s = read('resources/js/components/v2/V2DateField.vue');
    assert.match(s, /send\(r\.date\.iso\)/);
    assert.match(s, /send\(''\)/);
    assert.match(s, /!required && !disabled && modelValue !== ''/);
    assert.match(s, /`Clear \$\{label\}`/);
    assert.match(s, /showPicker\(\)/);
    assert.match(s, /weekday: false/);
    assert.match(s, /props\.weekday \?/);
    assert.match(s, /subject: undefined/);
    assert.match(s, /minMessage: undefined/);
    assert.match(s, /maxMessage: undefined/);
    assert.match(s, /props\.minMessage\s*:\s*props\.maxMessage/);
    assert.match(s, /Choose a date for/);
    assert.match(s, /NEVER reads month-first/);
    assert.match(s, /aria-hidden="true"\s+data-slot="v2-date-echo"/);
    assert.match(s, /role="status" aria-live="polite"/);
    assert.match(s, /type="text"/);
    assert.match(s, /lg:h-9/);
    assert.match(s, /PAGE must block Save/);
    assert.doesNotMatch(s, /components\/ui/);
    // The only native date input is the hidden one behind the calendar button.
    assert.equal((s.match(/type="date"/g) ?? []).length, 1);
    assert.match(s, /type="date"\s+class="sr-only"/);
});

test('V2DateField tells the page when the box holds text it could not read', () => {
    const src = read('resources/js/components/v2/V2DateField.vue');
    assert.match(src, /'update:invalid': \[value: boolean\]/);
    assert.match(
        src,
        /watch\(typedProblem, \(problem\) => emit\('update:invalid', problem !== null\)\)/,
    );
    assert.match(src, /@update:invalid/);
});
