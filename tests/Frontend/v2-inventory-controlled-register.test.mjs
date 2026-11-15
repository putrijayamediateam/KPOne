import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const registry = read('config/v2-pages.php');
const parts = (page) => {
    const source = read(page);

    return {
        source,
        script: source.slice(0, source.indexOf('<template>')),
        template: source.slice(source.indexOf('<template>')),
        flat: source.slice(source.indexOf('<template>')).replace(/\s+/g, ' '),
    };
};

/** The source of `const name = …` up to the next top-level statement, without whitespace. */
const block = (source, name) => {
    const start = source.search(
        new RegExp(`\\n[ \\t]*(?:const|type|function) ${name}\\b`),
    );
    assert.ok(start >= 0, `${name} not found`);
    const rest = source.slice(start + 1);
    const indent = /^[ \t]*/.exec(rest)[0];
    const next = rest
        .slice(indent.length)
        .search(
            new RegExp(
                `\\n${indent}(?:const |type |let |watch|function |//|/\\*\\*|return \\{|\\}\\n|<\\/script>)`,
            ),
        );

    return (next < 0 ? rest : rest.slice(0, next + indent.length))
        .replace(/\s+/g, '')
        .replace(/,(?=[)\]}])/g, '');
};
/** Every `watch(` block of a source, without whitespace, in order. */
const watches = (source) =>
    source
        .slice(0, source.indexOf('</script>'))
        .split(/\nwatch\(/)
        .slice(1)
        .map((chunk) =>
            chunk.slice(0, chunk.indexOf('\n);')).replace(/\s+/g, ''),
        );

const listClassic = read('resources/js/pages/Inventory/ControlledRegister.vue');
const showClassic = read(
    'resources/js/pages/Inventory/ControlledRegisterShow.vue',
);
const list = parts('resources/js/pages/Inventory/V2/ControlledRegister.vue');
const show = parts(
    'resources/js/pages/Inventory/V2/ControlledRegisterShow.vue',
);

test('page 8: both Controlled register pages are listed for Version 2, and the print page is not', () => {
    assert.match(registry, /'Inventory\/ControlledRegister',/);
    assert.match(registry, /'Inventory\/ControlledRegisterShow',/);
    assert.doesNotMatch(registry, /'Inventory\/ControlledRegisterPrint'/);
});

test('page 8a: the list keeps the Classic row type, labels and tones verbatim', () => {
    for (const name of ['Row', 'statusLabel', 'statusTone']) {
        assert.equal(block(list.source, name), block(listClassic, name), name);
    }
});

test('page 8a: the list shows the DRAFT banner, on hand in unit words and a named Open register link', () => {
    assert.match(list.template, /data-testid="cd-draft-banner"/);
    assert.match(
        list.flat,
        /Draft register — pending pharmacist review\. Not a statutory register until a pharmacist approves its format\./,
    );
    assert.match(list.flat, /quantityWithUnit\(row\.balance, row\.stockUnit\)/);
    assert.match(list.flat, /Open register for /);
    assert.match(list.template, /cards="xl"/);
});

test('page 8b: the register script from the Classic page is copied verbatim', () => {
    for (const name of [
        'Line',
        'Check',
        'Register',
        'props',
        'reasons',
        'from',
        'to',
        'base',
        'visit',
        'printHref',
        'milli',
        'form',
        'errors',
        'busy',
        'differs',
        'submit',
        'fieldKeys',
        'formError',
    ]) {
        assert.equal(block(show.source, name), block(showClassic, name), name);
    }

    assert.deepEqual(
        watches(show.source).slice(0, watches(showClassic).length),
        watches(showClassic),
    );
});

test('page 8b: the check posts exactly what Classic posts, with its idempotency key', () => {
    assert.match(show.script, /idempotency_key/);
    assert.match(show.script, /seen_movement_id/);
    assert.match(
        show.script,
        /seen_check_sequence: props\.register\.newestCheckSequence/,
    );
});

test('page 8b: the checker sees what Classic shows and nothing more (balance before counting, a differs note while typing)', () => {
    assert.match(
        show.flat,
        /since the last check\. Count what is physically on hand at this branch and compare it with the register:/,
    );
    assert.match(show.flat, /words\(register\.balanceNow\)/);
    assert.match(show.flat, /data-testid="cd-differs-note"/);
    assert.match(
        show.flat,
        /This differs from the register\. Recording it does not change stock; correct stock with a governed stock count\./,
    );
    assert.match(show.flat, /Do not enter patient or prescriber details\./);
    assert.match(
        show.flat,
        /v-if="register\.checkBlockedReason"[^>]*data-testid="cd-check-blocked"/,
    );
    assert.match(show.flat, /v-else-if="register\.can\.check"/);
});

test('page 8b: Record check says why it is off, keeps its name and carries the plain sentence', () => {
    for (const line of [
        'Enter the counted quantity to continue.',
        'Enter a number, with up to 3 decimal places.',
        'Pick a reason for the difference to continue.',
        'Add a note to explain why.',
    ]) {
        assert.ok(show.script.includes(line), line);
    }

    assert.match(show.flat, /:disabled="busy \|\| blocked !== null"/);
    assert.match(show.flat, /'Record check'/);
    assert.match(
        show.flat,
        /Records a second-person check of the register\. A check never changes stock\./,
    );
});

test('page 8b: the reason starts empty, Other brings its note into view, and leaving with a typed count asks first', () => {
    assert.match(show.flat, /:model-value="form\.reason \|\| null"/);
    assert.match(
        show.script,
        /form\.reason === 'other'[\s\S]*?scrollIntoView[\s\S]*?focus\(\)/,
    );
    assert.match(show.flat, /<V2LeaveGuard :dirty="dirty" \/>/);
});

test('page 8b: movements keep the running balance, show "Showing N of M" and print stays the Classic page', () => {
    assert.match(show.flat, /words\(line\.balance\)/);
    assert.match(
        show.flat,
        /Showing \{\{ register\.lines\.rows\.length \}\} of \{\{ register\.lines\.total \}\}/,
    );
    assert.match(show.flat, /:href="printHref"[^>]*data-testid="cd-print"/);
    assert.match(show.template, /cards="xl"/);
});

test('page 8b: register dates are day-first boxes; an unreadable date or a reversed range keeps Show off', () => {
    assert.doesNotMatch(show.source, /V2DateRange|type="date"/);
    assert.equal((show.template.match(/<V2DateField/g) ?? []).length, 2);
    assert.match(show.flat, /v-model="from"/);
    assert.match(show.flat, /v-model="to"/);
    assert.match(show.flat, /:disabled="!rangeReady"/);
    assert.ok(show.script.includes('From must be on or before To.'));
});
