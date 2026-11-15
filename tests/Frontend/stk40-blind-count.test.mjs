import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const flat = (s) => s.replace(/\s+/g, ' ');
const pages = {
    classic: read('resources/js/pages/Inventory/ControlledRegisterShow.vue'),
    v2: read('resources/js/pages/Inventory/V2/ControlledRegisterShow.vue'),
};
const lists = {
    classic: read('resources/js/pages/Inventory/ControlledRegister.vue'),
    v2: read('resources/js/pages/Inventory/V2/ControlledRegister.vue'),
};
const script = (s) => s.slice(0, s.indexOf('</script>'));

test('STK-40: in both layouts a blind checker relies on the server to say the count differs', () => {
    for (const [name, source] of Object.entries(pages)) {
        const s = flat(script(source));
        assert.match(s, /const toldDiffers = ref\(false\);/, name);
        assert.match(
            s,
            /if \(props\.register\.blind\) \{ return toldDiffers\.value; \}/,
            name,
        );
        assert.match(
            s,
            /const ledger = milli\(props\.register\.balanceNow \?\? ''\);/,
            name,
        );
        assert.match(
            s,
            /if \(bag\.reason && props\.register\.blind\) \{ toldDiffers\.value = true; \}/,
            name,
        );
        assert.match(
            s,
            /watch\( \(\) => form\.counted, \(\) => \{ toldDiffers\.value = false; \}, \);/,
            name,
        );
        assert.match(s, /balanceNow: string \| null; blind: boolean;/, name);
    }
});

test('STK-40: in both layouts the page says the balance comes after the count, with no print until then', () => {
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
        assert.match(
            t,
            /difference \{\{ difference\(check\.difference\) \}\}/,
            name,
        );
    }
});

test('STK-40: in both layouts the list shows "Shown after your count" for a blind row', () => {
    for (const [name, source] of Object.entries(lists)) {
        assert.match(source, /Shown after your count/, name);
        assert.match(
            flat(source),
            /balance: string \| null; blind: boolean;/,
            name,
        );
    }
});
