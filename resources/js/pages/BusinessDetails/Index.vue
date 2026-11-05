<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DisclosureText } from '@/components/ui/disclosure';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';

type Details = {
    clinic_name: string;
    address_line_1: string;
    address_line_2: string | null;
    postcode: string;
    city: string;
    state: string;
    country: string;
    phone: string | null;
    email: string | null;
};
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
    hasLogo: boolean;
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
    row.missing.some((item) => item.startsWith('logo'));
const shownMissing = (row: Readiness) => row.missing.slice(0, 2);
const hiddenMissing = (row: Readiness) => row.missing.slice(2);
// Where the fix lives in the form below (active branch only).
const fixTarget = (row: Readiness) =>
    row.missing.length === 1 && row.missing[0] === 'print footer'
        ? '#print-footer-heading'
        : logoBroken(row)
          ? '#logo'
          : '#clinic-details-heading';
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Business details', href: '/business-details' }],
    },
});
const values = () => ({
    ...props.letterhead.details,
    ...props.letterhead.organisation,
    address_line_2: props.letterhead.details.address_line_2 ?? '',
    phone: props.letterhead.details.phone ?? '',
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
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref('');
const clearPreview = () => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = '';

    if (fileInput.value) {
        fileInput.value.value = '';
    }
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

    preview.value = file ? URL.createObjectURL(file) : '';
    form.logo = file;
    form.remove_logo = false;
};
const removeLogo = () => {
    clearPreview();
    form.logo = null;
    form.remove_logo = true;
};
const logoUrl = computed(
    () =>
        preview.value ||
        (form.remove_logo ? '' : (props.letterhead.logo?.url ?? '')),
);
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
const addressFields = [
    { key: 'address_line_1', label: 'Address line 1', max: 255 },
    { key: 'address_line_2', label: 'Address line 2', max: 255 },
    { key: 'postcode', label: 'Postcode', max: 20 },
    { key: 'city', label: 'City', max: 100 },
    { key: 'state', label: 'State', max: 100 },
    { key: 'country', label: 'Country', max: 100 },
] as const;
const generalError = computed(
    () =>
        (form.errors as Record<string, string>).version ||
        (form.errors as Record<string, string>).expected_branch_id ||
        form.errors.idempotency_key,
);
const reload = () =>
    router.get('/business-details', {}, { preserveState: false });
</script>

<template>
    <Head title="Business details" />
    <main class="mx-auto w-full max-w-[1440px] flex-1 p-4 pb-0 md:p-6 md:pb-0">
        <header class="mb-4 flex flex-wrap items-center gap-3">
            <Link
                href="/clinic-management"
                class="rounded px-2 py-1 text-xs hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                >← Clinic management</Link
            >
            <h1 class="text-lg font-semibold">Business details</h1>
            <span class="text-xs text-muted-foreground">{{
                letterhead.branchName
            }}</span>
            <span
                v-if="!canEdit"
                class="ml-auto rounded-full border bg-muted px-2 py-1 text-xs"
                >View only</span
            >
        </header>
        <p
            v-if="generalError || blocked"
            role="alert"
            class="mb-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            {{
                blocked
                    ? 'The branch changed. Reload before continuing.'
                    : generalError
            }}
            <Button
                type="button"
                variant="outline"
                class="ml-2 h-8 text-xs"
                @click="reload"
                >Reload</Button
            >
            <span class="ml-2 text-xs">Reload discards unsaved changes.</span>
        </p>
        <section
            class="mb-4 rounded-lg border bg-card p-4 md:p-5"
            aria-labelledby="document-readiness-heading"
            data-testid="document-readiness"
        >
            <h2 id="document-readiness-heading" class="text-sm font-semibold">
                Document readiness
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Letters: referral, MC and attendance. Invoices and receipts
                print without a footer, but each branch should have one before
                real use.
            </p>
            <EmptyState
                v-if="readiness.length === 0"
                class="mt-3"
                title="No active branches."
                description="Add or switch on a branch in Branches first."
            />
            <ul v-else class="mt-3 grid gap-2" aria-label="Branches">
                <li
                    v-for="row in readiness"
                    :key="row.branchId"
                    class="grid min-w-0 gap-1 rounded-md border p-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:items-center md:gap-3"
                    data-testid="readiness-row"
                >
                    <p class="min-w-0 text-sm font-medium break-words">
                        {{ row.name }}
                        <span
                            v-if="row.isActive"
                            class="text-xs font-normal text-muted-foreground"
                            >(this branch)</span
                        >
                    </p>
                    <div class="grid min-w-0 gap-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusBadge
                                status="document_readiness"
                                :label="readinessLabel(row)"
                                :tone="readinessTone(row)"
                            />
                        </div>
                        <p
                            v-if="row.missing.length"
                            class="text-xs break-words text-muted-foreground"
                        >
                            Missing: {{ shownMissing(row).join(', ')
                            }}<template v-if="hiddenMissing(row).length">
                                · +{{
                                    hiddenMissing(row).length
                                }}
                                more</template
                            >
                        </p>
                        <div
                            v-if="hiddenMissing(row).length"
                            class="min-w-0 text-xs"
                        >
                            <DisclosureText
                                :text="`All missing: ${row.missing.join(', ')}`"
                            />
                        </div>
                        <p
                            v-if="!row.hasLogo && !logoBroken(row)"
                            class="text-xs text-muted-foreground"
                        >
                            No logo (optional)
                        </p>
                    </div>
                    <div v-if="row.missing.length" class="min-w-0 text-xs">
                        <Button
                            v-if="row.isActive"
                            variant="ghost"
                            size="sm"
                            class="min-h-11 md:min-h-8"
                            as-child
                        >
                            <a
                                :href="fixTarget(row)"
                                :aria-label="`Fix below: ${row.name}`"
                                >Fix below</a
                            >
                        </Button>
                        <span v-else class="text-muted-foreground"
                            >Switch to {{ row.name }} in the header to
                            edit.</span
                        >
                    </div>
                </li>
            </ul>
        </section>
        <p v-if="!canEdit" class="mb-3 text-xs text-muted-foreground">
            You can view these details. Only an authorised editor can save
            changes. The print footer below can be edited separately.
        </p>
        <form @submit.prevent="save" class="space-y-3" novalidate>
            <fieldset
                :disabled="!canEdit || form.processing || blocked"
                class="min-w-0 space-y-3 disabled:opacity-100"
            >
                <section
                    class="rounded-lg border bg-card p-4 md:p-5"
                    aria-labelledby="clinic-details-heading"
                >
                    <h2
                        id="clinic-details-heading"
                        class="mb-4 text-sm font-semibold"
                    >
                        Clinic details
                    </h2>
                    <div class="grid gap-x-6 gap-y-4 md:grid-cols-2">
                        <div class="min-w-0 space-y-3">
                            <div>
                                <label for="clinic_name" class="biz-label"
                                    >Clinic name</label
                                >
                                <input
                                    id="clinic_name"
                                    v-model="form.clinic_name"
                                    name="clinic_name"
                                    class="biz-input"
                                    maxlength="200"
                                    :aria-invalid="!!form.errors.clinic_name"
                                    aria-describedby="clinic_name-error"
                                />
                                <InputError
                                    id="clinic_name-error"
                                    :message="form.errors.clinic_name"
                                />
                            </div>
                            <div
                                v-for="field in addressFields"
                                :key="field.key"
                            >
                                <label :for="field.key" class="biz-label">{{
                                    field.label
                                }}</label>
                                <input
                                    :id="field.key"
                                    v-model="form[field.key]"
                                    :name="field.key"
                                    class="biz-input"
                                    :maxlength="field.max"
                                    :aria-invalid="!!form.errors[field.key]"
                                    :aria-describedby="`${field.key}-error`"
                                />
                                <InputError
                                    :id="`${field.key}-error`"
                                    :message="form.errors[field.key]"
                                />
                            </div>
                        </div>
                        <div class="min-w-0 space-y-4">
                            <div>
                                <label for="phone" class="biz-label"
                                    >Clinic phone number (optional)</label
                                >
                                <input
                                    id="phone"
                                    v-model="form.phone"
                                    name="phone"
                                    type="tel"
                                    class="biz-input"
                                    maxlength="40"
                                    :aria-invalid="!!form.errors.phone"
                                    aria-describedby="phone-help phone-error"
                                />
                                <p
                                    id="phone-help"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Include the country code for numbers outside
                                    Malaysia.
                                </p>
                                <InputError
                                    id="phone-error"
                                    :message="form.errors.phone"
                                />
                            </div>
                            <div>
                                <label for="email" class="biz-label"
                                    >Clinic email (optional)</label
                                >
                                <input
                                    id="email"
                                    v-model="form.email"
                                    name="email"
                                    type="email"
                                    class="biz-input"
                                    maxlength="254"
                                    :aria-invalid="!!form.errors.email"
                                    aria-describedby="email-error"
                                />
                                <InputError
                                    id="email-error"
                                    :message="form.errors.email"
                                />
                            </div>
                            <div>
                                <h3 class="biz-label">Company logo</h3>
                                <p class="mb-3 text-xs text-muted-foreground">
                                    One logo for all branches. JPG or PNG, up to
                                    2 MB and 4096 × 4096 pixels. Preferred
                                    aspect ratio 16:9.
                                </p>
                                <div class="flex flex-wrap items-start gap-3">
                                    <div
                                        class="flex h-24 w-40 items-center justify-center overflow-hidden rounded-lg border bg-muted/30 p-2"
                                    >
                                        <img
                                            v-if="logoUrl"
                                            :src="logoUrl"
                                            alt="Company logo preview"
                                            class="max-h-full max-w-full object-contain"
                                        />
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                            >No logo</span
                                        >
                                    </div>
                                    <div
                                        v-if="canEdit"
                                        class="min-w-0 flex-1 space-y-2"
                                    >
                                        <label for="logo" class="biz-label"
                                            >Choose logo</label
                                        >
                                        <input
                                            id="logo"
                                            ref="fileInput"
                                            name="logo"
                                            type="file"
                                            accept="image/jpeg,image/png"
                                            class="block w-full min-w-0 text-xs file:mr-2 file:cursor-pointer file:rounded-md file:border file:border-border file:bg-background file:px-3 file:py-2 file:text-xs"
                                            aria-describedby="logo-error"
                                            :aria-invalid="!!form.errors.logo"
                                            @change="chooseLogo"
                                        />
                                        <Button
                                            v-if="logoUrl"
                                            type="button"
                                            variant="outline"
                                            class="h-8 text-xs"
                                            @click="removeLogo"
                                            >Remove logo</Button
                                        >
                                        <p
                                            v-if="form.remove_logo"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Logo removal will be applied when
                                            you save.
                                        </p>
                                        <p
                                            v-if="form.logo"
                                            class="text-xs text-muted-foreground"
                                        >
                                            New logo selected. Save to apply.
                                        </p>
                                    </div>
                                </div>
                                <InputError
                                    id="logo-error"
                                    :message="form.errors.logo"
                                />
                            </div>
                        </div>
                    </div>
                </section>
                <section
                    class="rounded-lg border bg-card p-4 md:p-5"
                    aria-labelledby="organisation-details-heading"
                >
                    <h2
                        id="organisation-details-heading"
                        class="mb-1 text-sm font-semibold"
                    >
                        Organisation details
                    </h2>
                    <p class="mb-4 text-xs text-muted-foreground">
                        Legal identity and registration are shared across
                        branches.
                    </p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="legal_name" class="biz-label"
                                >Legal name</label
                            >
                            <input
                                id="legal_name"
                                v-model="form.legal_name"
                                name="legal_name"
                                class="biz-input"
                                maxlength="200"
                                :aria-invalid="!!form.errors.legal_name"
                                aria-describedby="legal_name-error"
                            />
                            <InputError
                                id="legal_name-error"
                                :message="form.errors.legal_name"
                            />
                        </div>
                        <div>
                            <label for="registration_type" class="biz-label"
                                >Registration type</label
                            >
                            <select
                                id="registration_type"
                                v-model="form.registration_type"
                                class="biz-input"
                                :aria-invalid="!!form.errors.registration_type"
                                aria-describedby="registration_type-error"
                            >
                                <option value="BRN">
                                    Company Registration Number (BRN)
                                </option>
                            </select>
                            <InputError
                                id="registration_type-error"
                                :message="form.errors.registration_type"
                            />
                        </div>
                        <div>
                            <label for="registration_number" class="biz-label"
                                >Registration number (optional)</label
                            >
                            <input
                                id="registration_number"
                                v-model="form.registration_number"
                                name="registration_number"
                                class="biz-input"
                                maxlength="80"
                                :aria-invalid="
                                    !!form.errors.registration_number
                                "
                                aria-describedby="registration_number-error"
                            />
                            <InputError
                                id="registration_number-error"
                                :message="form.errors.registration_number"
                            />
                        </div>
                    </div>
                </section>
            </fieldset>
            <footer
                v-if="canEdit"
                class="sticky bottom-0 z-10 flex justify-end gap-2 border-t bg-background/95 py-3"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-8 text-xs"
                    :disabled="form.processing || blocked"
                    @click="adopt"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    class="h-8 text-xs"
                    :disabled="form.processing || blocked"
                    >{{ form.processing ? 'Saving…' : 'Save' }}</Button
                >
            </footer>
        </form>
        <form
            @submit.prevent="saveFooter"
            class="my-4 rounded-lg border bg-card p-4 md:p-5"
            novalidate
            aria-labelledby="print-footer-heading"
        >
            <h2 id="print-footer-heading" class="text-sm font-semibold">
                Branch print footer
            </h2>
            <p
                id="print-footer-help"
                class="my-2 text-xs text-muted-foreground"
            >
                Optional plain text for this branch’s invoice and receipt print
                views. Maximum 1,000 characters; no HTML. Settings editors can
                save this footer without changing clinic details or the logo.
            </p>
            <label for="footer_text" class="biz-label"
                >Print footer (optional)</label
            >
            <textarea
                id="footer_text"
                v-model="footerForm.footer_text"
                rows="3"
                maxlength="1000"
                class="w-full rounded-md border bg-background p-2 text-sm"
                :disabled="blocked || footerForm.processing"
                :aria-invalid="!!footerForm.errors.footer_text"
                aria-describedby="print-footer-help print-footer-error"
            />
            <InputError
                id="print-footer-error"
                :message="footerForm.errors.footer_text"
            />
            <p
                v-if="
                    footerForm.errors.lock_version ||
                    footerForm.errors.idempotency_key ||
                    footerForm.errors.expected_branch_id
                "
                role="alert"
                class="my-2 text-xs text-destructive"
            >
                {{
                    footerForm.errors.lock_version ||
                    footerForm.errors.idempotency_key ||
                    footerForm.errors.expected_branch_id
                }}
                <button type="button" class="underline" @click="reload">
                    Reload
                </button>
            </p>
            <div class="mt-3 flex justify-end">
                <Button
                    type="submit"
                    class="h-8 text-xs"
                    :disabled="blocked || footerForm.processing"
                    >{{
                        footerForm.processing ? 'Saving…' : 'Save print footer'
                    }}</Button
                >
            </div>
        </form>
    </main>
</template>

<style scoped>
.biz-label {
    display: block;
    margin-bottom: 0.375rem;
    font-size: 0.75rem;
    font-weight: 500;
}
.biz-input {
    display: block;
    width: 100%;
    min-width: 0;
    height: 2rem;
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    background: var(--background);
    padding: 0.375rem 0.625rem;
    font-size: 0.8125rem;
}
.biz-input:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 2px;
}
.biz-input[aria-invalid='true'] {
    border-color: var(--destructive);
}
.biz-input:disabled {
    color: var(--foreground);
    opacity: 1;
    background: var(--muted);
}
</style>
