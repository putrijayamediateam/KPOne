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

test('parseTime: 12-hour and 24-hour forms', async () => {
    const { parseTime, toCanonical, toDisplay } = await loadLib();
    const c = (t) => {
        const p = parseTime(t);

        return p ? toCanonical(p) : null;
    };
    assert.equal(c('8am'), '08:00');
    assert.equal(c('8 AM'), '08:00');
    assert.equal(c('8:30 pm'), '20:30');
    assert.equal(c('8.30pm'), '20:30');
    assert.equal(c('12am'), '00:00');
    assert.equal(c('12 pm'), '12:00');
    assert.equal(c('12:15 am'), '00:15');
    assert.equal(c('0830'), '08:30');
    assert.equal(c('830'), '08:30');
    assert.equal(c('1430'), '14:30');
    assert.equal(c('14:30'), '14:30');
    assert.equal(c('8'), '08:00');
    assert.equal(c('14'), '14:00');
    assert.equal(c('0:00'), '00:00');
    assert.equal(toDisplay(parseTime('14:30')), '2:30 PM');
    assert.equal(toDisplay(parseTime('00:05')), '12:05 AM');
    assert.equal(toDisplay(parseTime('12:00')), '12:00 PM');
});

test('parseTime: nothing is guessed', async () => {
    const { parseTime, displayFromCanonical } = await loadLib();

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
    }

    assert.equal(displayFromCanonical('14:30'), '2:30 PM');
    assert.equal(displayFromCanonical('24:00'), '');
    assert.equal(displayFromCanonical('8:00'), '');
    assert.equal(displayFromCanonical(''), '');
});

test('V2TimeField: canonical model, error words, echo is visual, polite announcement, V2 only', () => {
    assert.match(block, /TIME_ERROR/);
    assert.match(block, /emit\('update:modelValue', ''\)/);
    assert.match(block, /emit\('update:modelValue', toCanonical\(parsed\)\)/);
    assert.match(block, /aria-hidden="true"\s+data-slot="v2-time-echo"/);
    assert.match(block, /role="status" aria-live="polite"/);
    assert.match(block, /type="text"/);
    assert.doesNotMatch(block, /type="time"/);
    assert.doesNotMatch(block, /components\/ui/);
});
