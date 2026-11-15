import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Version 2 Create claim page (PanelClaims/V2/Create.vue): the compare with Classic. Classic stays untouched; the
// Version 2 page uses a copy of its script logic: the same form, request, photo checks and rules.

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/PanelClaims/Create.vue');
const composable = read(
    'resources/js/composables/panel-claims/usePanelClaimCreate.ts',
);
const page = read('resources/js/pages/PanelClaims/V2/Create.vue');
const config = read('config/v2-pages.php');

const flat = (text) => text.replace(/[\s,()]/g, '');
const classicScript = classic.slice(
    classic.indexOf('const form = useForm({'),
    classic.indexOf('</script>'),
);

test('Classic is untouched: no Version 2 imports and no composable', () => {
    assert.ok(!classic.includes('components/v2'));
    assert.ok(!classic.includes('usePanelClaimCreate'));
    assert.ok(!classic.includes('V2'));
});

test('the composable is a verbatim copy of the Classic script', () => {
    assert.ok(flat(composable).includes(flat(classicScript)));
});

test('the same form, request and photo checks as Classic', () => {
    for (const text of [
        'expected_branch_id',
        'allocation_lock_version',
        'invoice_lock_version',
        'idempotency_key',
        'crypto.randomUUID()',
        'submitted_date',
        'remarks',
        'photos',
        '/panel-claims/allocations/',
        'forceFormData: true',
        'selected.length > 10',
        'file.size > 5 * 1024 * 1024',
        'Select up to 10 JPG or PNG photos, at most 5 MB each.',
    ]) {
        assert.ok(classic.includes(text), `Classic has ${text}`);
        assert.ok(composable.includes(text), `composable has ${text}`);
    }

    // The page never posts by itself: the request is the copied createClaim.
    assert.ok(!/router\.|form\.post|fetch\(/.test(page));
    assert.match(page, /createClaim\(\)/);
});

test('the Classic wording the Version 2 page keeps', () => {
    for (const text of [
        'Create panel claim',
        'Cancel',
        'Create claim',
        'Invoice details',
        'Below are the details of the selected invoice.',
        'Patient details',
        'Visit date and time',
        'Treatment items',
        'Item name',
        'Rate',
        'Qty',
        'Total',
        'Original subtotal',
        'Invoice adjustment',
        'Adjusted total',
        'Panel claim details',
        'Key information related to the submitted panel',
        'Submitted date',
        'Remarks',
        'Upload photo',
        'Drop photos here or choose files.',
        'Invoice amount breakdown',
    ]) {
        const wanted = flat(text);
        assert.ok(flat(classic).includes(wanted), `Classic has: ${text}`);
        assert.ok(flat(page + composable).includes(wanted), `V2 has: ${text}`);
    }
});

test('the page is registered and the layout rules hold', () => {
    assert.match(config, /'PanelClaims\/Create'/);
    // One filled button: the header one from 640 px, the sticky bar below; the count of bg-primary classes is two.
    assert.equal([...page.matchAll(/bg-primary(?!\/)/g)].length, 2);
    assert.match(page, /max-sm:hidden/);
    assert.match(page, /<V2StickyBar hide-from="sm"/);
    assert.match(page, /form="v2-create-claim-form"/);
    assert.match(page, /rail-from="2xl"/);
    assert.match(page, /<V2LeaveGuard/);
    assert.match(page, /<V2DateField/);
    assert.match(page, /:min="allocation\.visitDate"/);
    assert.match(page, /:max="branch\.today"/);
    assert.match(page, /FileChooseButton/);
    assert.ok(!/<select\b/.test(page));
    assert.ok(!/type="file"/.test(page));
    assert.match(page, /title: 'Panel claims', href: '\/panel-claims'/);
});

test('Daniel: live sentence, due rule line, above-the-invoice wording, phone order, no double submit', () => {
    assert.match(page, /Records a Submitted claim of/);
    assert.match(
        page,
        /You submit it on the Panel's portal yourself; KPOne sends nothing\./,
    );
    assert.match(page, /This Panel's rule: /);
    assert.match(page, /Above the invoice by /);
    assert.ok(!page.includes('Claim-only difference'));
    // Phone order: Invoice details, claim details, then Treatment items behind a closed bar; from 1280 the Classic columns.
    assert.match(page, /`Treatment items \(\$\{allocation\.lines\.length\}\)`/);
    assert.match(page, /class="xl:hidden"/);
    assert.match(page, /xl:grid-cols-\[minmax\(0,2fr\)_minmax\(0,1fr\)\]/);
    // An unreadable or empty date sends nothing; a second press while posting does nothing.
    assert.match(page, /dateInvalid\.value \|\| form\.submitted_date === ''/);
    assert.match(page, /if \(form\.processing\) \{\s*return;/);
    // The Remove button names the file.
    assert.match(page, /:aria-label="`Remove \$\{file\.name\}`"/);
    // No role-based show or hide: nothing in the page reads a permission.
    assert.ok(!/permissions|\.can\(|canEdit/.test(page));
});
