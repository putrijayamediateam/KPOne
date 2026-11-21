import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const flat = (s) => s.replace(/\s+/g, ' ');
const pages = {
    classic: read('resources/js/pages/Inventory/ControlledRegisterShow.vue'),
};
const lists = {
    classic: read('resources/js/pages/Inventory/ControlledRegister.vue'),
};
const script = (s) => s.slice(0, s.indexOf('</script>'));

test('STK-40b: on the Classic pages the count is always recorded and nothing says it differs before it is saved', () => {
    for (const [name, source] of Object.entries(pages)) {
        const s = flat(script(source));
        // The record form sends the count only; the server never answers "differs" before it is saved.
        assert.doesNotMatch(s, /toldDiffers/, name);
        assert.match(
            s,
            /seen_check_sequence: props\.register\.newestCheckSequence, counted: form\.counted\.trim\(\), \}/,
            name,
        );
        assert.match(
            s,
            /const form = reactive\(\{ counted: '', key: crypto\.randomUUID\(\), \}\);/,
            name,
        );
        // The reason goes afterwards, to its own address, by the checker.
        assert.match(
            s,
            /`\$\{base\.value\}\/checks\/\$\{check\.publicId\}\/reason`/,
            name,
        );
        assert.match(s, /balanceNow: string \| null; blind: boolean;/, name);
    }
});

test('STK-40: on the Classic pages the page says the balance comes after the count, with no print until then', () => {
    for (const [name, source] of Object.entries(pages)) {
        const t = flat(source.slice(source.indexOf('<template>')));
        assert.match(t, /on hand shown after your count/, name);
        assert.match(t, /Print the register after your count\./, name);
        assert.match(
            t,
            /v-(?:else|if="!register\.blind")[^>]*:href="printHref"|v-if="!register\.blind" :href="printHref"|v-else :href="printHref"/,
            name,
        );
        assert.match(
            t,
            /Quantities are hidden until you record your count, so your check does not depend on the register\./,
            name,
        );
        assert.match(
            t,
            /The register's balance appears after you record your count\./,
            name,
        );
        assert.match(t, /Quantities shown after your count\./, name);
        assert.match(t, /· \{\{ difference\(check\.difference\) \}\}/, name);
    }
});

test('STK-40: while blind the In, Out and Balance columns are left out, the differs note says to add the reason (STK-40b), and a match reads "no difference"', () => {
    for (const [name, source] of Object.entries(pages)) {
        const t = flat(source.slice(source.indexOf('<template>')));
        assert.equal(
            (t.match(/<th v-if="!register\.blind"/g) ?? []).length,
            3,
            name,
        );
        assert.equal(
            (t.match(/<td v-if="!register\.blind"/g) ?? []).length,
            3,
            name,
        );
        assert.match(
            t,
            /Your count differs from the register\. Add the reason/,
            name,
        );
        assert.match(
            flat(script(source)),
            /value === '0' \? 'no difference'/,
            name,
        );
    }
});

test('STK-40: on the Classic pages the list shows "Shown after your count" for a blind row', () => {
    for (const [name, source] of Object.entries(lists)) {
        assert.match(source, /Shown after your count/, name);
        assert.match(
            flat(source),
            /balance: string \| null; blind: boolean;/,
            name,
        );
    }
});

test('STK-40: on the Classic pages a check shows "N earlier attempts" only when there were refused tries (kept for older checks, STK-40b)', () => {
    for (const [name, source] of Object.entries(pages)) {
        const t = flat(source.slice(source.indexOf('<template>')));
        assert.match(
            t,
            /v-if="check\.attempts > 0"[^>]*data-testid="cd-earlier-attempts"/,
            name,
        );
        assert.match(
            t,
            /check\.attempts === 1 \? '1 earlier attempt before this check' : `\$\{check\.attempts\} earlier attempts before this check`/,
            name,
        );
        assert.match(flat(script(source)), /attempts: number;/, name);
        // STK-40b: the live "since the last check" line is retired; only older checks carry attempts.
        assert.doesNotMatch(
            source,
            /attemptsSinceCheck|cd-attempts-since/,
            name,
        );
    }
});
