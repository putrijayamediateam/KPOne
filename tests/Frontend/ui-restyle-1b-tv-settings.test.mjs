import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// UI-RESTYLE 1b (Queue & TV, owner-authorised 2026-11-12): the rest of the Queue Display settings page. Layout only.
const path = 'resources/js/pages/QueueDisplay/Settings.vue';
const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = parse(source).descriptor.template.content;

test('the page compiles', () => {
    assert.deepEqual(
        compileTemplate({ id: 'tv-settings', source: template, filename: path })
            .errors,
        [],
    );
});

test('the page is centred at 1280 px and its section titles are card titles', () => {
    assert.match(template, /max-w-7xl/);
    assert.doesNotMatch(template, /<h2 class="text-sm font-semibold">/);
    assert.equal(
        (template.match(/<h2 class="text-base font-semibold">/g) ?? [])
            .length >= 3,
        true,
    );
});

test('fields and buttons are 44 px below 1024 px', () => {
    for (const marker of ['Branch', 'data-testid="call-display-mode"']) {
        const from = template.indexOf(marker);
        assert.match(
            template.slice(from - 300, from + 120),
            /h-11[^"]*lg:h-9/,
            marker,
        );
    }

    const content = template.slice(
        template.indexOf('Screen content'),
        template.indexOf('Posters ('),
    );
    assert.equal((content.match(/max-lg:h-11/g) ?? []).length, 3);
    assert.match(content, /class="max-lg:min-h-11"/);
});

test('the poster picker is the shared file button, so a long file name cannot widen the page', () => {
    assert.match(
        template,
        /<FileChooseButton[\s\S]{0,200}accept="image\/jpeg,image\/png,image\/webp"/,
    );
    assert.doesNotMatch(template, /type="file"/);
    // The shared button is 36 px from 640 px; below 1024 px this page makes it 44 px.
    assert.match(template, /label="Choose poster"\s+class="max-lg:min-h-11!"/);
    assert.match(template, /Upload poster/);
    assert.match(source, /posterInput\.value\?\.clear\(\)/);
    // Removing a poster is unchanged: the same button, the same words, the same request.
    assert.match(template, /@click="removePoster\(poster\.id\)"\s*>Remove</);
});

test('Seconds per poster is as wide as the other fields below 1024 px and a deliberate short field from there', () => {
    assert.match(
        template,
        /<label class="grid gap-1 text-xs lg:max-w-xs">\s+Seconds per poster/,
    );
});
