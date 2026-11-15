<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { Textarea } from '@/components/ui/textarea';
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
const shownMissing = (row: Readiness) => row.missing.slice(0, 2);
const hiddenMissing = (row: Readiness) => row.missing.slice(2);
// Where the fix lives in the form below (active branch only).
const fixTarget = (row: Readiness) =>
    row.detailsComplete && !logoBroken(row)
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
    phone_secondary: props.letterhead.details.phone_secondary ?? '',
    label_address: props.letterhead.details.label_address ?? '',
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
            'label_address',
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
const reload = () =>
    router.get('/business-details', {}, { preserveState: false });
</script>

<template>
    <Head title="Business details" />
    <main
        class="mx-auto w-full max-w-4xl min-w-0 flex-1 p-4 pb-0 md:p-6 md:pb-0"
    >
        <PageHeader
            title="Business details"
            :description="`Clinic name, address, contact and logo used on invoices, receipts and letters · ${letterhead.branchName}`"
        >
            <template #actions>
                <StatusBadge
                    v-if="!canEdit"
                    status="view_only"
                    label="View only"
                    tone="neutral"
                />
                <Button variant="ghost" size="sm" as-child>
                    <Link href="/clinic-management">← Clinic management</Link>
                </Button>
            </template>
        </PageHeader>
        <p class="mt-2 mb-4 text-xs text-muted-foreground" aria-hidden="true">
            * Required
        </p>
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
                            <StatusBadge
                                v-if="
                                    !row.hasFooter &&
                                    row.status === 'letters_blocked'
                                "
                                status="document_readiness_footer"
                                label="Needs footer"
                                tone="warning"
                            />
                        </div>
                        <template v-if="row.missing.length">
                            <p
                                class="hidden text-xs break-words text-muted-foreground md:block"
                                data-testid="missing-full"
                            >
                                Missing: {{ row.missing.join(', ') }}
                            </p>
                            <p
                                class="text-xs break-words text-muted-foreground md:hidden"
                                data-testid="missing-short"
                            >
                                Missing: {{ shownMissing(row).join(', ')
                                }}<template v-if="hiddenMissing(row).length">
                                    · +{{
                                        hiddenMissing(row).length
                                    }}
                                    more</template
                                >
                            </p>
                            <details
                                v-if="hiddenMissing(row).length"
                                class="text-xs text-muted-foreground md:hidden"
                            >
                                <summary
                                    class="flex min-h-11 cursor-pointer items-center font-medium text-foreground"
                                >
                                    Show all {{ row.missing.length }} missing
                                </summary>
                                <p class="break-words">
                                    {{ row.missing.join(', ') }}
                                </p>
                            </details>
                        </template>
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
                        <Button
                            v-if="row.isActive && canFix(row)"
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
                        <span
                            v-else-if="row.isActive"
                            class="text-muted-foreground"
                            >Ask an editor to complete these details.</span
                        >
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
                    <div
                        class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2"
                    >
                        <div class="min-w-0 space-y-3">
                            <div class="grid gap-2">
                                <Label for="clinic_name"
                                    >Clinic name<span aria-hidden="true">
                                        *</span
                                    ></Label
                                >
                                <Input
                                    id="clinic_name"
                                    v-model="form.clinic_name"
                                    aria-required="true"
                                    name="clinic_name"
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
                                class="grid gap-2"
                            >
                                <Label :for="field.key"
                                    >{{ field.label
                                    }}<span
                                        v-if="field.required"
                                        aria-hidden="true"
                                    >
                                        *</span
                                    ></Label
                                >
                                <Input
                                    :id="field.key"
                                    v-model="form[field.key]"
                                    :aria-required="
                                        field.required ? 'true' : undefined
                                    "
                                    :name="field.key"
                                    :maxlength="field.max"
                                    :aria-invalid="!!form.errors[field.key]"
                                    :aria-describedby="`${field.key}-error`"
                                />
                                <InputError
                                    :id="`${field.key}-error`"
                                    :message="form.errors[field.key]"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="label_address"
                                    >Address on drug labels (optional)</Label
                                >
                                <Input
                                    id="label_address"
                                    v-model="form.label_address"
                                    name="label_address"
                                    maxlength="96"
                                    :aria-invalid="!!form.errors.label_address"
                                    aria-describedby="label_address-help label_address-error"
                                />
                                <p
                                    id="label_address-help"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    Used only on drug labels. Leave empty to
                                    print the full address. Up to 96 characters.
                                </p>
                                <InputError
                                    id="label_address-error"
                                    :message="form.errors.label_address"
                                />
                            </div>
                        </div>
                        <div class="min-w-0 space-y-4">
                            <div class="grid gap-2">
                                <Label for="phone">Phone (optional)</Label>
                                <Input
                                    id="phone"
                                    v-model="form.phone"
                                    name="phone"
                                    type="tel"
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
                            <div class="grid gap-2">
                                <Label for="phone_secondary"
                                    >Second phone (optional)</Label
                                >
                                <Input
                                    id="phone_secondary"
                                    v-model="form.phone_secondary"
                                    name="phone_secondary"
                                    type="tel"
                                    maxlength="40"
                                    :aria-invalid="
                                        !!form.errors.phone_secondary
                                    "
                                    aria-describedby="phone_secondary-help phone_secondary-error"
                                />
                                <p
                                    id="phone_secondary-help"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    For documents that show two numbers, as
                                    "Tel: A / B".
                                </p>
                                <InputError
                                    id="phone_secondary-error"
                                    :message="form.errors.phone_secondary"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="email"
                                    >Clinic email (optional)</Label
                                >
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    name="email"
                                    type="email"
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
                                <h3
                                    class="mb-2 text-sm leading-none font-medium"
                                >
                                    Company logo
                                </h3>
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
                                        <!-- CAT-10 (Daniel): a real button, with the file's state on its own line and the error under it. -->
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
                                        <Button
                                            v-if="logoUrl"
                                            type="button"
                                            variant="outline"
                                            class="min-h-11 text-xs sm:min-h-9"
                                            @click="removeLogo"
                                            >Remove logo</Button
                                        >
                                    </div>
                                </div>
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
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="grid gap-2 md:col-span-2">
                            <Label for="legal_name"
                                >Legal name<span aria-hidden="true">
                                    *</span
                                ></Label
                            >
                            <Input
                                id="legal_name"
                                v-model="form.legal_name"
                                aria-required="true"
                                name="legal_name"
                                maxlength="200"
                                :aria-invalid="!!form.errors.legal_name"
                                aria-describedby="legal_name-error"
                            />
                            <InputError
                                id="legal_name-error"
                                :message="form.errors.legal_name"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label
                                id="registration_type-label"
                                for="registration_type"
                                >Registration type</Label
                            >
                            <OperationalSelect
                                id="registration_type"
                                v-model="form.registration_type"
                                :disabled="
                                    !canEdit || form.processing || blocked
                                "
                                label="Registration type"
                                labelledby="registration_type-label"
                                describedby="registration_type-error"
                                :invalid="!!form.errors.registration_type"
                                :options="registrationTypes"
                            />
                            <InputError
                                id="registration_type-error"
                                :message="form.errors.registration_type"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="registration_number"
                                >Registration number (optional)</Label
                            >
                            <Input
                                id="registration_number"
                                v-model="form.registration_number"
                                name="registration_number"
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
            <Label for="footer_text" class="mb-2"
                >Print footer (optional)</Label
            >
            <Textarea
                id="footer_text"
                v-model="footerForm.footer_text"
                rows="3"
                maxlength="1000"
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
/* View-only and saving states keep the values readable (the shared inputs fade disabled fields). */
fieldset:disabled :deep(input:disabled),
fieldset:disabled :deep([data-slot='select-trigger']:disabled) {
    color: var(--foreground);
    opacity: 1;
}
</style>
