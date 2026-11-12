import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-5 (B.E.R.N.I.S.S for the owner, 2026-11-06): settings screens tidy-up, with Daniel's design review pinned.
// Layout, labels and accessibility only.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
// Whitespace is collapsed so the checks do not depend on how Prettier wraps the markup.
const templateOf = (source) =>
    source.slice(source.indexOf('<template>')).replace(/\s+/g, ' ');
const business = read('resources/js/pages/BusinessDetails/Index.vue');
const businessTemplate = templateOf(business);
const medicine = templateOf(
    read('resources/js/pages/InventoryServicesSettings/MedicineDetails.vue'),
);
const presets = templateOf(
    read('resources/js/pages/ConsultationSettings/Index.vue'),
);

test('Business details uses PageHeader with the back link as a small ghost button in its actions', () => {
    assert.equal((businessTemplate.match(/<PageHeader/g) ?? []).length, 1);
    assert.doesNotMatch(businessTemplate, /<h1/);
    const header = businessTemplate.slice(
        businessTemplate.indexOf('<PageHeader'),
        businessTemplate.indexOf('</PageHeader>'),
    );
    assert.match(
        header,
        /<template #actions>[\s\S]*<Button variant="ghost" size="sm" as-child>\s*<Link href="\/clinic-management">← Clinic management<\/Link>/,
    );
    assert.match(
        header,
        /<StatusBadge\s+v-if="!canEdit"\s+status="view_only"\s+label="View only"\s+tone="neutral"/,
    );
    assert.match(businessTemplate, /max-w-4xl/);
    assert.doesNotMatch(businessTemplate, /max-w-\[1440px\]/);
});

test('Business details fields use the shared components, and every starred field is required for screen readers', () => {
    assert.doesNotMatch(business, /biz-input|biz-label/);
    assert.doesNotMatch(businessTemplate, /<input(?![^>]*type="file")/);
    assert.doesNotMatch(businessTemplate, /<select|<textarea|<label/);
    assert.match(businessTemplate, /\* Required/);

    for (const id of ['clinic_name', 'legal_name']) {
        assert.match(
            businessTemplate,
            new RegExp(
                `id="${id}"\\s+v-model="form\\.${id}"\\s+aria-required="true"`,
            ),
        );
    }

    assert.match(
        businessTemplate,
        /:aria-required="\s*field\.required \? 'true' : undefined\s*"/,
    );
    // The "*" is visual only; screen readers get aria-required instead.
    assert.match(
        businessTemplate,
        /<span v-if="field\.required" aria-hidden="true"\s*> \*<\/span\s*>/,
    );
    assert.equal(
        (
            businessTemplate.match(
                /<span aria-hidden="true"> \*<\/span\s*>/g,
            ) ?? []
        ).length,
        2,
    );
    // View-only: the select is disabled itself, and disabled values stay readable.
    assert.match(
        businessTemplate,
        /id="registration_type" v-model="form\.registration_type" :disabled="\s*!canEdit \|\| form\.processing \|\| blocked\s*"/,
    );
    assert.match(
        business,
        /\[data-slot='select-trigger'\]:disabled\) \{\s*color: var\(--foreground\);\s*opacity: 1;/,
    );
    assert.match(
        businessTemplate,
        /<OperationalSelect\s+id="registration_type"[\s\S]*labelledby="registration_type-label"/,
    );
    assert.match(businessTemplate, /<Textarea\s+id="footer_text"/);
    // Phones: the form grids are one shrinkable column.
    assert.equal(
        (businessTemplate.match(/class="grid grid-cols-1 gap/g) ?? []).length,
        2,
    );
});

for (const [name, page, label] of [
    ['Medicine details', medicine, 'Medicine details options'],
    ['Preset diagnoses', presets, 'Preset diagnoses'],
]) {
    test(`${name}: OperationalTable from 768 px and cards on a phone, in sentence case`, () => {
        assert.match(
            page,
            new RegExp(
                `<OperationalTable\\s+label="${label}"\\s+class="\\[&_table\\]:table-fixed"`,
            ),
        );
        assert.doesNotMatch(page, /<table/);
        assert.match(page, /<th>Name<\/th>/);
        assert.match(page, /<th class="w-44">Status<\/th>/);
        assert.match(page, /<th class="w-24 text-right">Actions<\/th>/);
        assert.match(
            page,
            /class="mt-4 grid grid-cols-1 gap-3 lg:hidden"\s+:?aria-label="/,
        );
        assert.match(page, /<div class="mt-4 hidden min-w-0 lg:block">/);
        assert.match(page, /<EmptyState v-if="/);
    });

    test(`${name}: the switch keeps a visible track in dark mode and a status word`, () => {
        assert.doesNotMatch(page, /bg-emerald-500/);
        assert.equal(
            (
                page.match(
                    /\.active \? 'bg-success' : 'bg-muted-foreground\/40'/g,
                ) ?? []
            ).length,
            2,
        );
        assert.equal((page.match(/ring-1 ring-border/g) ?? []).length, 2);
        assert.equal(
            (page.match(/\.active \? 'Active' : 'Archived'/g) ?? []).length,
            2,
        );
    });

    test(`${name}: the row menu is a named icon button, 36 px on desktop and 44 px on a phone`, () => {
        assert.doesNotMatch(page, /•••/);
        assert.equal(
            (page.match(/:aria-label="`More actions for \$\{/g) ?? []).length,
            2,
        );
        assert.match(page, /class="min-h-11 min-w-11 shrink-0"/);
        assert.match(page, /class="size-9"/);
        assert.equal(
            (
                page.match(
                    /<Ellipsis class="size-4" aria-hidden="true" \/>/g,
                ) ?? []
            ).length,
            2,
        );
    });

    test(`${name}: tabs are 44 px tall and search has a visible label on a phone`, () => {
        assert.match(page, /'min-h-11 (shrink-0 )?border-b-2 px-5 py-3/);
        assert.match(page, /class="text-xs font-medium md:sr-only"/);
    });
}

test('Clinical Service: the publication branch select is connected to its visible label', () => {
    const service = read('resources/js/pages/ClinicalService/Index.vue');
    assert.match(
        service,
        /<span id="service-publication-branch-label"\s*>Branch used for price publication<\/span/,
    );
    assert.match(
        service,
        /id="service-publication-branch"[\s\S]*?labelledby="service-publication-branch-label"/,
    );
});

test('Daniel (CAT-5 review): Medicine details tabs fade at the right edge and bring the current or focused tab into view', () => {
    const source = read(
        'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
    );
    assert.match(medicine, /ref="tabStrip"/);
    assert.match(
        source,
        /useScrollStrip\(\s*tabStrip,\s*'\[aria-current="page"\]',?\s*\)/,
    );
    assert.match(
        source,
        /import \{ useScrollStrip \} from '@\/composables\/useScrollStrip'/,
    );
    assert.match(medicine, /:style="fadeStyle"/);
    assert.match(medicine, /@scroll\.passive="measure"/);
    assert.match(medicine, /@focusin="revealFocusedTab"/);
});

test('Daniel (CAT-5 review): empty states name the tab and the next step; wording and search names', () => {
    const source = read(
        'resources/js/pages/InventoryServicesSettings/MedicineDetails.vue',
    );
    assert.match(source, /`No \$\{plural\.value\} yet`/);
    assert.match(source, /'Add one with Add new\.'/);
    assert.match(
        read('resources/js/pages/ConsultationSettings/Index.vue'),
        /'No preset diagnoses yet'[\s\S]*'Add one with Add diagnosis\.'/,
    );

    for (const page of [medicine, presets]) {
        assert.doesNotMatch(page, /No options found|No preset diagnoses found/);
    }

    assert.match(
        medicine,
        /Set up your drug groups, dosage units and instructions\./,
    );
    assert.doesNotMatch(medicine, /Setup your|Search options/);
    assert.equal(
        (
            medicine.match(
                /:aria-label="`Search \$\{plural\}`" class="min-h-11 min-w-11 lg:min-h-8 lg:min-w-8"/g,
            ) ?? []
        ).length,
        2,
    );
    assert.equal(
        (
            presets.match(
                /aria-label="Search diagnoses" class="min-h-11 min-w-11 lg:min-h-8 lg:min-w-8"/g,
            ) ?? []
        ).length,
        2,
    );
});
