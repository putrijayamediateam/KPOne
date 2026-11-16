import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ClinicianCredentials/Index.vue');
const page = read('resources/js/pages/ClinicianCredentials/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');
const between = (s, from, to) => s.slice(s.indexOf(from), s.indexOf(to));

test('V2 keeps the Classic form, retry identity, branch watcher and save logic exactly', () => {
    for (const [from, to] of [
        ['type Clinician = {', 'const page = usePage();'],
        ['const page = usePage();', 'const open = ref(false);'],
        ['const form = useForm({', 'const clear = () => {'],
        ['const clear = () => {', 'const edit = (clinician'],
        [
            'const edit = (clinician',
            'watch(\n    () => [form.professional_name',
        ],
        [
            'watch(\n    () => [form.professional_name',
            'onBeforeUnmount(clear);',
        ],
        ['const save = () => {', '</script>'],
    ]) {
        const a = tight(between(classic, from, to));
        assert.ok(a.length > 30, `classic chunk ${from}`);
        assert.ok(
            tight(page).includes(a),
            `V2 differs from Classic in: ${from}`,
        );
    }

    assert.ok(tight(page).includes('onBeforeUnmount(clear);'));
});

// The sheet title is now "Review credentials for <name>" in V2 (Lead UI/UX Designer's review); Classic keeps its own title.
test('V2 keeps the Classic props, the self-edit and inactive rules and every wording string', () => {
    const p = flat(page);

    assert.ok(
        p.includes(
            'canManage && clinician.active && clinician.userId !== page.props.auth.user?.id',
        ),
    );

    for (const text of [
        'Clinician credentials',
        'My clinician credentials',
        'Professional identity for future Medical Certificates. Issuing certificates is not available. Verification is a manual check, not an automated MMC or current APC validation.',
        'Credentials saved.',
        'Branch changed. Reload to review credentials.',
        'Review credentials',
        'Registered professional name',
        'MMC registration number',
        'Not configured',
        'Manually verified',
        'Not verified',
        'Inactive account',
        'No eligible clinicians in this organisation.',
        'Previous',
        'Next',
        'This does not grant clinical permissions or authorise MC issuance.',
        'I have checked the professional name and MMC registration number. Uncheck to mark these credentials unverified.',
        'Reload latest credentials',
        'Save credentials',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }
});

test('V2 form fields keep their bounds, the shared Checkbox, and Save says in words why it is off', () => {
    const p = flat(page);

    assert.ok(p.includes('maxlength="200"') && p.includes('maxlength="30"'));
    assert.ok(
        p.includes('inputmode="numeric"') &&
            p.includes('pattern="[0-9]{1,30}"'),
    );
    assert.ok(p.includes('v-model="form.verified"'));
    assert.ok(!/v-model:checked/.test(page));
    assert.ok(
        p.includes('Enter the registered professional name to continue.'),
    );
    assert.ok(
        p.includes(
            'Enter the MMC registration number (digits only) to continue.',
        ),
    );
    assert.ok(p.includes(':confirm-disabled="!!saveReason || branchChanged"'));
    assert.ok(p.includes(':busy="form.processing"'));
    // An unsaved edit asks before the sheet closes; Enter in a field cannot bypass the disabled reason.
    assert.ok(p.includes(':dirty="changed"'));
    assert.ok(p.includes('@submit.prevent="!saveReason && save()"'));
});

test('V2 adds no request of its own and no unsafe HTML, storage or issuing control', () => {
    assert.ok(
        !/v-html|type="file"|window\.print|localStorage|sessionStorage|fetch\(/.test(
            page,
        ),
    );
    assert.equal((page.match(/form\.post\(/g) ?? []).length, 1);
    assert.ok(
        tight(page).includes('/clinician-credentials/${subject.value.userId}'),
    );
});

test("V2 actions are 44 px targets and the page has one filled button (the sheet's Save)", () => {
    const t = tight(page);

    assert.ok(t.includes('h-11'));
    assert.ok(!/:class="primaryButton"/.test(page));
});

test('V2 review change 1: the sheet opens with the cursor at the end of the name and nothing selected', () => {
    const t = tight(page);

    assert.ok(t.includes('letcollapseOnFocus=false;'));
    assert.ok(t.includes('watch(open,(value)=>(collapseOnFocus=value));'));
    assert.ok(
        t.includes(
            'input.setSelectionRange(input.value.length,input.value.length);',
        ),
    );
    assert.ok(t.includes('collapseOnFocus=false;input.setSelectionRange'));
    assert.ok(flat(page).includes('@focus="placeCursorAtEnd"'));
    assert.ok(!/\.select\(\)/.test(page));
});

test('V2 review change 2: Save says "Change a field to continue." until something changes, and a note beside it says what verifying does', () => {
    const p = flat(page);

    assert.ok(
        p.includes(
            "if (!changed.value) { return 'Change a field to continue.'; }",
        ),
    );
    // The invalid-field reasons come first, then the unchanged reason.
    assert.ok(
        p.indexOf('Enter the MMC registration number') <
            p.indexOf('Change a field to continue.'),
    );
    assert.ok(p.includes('<template #footer-note>'));
    assert.ok(
        p.includes(
            'Marks these credentials as manually verified. This does not grant clinical permissions or authorise MC issuance.',
        ),
    );
    assert.ok(
        p.includes(
            'Marks these credentials as not verified. This does not grant clinical permissions or authorise MC issuance.',
        ),
    );
    assert.ok(p.includes('form.verified ?'));
});

test('V2 review change 3: the sheet and every list button name the person', () => {
    const p = flat(page);

    assert.ok(
        p.includes(
            ':title="`Review credentials for ${subject?.accountName ?? \'\'}`"',
        ),
    );
    assert.ok(
        p.includes(
            ':aria-label="`Review credentials for ${clinician.accountName}`"',
        ),
    );
    // The visible words stay "Review credentials", so the accessible name starts with them.
    assert.ok(p.includes('> Review credentials </button>'));
    assert.ok(!p.includes('title="Review clinician credentials"'));
});

test("V2 review change 4: a manager's own row says in words why it cannot be verified", () => {
    const p = flat(page);

    assert.ok(p.includes('data-testid="own-row-note"'));
    assert.ok(p.includes('You cannot verify your own credentials.'));
    assert.ok(p.includes('clinician.userId === page.props.auth.user?.id'));
    // Classic keeps its own sheet title and has no such note.
    assert.ok(classic.includes('Review clinician credentials'));
    assert.ok(!classic.includes('You cannot verify your own credentials.'));
});
