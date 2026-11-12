import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/pages/Registration/Create.vue', import.meta.url), 'utf8');
const template = parse(source).descriptor.template.content;

// RESTYLE-7: the action bar is the shared StickyActionBar. It takes its own space at the end of the page, so it never
// overlaps the visit fields at any width (the earlier top-sticky phone row is gone).
test('the Register Visit action sits in the shared sticky bar after the page and still submits the visit form', () => {
    const form = template.match(/<form\b[^>]*v-if="visitReady"[^>]*>/)?.[0];
    assert.ok(form, 'visit fields and submit action retain their existing form');
    assert.match(form, /id="register-visit-form"/);
    assert.match(form, /@submit\.prevent="submit"/);

    assert.match(template, /<StickyActionBar v-if="visitReady"/);
    assert.ok(!/class="sticky bottom-3/.test(template), 'no hand-built sticky card remains');

    const button = template.match(/<Button\s+type="submit"[\s\S]*?>/)?.[0] ?? '';
    assert.match(button, /form="register-visit-form"/);
    assert.match(button, /size="lg"/);
    assert.match(button, /:disabled="form\.processing"/);

    // The bar comes after the page content, not inside the form column.
    assert.ok(template.indexOf('<StickyActionBar') > template.indexOf('</form>'));
});
