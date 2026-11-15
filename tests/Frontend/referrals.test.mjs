import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = (p) => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Referrals/Index.vue');

test('referral composer compiles and uses escaped selections, bounded text and explicit consent', () => {
    const { descriptor } = parse(page);
    assert.ok(compileScript(descriptor, { id: 'referral' }));
    assert.deepEqual(compileTemplate({ id: 'referral', filename: 'Referral.vue', source: descriptor.template.content }).errors, []);
    assert.doesNotMatch(page, /v-html|localStorage|sessionStorage|type="file"/);
    assert.match(page, /Only SAVED source data/);
    assert.match(page, /form\.consent/);
    assert.match(page.replace(/\s+/g, ' '), /written-consent or guardian procedure/);
    assert.ok(page.replace(/\s+/g, ' ').includes("A tick does not prove the clinic's written-consent or guardian procedure was followed."));
    assert.match(page, /review_token/);
    assert.match(page, /preview\.value = null/);
    assert.match(page, /lg:grid-cols-2/);
});

test('referral branch changes cancel requests and remove drafts, selections, previews and loaded history', () => {
    assert.match(page, /generation\+\+/);
    assert.match(page, /controller\?\.abort/);
    assert.match(page, /source\.value = null/);
    assert.match(page, /Object\.assign\(form, blank\(\)\)/);
    assert.match(page, /ownGeneration !== generation \|\| branchChanged\.value/);
    assert.match(page, /v-if="!branchChanged"[\s\S]*aria-label="Referral history"/);
    assert.match(page, /referrals\.canPrint/);
    assert.match(page, /referrals\.canIssue/);
    assert.match(read('resources/js/pages/Clinical/Show.vue'), /referrals\.view\.own[\s\S]*target="_blank"[\s\S]*Referral letters/);
});

test('referral print retains A4 white dark-text pages with repeat identity, references and page numbers', () => {
    const content = read('resources/views/referrals/content.blade.php');
    assert.match(content, /size:A4 portrait/);
    assert.match(content, /background:#fff!important/);
    assert.match(content, /color:#18181b!important/);
    assert.match(content, /@foreach\(\$snapshot\['pages'\]/);
    assert.match(content, /patient'\]\['number/);
    assert.match(content, /Page \/ Halaman/);
    assert.match(content, /DRAFT WORDING/);
    assert.match(content, /@if\(\$loop->last\)[\s\S]*class="ref-sign"[\s\S]*@endif/);
    assert.match(content, /<style>[\s\S]*\.ref-page\{[^}]*background:#fff[^}]*font:11pt/);
    assert.match(content, /@media\(max-width:760px\)/);
    assert.match(content, /\.ref-lines\{font:inherit;/);
    assert.match(content, /font:11pt\/1\.35 Arial,sans-serif/);
    assert.doesNotMatch(content, /monospace/);
    assert.doesNotMatch(content, /{!!|<script|overflow:hidden|text-overflow/);
    assert.match(read('resources/views/referrals/show.blade.php'), /COPY — \{\{ \$status/);
});
