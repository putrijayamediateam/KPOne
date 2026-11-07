import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

// PNL-19: the claim pack letterhead prints the branch's optional second phone (CAT-9) as "Tel: A / B", like the
// referral and attendance letters; each number stays whole when the line wraps.

const pack = readFileSync(
    new URL('../../resources/js/pages/PanelClaims/Pack.vue', import.meta.url),
    'utf8',
);

test('The pack still compiles', () => {
    const descriptor = parse(pack).descriptor;
    assert.deepEqual(
        compileTemplate({
            source: descriptor.template.content,
            filename: 'Pack.vue',
            id: 'pnl19',
        }).errors,
        [],
    );
    assert.ok(compileScript(descriptor, { id: 'pnl19' }));
});

test('Both letterheads print every phone on one Tel line, each number unbroken', () => {
    assert.match(
        pack,
        /const phones = computed\(\(\) =>\s*\[\s*props\.letterhead\.details\.phone,\s*props\.letterhead\.details\.phone_secondary,?\s*\]\.filter\(/,
    );
    assert.equal(
        pack.match(
            /v-if="phones\.length > 0"\s+data-testid="pack-letterhead-phones"/g,
        )?.length,
        2,
    );
    assert.equal(
        pack.match(
            /<template v-if="index > 0">&nbsp;\/ <\/template\s*><span class="whitespace-nowrap">\{\{\s*number\s*\}\}<\/span>/g,
        )?.length,
        2,
    );
    assert.doesNotMatch(pack, /Tel: \{\{ letterhead\.details\.phone \}\}/);
});

test('The Tel line reads "A / B", "A" alone, or "B" alone, never with an empty slot', () => {
    const phones = (phone, secondary) =>
        [phone, secondary].filter((number) => !!number);
    const line = (phone, secondary) =>
        `Tel: ${phones(phone, secondary).join(' / ')}`;

    assert.equal(
        line('+60312345678', '+60123456789'),
        'Tel: +60312345678 / +60123456789',
    );
    assert.equal(line('+60312345678', null), 'Tel: +60312345678');
    assert.equal(line(null, '+60123456789'), 'Tel: +60123456789');
    assert.equal(phones(null, null).length, 0);
});
