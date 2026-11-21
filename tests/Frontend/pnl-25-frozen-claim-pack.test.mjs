import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-25: a frozen Panel claim pack shows its label (ORIGINAL, COPY or REVISED), the claim page lists the issued packs,
// and the live page is a preview. Source checks, as for the other claim pages.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const pack = read('resources/js/pages/PanelClaims/Pack.vue');
const label = read('resources/js/components/panel/PackIssuedLabel.vue');
const list = read('resources/js/components/panel/IssuedPacksList.vue');
const classicShow = read('resources/js/pages/PanelClaims/Show.vue');

test('the label says ORIGINAL, COPY or REVISED (version n), and the live page says PREVIEW', () => {
    assert.match(label, /'ORIGINAL'/);
    assert.match(label, /`REVISED \(version \$\{props\.issued\.version\}\)`/);
    assert.match(label, /'COPY'/);
    assert.match(label, /PREVIEW — not an issued copy/);
    assert.match(label, /data-testid="pack-issued-label"/);
    assert.match(label, /data-testid="pack-preview-label"/);
    // Plain black on white: no colour classes, so it reads on paper and in dark mode alike.
    assert.ok(
        !/text-(?:red|green|amber|pink|blue)|bg-(?:red|green|amber|pink|blue)/.test(
            label,
        ),
    );
});

test('the pack page shows the label on the cover and on every claim sheet and uses the frozen print time', () => {
    assert.equal([...pack.matchAll(/<PackIssuedLabel/g)].length, 2);
    assert.match(pack, /pack\.printedAt \?\? issued\?\.printedAt/);
    assert.match(pack, /printedAt\?: string;/);
    assert.match(pack, /data-testid="pack-issued-line"/);
    assert.match(pack, /This page is a preview, not an issued copy/);
});

test('the issued packs list has outline View copy and Reprint buttons at 44 px and one request key per press', () => {
    assert.match(list, /View copy/);
    assert.match(list, /Reprint/);
    assert.match(list, /min-h-11/);
    assert.ok(!/bg-primary/.test(list), 'no filled button in the list');
    assert.match(list, /crypto\.randomUUID\(\)/);
    assert.match(list, /router\.post\(/);
    assert.match(list, /:aria-label="`Reprint version \$\{pack\.version\}`"/);
    assert.match(list, /busy\.value !== null/);
});

test('the claim page lists the issued packs', () => {
    assert.match(classicShow, /<IssuedPacksList/);
    assert.match(classicShow, /:from-claim="claim\.publicId"/);

    assert.match(classicShow, /issuedPacks\?: IssuedPackRow\[\] \| null/);
});
