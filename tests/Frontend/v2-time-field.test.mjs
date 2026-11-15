import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const block = read('resources/js/components/v2/V2TimeField.vue');

const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(read('resources/js/lib/v2-time.ts'), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('parseTime: AM/PM forms and clear 24-hour forms', async () => {
    const { parseTime, toCanonical, toDisplay } = await loadLib();
    const c = (t) => {
        const p = parseTime(t);

        return p ? toCanonical(p) : null;
    };
    assert.equal(c('8am'), '08:00');
    assert.equal(c('8 AM'), '08:00');
    assert.equal(c('8:30 pm'), '20:30');
    assert.equal(c('8.30pm'), '20:30');
    assert.equal(c('5 PM'), '17:00');
    assert.equal(c('12am'), '00:00');
    assert.equal(c('12 pm'), '12:00');
    assert.equal(c('12:15 am'), '00:15');
    assert.equal(c('0830'), '08:30');
    assert.equal(c('08:30'), '08:30');
    assert.equal(c('1430'), '14:30');
    assert.equal(c('14:30'), '14:30');
    assert.equal(c('17:45'), '17:45');
    assert.equal(c('14'), '14:00');
    assert.equal(c('08'), '08:00');
    assert.equal(c('0:00'), '00:00');
    assert.equal(c('00:00'), '00:00');
    assert.equal(toDisplay(parseTime('14:30')), '2:30 PM');
    assert.equal(toDisplay(parseTime('00:05')), '12:05 AM');
    assert.equal(toDisplay(parseTime('12:00 pm')), '12:00 PM');
});

test('parseTime: a time that could be read either way is refused, with its own reason', async () => {
    const { parseTime, timeProblem } = await loadLib();

    for (const ambiguous of [
        '8',
        '5',
        '5:30',
        '830',
        '12',
        '12:30',
        '1230',
        '9.15',
    ]) {
        assert.equal(parseTime(ambiguous), null, ambiguous);
        assert.equal(timeProblem(ambiguous), 'ambiguous', ambiguous);
    }

    for (const clear of ['8am', '0830', '17:45', '12am', '12pm', '14']) {
        assert.equal(timeProblem(clear), null, clear);
    }
});

test('parseTime: nothing else is guessed', async () => {
    const { parseTime, timeProblem, displayFromCanonical } = await loadLib();

    for (const bad of [
        '',
        'noon',
        '25:00',
        '8:60',
        '13pm',
        '0am',
        '8:5',
        '12345',
        '8:30:15',
        '-8',
        '8 am pm',
        '8:300',
    ]) {
        assert.equal(parseTime(bad), null, bad);
        assert.equal(timeProblem(bad), 'invalid', bad);
    }

    assert.equal(displayFromCanonical('14:30'), '2:30 PM');
    assert.equal(displayFromCanonical('24:00'), '');
    assert.equal(displayFromCanonical('8:00'), '');
    assert.equal(displayFromCanonical(''), '');
});

test('V2TimeField: canonical model, error words, echo is visual, polite announcement, V2 only', () => {
    assert.match(block, /TIME_ERROR/);
    assert.match(block, /TIME_ERROR_AMPM/);
    assert.match(block, /send\(''\)/);
    assert.match(block, /send\(toCanonical\(parsed\)\)/);
    assert.match(block, /aria-hidden="true"\s+data-slot="v2-time-echo"/);
    assert.match(block, /role="status" aria-live="polite"/);
    assert.match(block, /type="text"/);
    assert.doesNotMatch(block, /type="time"/);
    assert.doesNotMatch(block, /components\/ui/);
});
