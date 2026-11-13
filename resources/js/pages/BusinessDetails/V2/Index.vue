<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type { PrintLetterhead } from '@/types/letterhead';

// CAT-13: the same details shape the prints use, including the optional second phone.
type Details = PrintLetterhead['details'];
type OrganisationDetails = {
    legal_name: string;
    registration_type: string | null;
    registration_number: string | null;
};
type Letterhead = {
    branchId: number;
    branchName: string;
    organisation: OrganisationDetails;
    details: Details;
    logo: { url: string; mime: string; width: number; height: number } | null;
    organisationVersion: number;
    branchVersion: number;
    configured: boolean;
    footerText: string | null;
    footerVersion: number;
};
// CAT-4: read-only readiness of every active branch for invoices, receipts and the doctor's letters.
type Readiness = {
    branchId: number;
    name: string;
    isActive: boolean;
    status: 'ready' | 'needs_footer' | 'letters_blocked';
    missing: string[];
    blockedLetters: string[];
    logoState: 'none' | 'ok' | 'retired' | 'unavailable';
    detailsComplete: boolean;
    hasFooter: boolean;
};
const props = withDefaults(
    defineProps<{
        letterhead: Letterhead;
        canEdit: boolean;
        readiness?: Readiness[];
    }>(),
    { readiness: () => [] },
);
const readinessLabel = (row: Readiness) =>
    row.status === 'letters_blocked'
        ? `Letters blocked: ${row.blockedLetters.join(', ')}`
        : row.status === 'needs_footer'
          ? 'Needs footer'
          : 'Ready';
const readinessTone = (row: Readiness): 'danger' | 'warning' | 'success' =>
    row.status === 'letters_blocked'
        ? 'danger'
        : row.status === 'needs_footer'
          ? 'warning'
          : 'success';
const logoBroken = (row: Readiness) =>
    row.logoState === 'retired' || row.logoState === 'unavailable';
// A viewer without edit rights can fix only the footer; the rest needs an editor.
const canFix = (row: Readiness) =>
    props.canEdit || (row.detailsComplete && !logoBroken(row));
// Where the fix lives in the form below (active branch only).
const fixTarget = (row: Readiness) =>
    row.detailsComplete && !logoBroken(row)
        ? 'footer'
        : logoBroken(row)
          ? 'logo'
          : 'details';
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Business details' },
]);
const values = () => ({
    ...props.letterhead.details,
    ...props.letterhead.organisation,
    address_line_2: props.letterhead.details.address_line_2 ?? '',
    phone: props.letterhead.details.phone ?? '',
    phone_secondary: props.letterhead.details.phone_secondary ?? '',
    email: props.letterhead.details.email ?? '',
    registration_type: props.letterhead.organisation.registration_type ?? 'BRN',
    registration_number:
        props.letterhead.organisation.registration_number ?? '',
    expected_branch_id: props.letterhead.branchId,
    organisation_lock_version: props.letterhead.organisationVersion,
    branch_lock_version: props.letterhead.branchVersion,
    idempotency_key: crypto.randomUUID(),
    remove_logo: false,
    logo: null as File | null,
});
const form = useForm(values());
const footerValues = () => ({
    footer_text: props.letterhead.footerText ?? '',
    expected_branch_id: props.letterhead.branchId,
    lock_version: props.letterhead.footerVersion,
    idempotency_key: crypto.randomUUID(),
});
const footerForm = useForm(footerValues());
const adoptFooter = () => {
    footerForm.defaults(footerValues());
    footerForm.reset();
    footerForm.clearErrors();
};
const saveFooter = () => {
    if (blocked.value || footerForm.processing) {
        return;
    }

    footerForm.post('/business-details/footer', {
        preserveScroll: true,
        onSuccess: async () => {
            await nextTick();
            adoptFooter();
        },
    });
};
const blocked = ref(false);
const fileInput = ref<{ clear: () => void } | null>(null);
const preview = ref('');
const clearPreview = () => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = '';
    previewFailed.value = false;

    fileInput.value?.clear();
};
const adopt = () => {
    clearPreview();
    form.defaults(values());
    form.reset();
    form.clearErrors();
    blocked.value = false;
};
const chooseLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    previewFailed.value = false;
    // A new choice replaces the refused one, so its old error no longer applies.
    form.clearErrors('logo');
    preview.value = file ? URL.createObjectURL(file) : '';
    form.logo = file;
    form.remove_logo = false;
};
const removeOpen = ref(false);
const footerDirty = computed(
    () => footerForm.footer_text !== (props.letterhead.footerText ?? ''),
);
const footerFilled = computed(
    () => footerDirty.value && !(props.canEdit && changed(branchKeys)),
);
const removeLogo = () => {
    clearPreview();
    form.logo = null;
    form.remove_logo = true;
};
// CAT-10 (Daniel): the line under the logo button says what will happen on Save.
const logoStatus = computed(() =>
    form.logo
        ? `${form.logo.name} · not saved yet`
        : form.remove_logo
          ? 'Logo will be removed when you save.'
          : props.letterhead.logo
            ? 'Current logo saved.'
            : 'No file chosen',
);
// CAT-10 (Daniel): never preview a file that failed the check. If the chosen file does not load as an image, or
// the server refused it, the box falls back to the saved logo (or "No logo").
const previewFailed = ref(false);
const logoUrl = computed(
    () =>
        (previewFailed.value || form.errors.logo ? '' : preview.value) ||
        (form.remove_logo ? '' : (props.letterhead.logo?.url ?? '')),
);
const previewError = () => {
    if (preview.value) {
        previewFailed.value = true;
    }
};
const save = () => {
    if (!props.canEdit || blocked.value || form.processing) {
        return;
    }

    form.post('/business-details', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: async () => {
            await nextTick();
            adopt();
        },
    });
};
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        footerForm.footer_text = '';
        footerForm.idempotency_key = crypto.randomUUID();
        clearPreview();

        for (const field of [
            'clinic_name',
            'address_line_1',
            'address_line_2',
            'postcode',
            'city',
            'state',
            'country',
            'phone',
            'phone_secondary',
            'email',
            'legal_name',
            'registration_number',
        ] as const) {
            form[field] = '';
        }

        form.logo = null;
        form.idempotency_key = crypto.randomUUID();
    }
});
watch(() => props.letterhead.branchId, adopt);
watch(() => props.letterhead.branchId, adoptFooter);
onUnmounted(() => {
    stop();
    clearPreview();
});
const registrationTypes = [
    { value: 'BRN', label: 'Company Registration Number (BRN)' },
];
const addressFields = [
    {
        key: 'address_line_1',
        label: 'Address line 1',
        max: 255,
        required: true,
    },
    {
        key: 'address_line_2',
        label: 'Address line 2',
        max: 255,
        required: false,
    },
    { key: 'postcode', label: 'Postcode', max: 20, required: true },
    { key: 'city', label: 'City', max: 100, required: true },
    { key: 'state', label: 'State', max: 100, required: true },
    { key: 'country', label: 'Country', max: 100, required: true },
] as const;
const generalError = computed(
    () =>
        (form.errors as Record<string, string>).version ||
        (form.errors as Record<string, string>).expected_branch_id ||
        form.errors.idempotency_key,
);

// Version 2: two tabs, each saved on its own. Company holds the legal name, registration and the shared logo;
// Branches holds this branch's letterhead and print footer. The server still takes one letterhead payload and one
// footer payload, so a tab can only be saved while the other tab has nothing unsaved (switching asks first).
const tab = ref<'company' | 'branches'>('company');
const companyKeys = [
    'legal_name',
    'registration_type',
    'registration_number',
] as const;
const branchKeys = [
    'clinic_name',
    'address_line_1',
    'address_line_2',
    'postcode',
    'city',
    'state',
    'country',
    'phone',
    'phone_secondary',
    'email',
] as const;
const changed = (keys: readonly string[]) => {
    const base = values() as Record<string, unknown>;
    const now = form as unknown as Record<string, unknown>;

    return keys.some((k) => String(now[k] ?? '') !== String(base[k] ?? ''));
};
const companyDirty = computed(
    () =>
        changed(companyKeys) || form.logo !== null || form.remove_logo === true,
);
const branchDirty = computed(
    () =>
        changed(branchKeys) ||
        footerForm.footer_text !== (props.letterhead.footerText ?? ''),
);
const tabDirty = computed(() =>
    tab.value === 'company' ? companyDirty.value : branchDirty.value,
);
const guard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
// Switching tabs throws away unsaved work on the tab you leave: the shared question asks first (a clean tab switches at
// once). Discard changes resets both forms before the switch, so the next tab starts clean.
const askTab = (next: string) => {
    const target = next as 'company' | 'branches';

    if (target === tab.value) {
        return;
    }

    guard.value?.ask(() => {
        tab.value = target;
    });
};
const discardTabChanges = () => {
    adopt();
    adoptFooter();
};
// Which branch the Branches tab shows. Only the active branch can be edited; another one is shown as its readiness.
const shownBranch = ref<number | string>(props.letterhead.branchId);
const branchOptions = computed(() =>
    props.readiness.map((r) => ({
        value: r.branchId,
        label: r.isActive ? `${r.name} (this branch)` : r.name,
    })),
);
const shownRow = computed(
    () => props.readiness.find((r) => r.branchId === shownBranch.value) ?? null,
);
const isActiveBranch = computed(
    () => shownBranch.value === props.letterhead.branchId,
);
const goFix = (row: Readiness) => {
    const target = fixTarget(row);

    if (target === 'logo') {
        askTab('company');
    } else {
        askTab('branches');
    }
};
const reload = () =>
    router.get('/business-details', {}, { preserveState: false });

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const field = (invalid?: boolean) =>
    invalid
        ? inputClass.replace('border-input', 'border-destructive')
        : inputClass;
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
</script>

<template>
    <Head title="Business details" />
    <PageFrame>
        <V2WorkLayout
            title="Business details"
            :description="`Clinic name, address, contact and logo used on invoices, receipts and letters · ${letterhead.branchName}`"
        >
            <template #actions>
                <V2Chip v-if="!canEdit" tone="neutral" label="View only" />
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <p
                        v-if="generalError || blocked"
                        role="alert"
                        class="flex flex-wrap items-center gap-2 rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                    >
                        {{
                            blocked
                                ? 'The branch changed. Reload before continuing.'
                                : generalError
                        }}
                        <button
                            type="button"
                            :class="outlineButton"
                            @click="reload"
                        >
                            Reload
                        </button>
                        <span class="text-xs"
                            >Reload discards unsaved changes.</span
                        >
                    </p>

                    <V2DisclosureBar
                        :title="
                            readiness.some((r) => r.status !== 'ready')
                                ? `Document readiness · ${readiness.filter((r) => r.status !== 'ready').length} need attention`
                                : 'Document readiness · all branches ready'
                        "
                        :tone="
                            readiness.some((r) => r.status !== 'ready')
                                ? 'warning'
                                : 'neutral'
                        "
                        keep-mounted
                    >
                        <p class="mb-3 text-sm text-muted-foreground">
                            Letters: referral, MC and attendance. Invoices and
                            receipts print without a footer, but each branch
                            should have one before real use.
                        </p>
                        <p
                            v-if="readiness.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No active branches. Add or switch on a branch in
                            Branches first.
                        </p>
                        <ul v-else class="grid gap-2" aria-label="Branches">
                            <li
                                v-for="row in readiness"
                                :key="row.branchId"
                                class="grid min-w-0 gap-1 rounded-[10px] border p-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:items-center md:gap-3"
                                data-testid="readiness-row"
                            >
                                <p
                                    class="min-w-0 text-sm font-medium break-words"
                                >
                                    {{ row.name }}
                                    <span
                                        v-if="row.isActive"
                                        class="text-xs font-normal text-muted-foreground"
                                        >(this branch)</span
                                    >
                                </p>
                                <div class="grid min-w-0 gap-1">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <V2Chip
                                            :tone="readinessTone(row)"
                                            :label="readinessLabel(row)"
                                        />
                                        <V2Chip
                                            v-if="
                                                !row.hasFooter &&
                                                row.status === 'letters_blocked'
                                            "
                                            tone="warning"
                                            label="Needs footer"
                                        />
                                    </div>
                                    <p
                                        v-if="row.missing.length"
                                        class="text-xs break-words text-muted-foreground"
                                        data-testid="missing-full"
                                    >
                                        Missing: {{ row.missing.join(', ') }}
                                    </p>
                                    <p
                                        v-if="row.logoState === 'none'"
                                        class="text-xs text-muted-foreground"
                                    >
                                        No logo (optional)
                                    </p>
                                </div>
                                <div
                                    v-if="row.missing.length || !row.hasFooter"
                                    class="min-w-0 text-xs"
                                >
                                    <button
                                        v-if="row.isActive && canFix(row)"
                                        type="button"
                                        class="inline-flex h-11 items-center rounded-[10px] px-2 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                                        :aria-label="`Fix below: ${row.name}`"
                                        @click="goFix(row)"
                                    >
                                        Fix below
                                    </button>
                                    <span
                                        v-else-if="row.isActive"
                                        class="text-muted-foreground"
                                        >Ask an editor to complete these
                                        details.</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >Switch to {{ row.name }} in the header
                                        to edit.</span
                                    >
                                </div>
                            </li>
                        </ul>
                    </V2DisclosureBar>

                    <p v-if="!canEdit" class="text-sm text-muted-foreground">
                        You can view this. Only the Director and Administrator
                        can change it. The print footer can be edited
                        separately.
                    </p>

                    <V2Tabs
                        :model-value="tab"
                        ariaLabel="Business details sections"
                        :tabs="[
                            { value: 'company', label: 'Company' },
                            { value: 'branches', label: 'Branches' },
                        ]"
                        @update:model-value="askTab"
                    />

                    <V2FormCard
                        v-if="!canEdit"
                        v-show="tab === 'company'"
                        role="tabpanel"
                        title="Company"
                        description="Legal identity, registration and the shared logo."
                        data-test="company-readonly"
                    >
                        <dl class="grid gap-3 text-sm md:grid-cols-2">
                            <div class="md:col-span-2">
                                <dt class="text-muted-foreground">
                                    Legal name
                                </dt>
                                <dd class="font-medium break-words">
                                    {{ letterhead.organisation.legal_name }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Registration type
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        letterhead.organisation
                                            .registration_type ?? 'Not set'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Registration number
                                </dt>
                                <dd class="font-medium break-words">
                                    {{
                                        letterhead.organisation
                                            .registration_number || 'Not set'
                                    }}
                                </dd>
                            </div>
                            <div class="md:col-span-2">
                                <dt class="text-muted-foreground">
                                    Company logo
                                </dt>
                                <dd>
                                    <img
                                        v-if="letterhead.logo"
                                        :src="letterhead.logo.url"
                                        alt="Company logo"
                                        class="mt-1 max-h-24 max-w-40 object-contain"
                                    />
                                    <span v-else class="font-medium"
                                        >No logo</span
                                    >
                                </dd>
                            </div>
                        </dl>
                    </V2FormCard>

                    <form
                        v-if="canEdit"
                        v-show="tab === 'company'"
                        role="tabpanel"
                        aria-label="Company"
                        class="flex min-w-0 flex-col gap-4"
                        data-test="company-form"
                        novalidate
                        @submit.prevent="save"
                    >
                        <fieldset
                            :disabled="!canEdit || form.processing || blocked"
                            class="flex min-w-0 flex-col gap-4 disabled:opacity-100"
                        >
                            <V2FormCard
                                title="Organisation details"
                                description="Legal identity and registration are shared across branches."
                            >
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <V2Field
                                            v-slot="{
                                                id,
                                                describedBy,
                                                invalid,
                                            }"
                                            label="Legal name"
                                            required
                                            :error="form.errors.legal_name"
                                        >
                                            <input
                                                :id="id"
                                                v-model="form.legal_name"
                                                name="legal_name"
                                                maxlength="200"
                                                aria-required="true"
                                                :aria-describedby="describedBy"
                                                :aria-invalid="invalid"
                                                :class="field(invalid)"
                                            />
                                        </V2Field>
                                    </div>
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Registration type"
                                        :error="form.errors.registration_type"
                                    >
                                        <OperationalSelect
                                            :id="id"
                                            v-model="form.registration_type"
                                            :disabled="
                                                !canEdit ||
                                                form.processing ||
                                                blocked
                                            "
                                            label="Registration type"
                                            :describedby="describedBy"
                                            :invalid="invalid"
                                            :options="registrationTypes"
                                        />
                                    </V2Field>
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Registration number (optional)"
                                        :error="form.errors.registration_number"
                                    >
                                        <input
                                            :id="id"
                                            v-model="form.registration_number"
                                            name="registration_number"
                                            maxlength="80"
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            :class="field(invalid)"
                                        />
                                    </V2Field>
                                </div>
                            </V2FormCard>

                            <V2FormCard
                                title="Company logo"
                                description="One logo for all branches. JPG or PNG, up to 2 MB and 4096 × 4096 pixels. Preferred aspect ratio 16:9."
                            >
                                <div class="flex flex-wrap items-start gap-3">
                                    <div
                                        class="flex h-24 w-40 items-center justify-center overflow-hidden rounded-[10px] border bg-muted/30 p-2"
                                    >
                                        <img
                                            v-if="logoUrl"
                                            :src="logoUrl"
                                            alt="Company logo preview"
                                            class="max-h-full max-w-full object-contain"
                                            @error="previewError"
                                        />
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                            >No logo</span
                                        >
                                    </div>
                                    <div
                                        v-if="canEdit"
                                        class="min-w-0 flex-1 basis-40 space-y-2"
                                    >
                                        <FileChooseButton
                                            id="logo"
                                            ref="fileInput"
                                            accept="image/jpeg,image/png"
                                            :label="
                                                logoUrl
                                                    ? 'Replace logo'
                                                    : 'Choose logo'
                                            "
                                            :status="logoStatus"
                                            status-id="logo-file-name"
                                            aria-describedby="logo-error"
                                            data-testid="choose-logo"
                                            @change="chooseLogo"
                                        >
                                            <InputError
                                                id="logo-error"
                                                :message="form.errors.logo"
                                            />
                                        </FileChooseButton>
                                        <button
                                            v-if="logoUrl"
                                            type="button"
                                            :class="outlineButton"
                                            @click="removeOpen = true"
                                        >
                                            Remove logo
                                        </button>
                                    </div>
                                </div>
                            </V2FormCard>
                        </fieldset>
                        <div
                            v-if="canEdit"
                            class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:-mx-8 lg:px-8"
                            data-test="company-actions"
                        >
                            <button
                                type="button"
                                :class="[outlineButton, 'max-sm:hidden']"
                                :disabled="
                                    form.processing || blocked || !companyDirty
                                "
                                @click="adopt"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :class="[
                                    companyDirty ? filledButton : outlineButton,
                                    'max-sm:w-full',
                                ]"
                                :disabled="form.processing || blocked"
                            >
                                {{ form.processing ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </form>

                    <div
                        v-show="tab === 'branches'"
                        role="tabpanel"
                        aria-label="Branches"
                        class="flex min-w-0 flex-col gap-4"
                    >
                        <V2FormCard
                            title="Branch"
                            :description="
                                canEdit
                                    ? 'Letterhead and print footer are set per branch. You can edit the branch you are working in.'
                                    : 'Letterhead and print footer are set per branch.'
                            "
                        >
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Branch"
                            >
                                <OperationalSelect
                                    :id="id"
                                    v-model="shownBranch"
                                    label="Branch"
                                    :describedby="describedBy"
                                    :options="branchOptions"
                                />
                            </V2Field>
                            <p
                                v-if="!isActiveBranch && shownRow"
                                class="text-sm text-muted-foreground"
                                data-testid="other-branch-note"
                            >
                                {{ shownRow.name }}:
                                {{ readinessLabel(shownRow) }}. Switch to
                                {{ shownRow.name }} in the header to edit its
                                letterhead and footer.
                            </p>
                        </V2FormCard>

                        <template v-if="isActiveBranch">
                            <V2FormCard
                                v-if="!canEdit"
                                title="Clinic details and contact"
                                description="Printed in the letterhead of this branch."
                                data-test="branch-readonly"
                            >
                                <dl class="grid gap-3 text-sm md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <dt class="text-muted-foreground">
                                            Clinic name
                                        </dt>
                                        <dd class="font-medium break-words">
                                            {{ letterhead.details.clinic_name }}
                                        </dd>
                                    </div>
                                    <div class="md:col-span-2">
                                        <dt class="text-muted-foreground">
                                            Address
                                        </dt>
                                        <dd class="font-medium break-words">
                                            {{
                                                [
                                                    letterhead.details
                                                        .address_line_1,
                                                    letterhead.details
                                                        .address_line_2,
                                                    letterhead.details.postcode,
                                                    letterhead.details.city,
                                                    letterhead.details.state,
                                                    letterhead.details.country,
                                                ]
                                                    .filter(Boolean)
                                                    .join(', ')
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Phone
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                letterhead.details.phone ||
                                                'Not set'
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Second phone
                                        </dt>
                                        <dd class="font-medium">
                                            {{
                                                letterhead.details
                                                    .phone_secondary ||
                                                'Not set'
                                            }}
                                        </dd>
                                    </div>
                                    <div class="md:col-span-2">
                                        <dt class="text-muted-foreground">
                                            Clinic email
                                        </dt>
                                        <dd class="font-medium break-words">
                                            {{
                                                letterhead.details.email ||
                                                'Not set'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </V2FormCard>

                            <form
                                v-if="canEdit"
                                class="flex min-w-0 flex-col gap-4"
                                data-test="branch-form"
                                novalidate
                                @submit.prevent="save"
                            >
                                <fieldset
                                    :disabled="
                                        !canEdit || form.processing || blocked
                                    "
                                    class="flex min-w-0 flex-col gap-4 disabled:opacity-100"
                                >
                                    <V2FormCard
                                        title="Clinic details"
                                        description="Name and address printed in the letterhead."
                                    >
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <div class="md:col-span-2">
                                                <V2Field
                                                    v-slot="{
                                                        id,
                                                        describedBy,
                                                        invalid,
                                                    }"
                                                    label="Clinic name"
                                                    required
                                                    :error="
                                                        form.errors.clinic_name
                                                    "
                                                >
                                                    <input
                                                        :id="id"
                                                        v-model="
                                                            form.clinic_name
                                                        "
                                                        name="clinic_name"
                                                        maxlength="200"
                                                        aria-required="true"
                                                        :aria-describedby="
                                                            describedBy
                                                        "
                                                        :aria-invalid="invalid"
                                                        :class="field(invalid)"
                                                    />
                                                </V2Field>
                                            </div>
                                            <V2Field
                                                v-for="f in addressFields"
                                                :key="f.key"
                                                v-slot="{
                                                    id,
                                                    describedBy,
                                                    invalid,
                                                }"
                                                :label="f.label"
                                                :required="f.required"
                                                :error="form.errors[f.key]"
                                            >
                                                <input
                                                    :id="id"
                                                    v-model="form[f.key]"
                                                    :name="f.key"
                                                    :maxlength="f.max"
                                                    :aria-required="
                                                        f.required
                                                            ? 'true'
                                                            : undefined
                                                    "
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    :aria-invalid="invalid"
                                                    :class="field(invalid)"
                                                />
                                            </V2Field>
                                        </div>
                                    </V2FormCard>
                                    <V2FormCard
                                        title="Contact"
                                        description="Shown on letters and prints."
                                    >
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <V2Field
                                                v-slot="{
                                                    id,
                                                    describedBy,
                                                    invalid,
                                                }"
                                                label="Phone (optional)"
                                                hint="Include the country code for numbers outside Malaysia."
                                                :error="form.errors.phone"
                                            >
                                                <input
                                                    :id="id"
                                                    v-model="form.phone"
                                                    name="phone"
                                                    type="tel"
                                                    maxlength="40"
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    :aria-invalid="invalid"
                                                    :class="field(invalid)"
                                                />
                                            </V2Field>
                                            <V2Field
                                                v-slot="{
                                                    id,
                                                    describedBy,
                                                    invalid,
                                                }"
                                                label="Second phone (optional)"
                                                hint='For documents that show two numbers, as "Tel: A / B".'
                                                :error="
                                                    form.errors.phone_secondary
                                                "
                                            >
                                                <input
                                                    :id="id"
                                                    v-model="
                                                        form.phone_secondary
                                                    "
                                                    name="phone_secondary"
                                                    type="tel"
                                                    maxlength="40"
                                                    :aria-describedby="
                                                        describedBy
                                                    "
                                                    :aria-invalid="invalid"
                                                    :class="field(invalid)"
                                                />
                                            </V2Field>
                                            <div class="md:col-span-2">
                                                <V2Field
                                                    v-slot="{
                                                        id,
                                                        describedBy,
                                                        invalid,
                                                    }"
                                                    label="Clinic email (optional)"
                                                    :error="form.errors.email"
                                                >
                                                    <input
                                                        :id="id"
                                                        v-model="form.email"
                                                        name="email"
                                                        type="email"
                                                        maxlength="254"
                                                        :aria-describedby="
                                                            describedBy
                                                        "
                                                        :aria-invalid="invalid"
                                                        :class="field(invalid)"
                                                    />
                                                </V2Field>
                                            </div>
                                        </div>
                                    </V2FormCard>
                                </fieldset>
                                <div
                                    class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-end gap-x-3 gap-y-1 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:-mx-8 lg:px-8"
                                    data-test="branch-actions"
                                >
                                    <p
                                        class="mr-auto text-xs text-muted-foreground max-sm:w-full"
                                    >
                                        Saves the letterhead only.
                                    </p>
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        :disabled="
                                            form.processing ||
                                            blocked ||
                                            !changed(branchKeys)
                                        "
                                        @click="adopt"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        :class="
                                            changed(branchKeys)
                                                ? filledButton
                                                : outlineButton
                                        "
                                        :disabled="form.processing || blocked"
                                    >
                                        {{
                                            form.processing
                                                ? 'Saving…'
                                                : 'Save letterhead'
                                        }}
                                    </button>
                                </div>
                            </form>

                            <form
                                data-test="footer-form"
                                novalidate
                                @submit.prevent="saveFooter"
                            >
                                <V2FormCard
                                    title="Branch print footer"
                                    description="Optional plain text for this branch’s invoice and receipt print views. Maximum 1,000 characters; no HTML. Settings editors can save this footer without changing clinic details or the logo."
                                >
                                    <V2Field
                                        v-slot="{ id, describedBy, invalid }"
                                        label="Print footer (optional)"
                                        :error="footerForm.errors.footer_text"
                                    >
                                        <textarea
                                            :id="id"
                                            v-model="footerForm.footer_text"
                                            rows="3"
                                            maxlength="1000"
                                            :disabled="
                                                blocked || footerForm.processing
                                            "
                                            :aria-describedby="describedBy"
                                            :aria-invalid="invalid"
                                            class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                        ></textarea>
                                    </V2Field>
                                    <p
                                        v-if="
                                            footerForm.errors.lock_version ||
                                            footerForm.errors.idempotency_key ||
                                            footerForm.errors.expected_branch_id
                                        "
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{
                                            footerForm.errors.lock_version ||
                                            footerForm.errors.idempotency_key ||
                                            footerForm.errors.expected_branch_id
                                        }}
                                        <button
                                            type="button"
                                            class="underline"
                                            @click="reload"
                                        >
                                            Reload
                                        </button>
                                    </p>
                                    <div
                                        class="flex flex-wrap items-center justify-end gap-3"
                                    >
                                        <p
                                            class="mr-auto text-xs text-muted-foreground max-sm:w-full"
                                        >
                                            Saves the print footer only.
                                        </p>
                                        <button
                                            type="submit"
                                            :class="[
                                                footerFilled
                                                    ? filledButton
                                                    : outlineButton,
                                                'max-sm:w-full',
                                            ]"
                                            :disabled="
                                                blocked || footerForm.processing
                                            "
                                        >
                                            {{
                                                footerForm.processing
                                                    ? 'Saving…'
                                                    : 'Save print footer'
                                            }}
                                        </button>
                                    </div>
                                </V2FormCard>
                            </form>
                        </template>
                    </div>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="removeOpen"
            title="Remove the company logo?"
            description="The logo is shared by every branch, so this removes it from the letters and prints of all branches. It is removed when you press Save."
            confirm-label="Remove logo"
            cancel-label="Keep logo"
            tone="danger"
            @confirm="
                removeLogo();
                removeOpen = false;
            "
        />

        <V2LeaveGuard
            ref="guard"
            :dirty="tabDirty"
            @discard="discardTabChanges"
        />
    </PageFrame>
</template>
