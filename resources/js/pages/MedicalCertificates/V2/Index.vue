<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDateTime } from '@/lib/presentation';
import { displayFromIso } from '@/lib/v2-date';

/**
 * Version 2 Medical certificates page (shown only in V2; Classic is `pages/MedicalCertificates/Index.vue`, untouched).
 * A clinical document, so this is layout only: the same props, the same one form with the same payload keys, the same
 * idempotency-key rule, the same POST, the same branch lock and the same wording as Classic. The issue, replace and void
 * rules are the server's. The one new sentence is the on-screen footer note in the dialog; it is never printed.
 */
type Document = {
    id: number;
    number: string;
    issuedAt: string;
    status: string;
    canCorrect: boolean;
};
const props = defineProps<{
    certificates: {
        visitNumber: string;
        patientName: string;
        canFirstIssue: boolean;
        branchId: number;
        branchTimezone: string;
        lockVersion: number;
        canIssue: boolean;
        credentialVerified: boolean;
        canPrint: boolean;
        today: string;
        documents: Document[];
        page: number;
        hasMore: boolean;
    };
}>();

usePageCrumbs(() => [
    { title: 'Registration', href: '/registration' },
    { title: props.certificates.visitNumber },
]);

const page = usePage();
const open = ref(false);
const saved = ref(false);
const loaded = ref(true);
const startInvalid = ref(false);
const endInvalid = ref(false);
const baseline = ref('');
const branchChanged = computed(
    () => props.certificates.branchId !== page.props.branchContext?.active?.id,
);
const baseUrl = computed(
    () =>
        `/visits/${encodeURIComponent(props.certificates.visitNumber)}/medical-certificates`,
);
const form = useForm({
    expected_branch_id: props.certificates.branchId,
    lock_version: props.certificates.lockVersion,
    idempotency_key: crypto.randomUUID(),
    action: 'issue',
    document_id: null as number | null,
    language: 'en',
    start_date: props.certificates.today,
    end_date: props.certificates.today,
    reason: '',
    reason_consent: false,
    change_reason: 'dates_corrected',
});
const clear = () => {
    form.cancel();
    form.reset();
    form.clearErrors();
};
const fields = () =>
    JSON.stringify([
        form.language,
        form.start_date,
        form.end_date,
        form.reason,
        form.reason_consent,
        form.change_reason,
    ]);
const edit = (action: string, document: Document | null = null) => {
    clear();
    saved.value = false;
    startInvalid.value = false;
    endInvalid.value = false;
    form.action = action;
    form.document_id = document?.id ?? null;
    form.expected_branch_id = props.certificates.branchId;
    form.lock_version = props.certificates.lockVersion;
    form.idempotency_key = crypto.randomUUID();
    baseline.value = fields();
    open.value = true;
};
watch(
    () => [
        form.action,
        form.document_id,
        form.language,
        form.start_date,
        form.end_date,
        form.reason,
        form.reason_consent,
        form.change_reason,
    ],
    () => {
        if (!form.processing) {
            form.idempotency_key = crypto.randomUUID();
        }
    },
);
watch(open, (value) => {
    if (!value) {
        clear();
    }
});
watch(
    [
        () => props.certificates.branchId,
        () => page.props.branchContext?.active?.id,
    ],
    () => {
        open.value = false;
        loaded.value = false;
        saved.value = false;
        clear();
    },
);
onBeforeUnmount(clear);
const submit = () => {
    if (form.processing || branchChanged.value) {
        return;
    }

    form.post(baseUrl.value, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            saved.value = true;
        },
    });
};
const days = computed(() => {
    const value =
        (Date.parse(form.end_date) - Date.parse(form.start_date)) / 86400000 +
        1;

    return Number.isInteger(value) && value > 0 ? value : 0;
});

const selected = computed(
    () =>
        props.certificates.documents.find(
            (document) => document.id === form.document_id,
        ) ?? null,
);
const dirty = computed(() => open.value && fields() !== baseline.value);
const dialogTitle = computed(() =>
    form.action === 'void'
        ? 'Void MC'
        : form.action === 'replace'
          ? 'Replace MC'
          : 'Issue MC',
);
// Why the filled button is off, in words (the person can fix each).
const missing = computed(() => {
    if (form.action === 'void') {
        return undefined;
    }

    if (
        startInvalid.value ||
        endInvalid.value ||
        !form.start_date ||
        !form.end_date
    ) {
        return 'Enter the start and end dates to continue.';
    }

    if (days.value === 0) {
        return 'The end date must be on or after the start date to continue.';
    }

    if (form.reason && !form.reason_consent) {
        return 'Tick the patient consent, or clear the reason, to continue.';
    }

    return undefined;
});
// On screen only, never printed. Wording is new (listed in the PR for review); the day count is the same one Classic shows.
const footerNote = computed(() => {
    if (form.action === 'void' || missing.value) {
        return null;
    }

    const period = `from ${displayFromIso(form.start_date)} to ${displayFromIso(form.end_date)} (${days.value} ${days.value === 1 ? 'day' : 'days'})`;

    return form.action === 'replace' && selected.value
        ? `Replaces ${selected.value.number} with an MC ${period} to ${props.certificates.patientName}. It is a draft until the doctor approves the wording.`
        : `Issues an MC ${period} to ${props.certificates.patientName}. It is a draft until the doctor approves the wording.`;
});
const statusLabel = (status: string) =>
    status.charAt(0).toUpperCase() + status.slice(1);
const statusTone = (status: string) =>
    status === 'void'
        ? 'danger'
        : status === 'replaced'
          ? 'neutral'
          : 'success';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const card = 'rounded-[14px] border bg-card p-4 sm:p-5';
const errorKeys = [
    'certificate',
    'lock_version',
    'expected_branch_id',
    'idempotency_key',
    'document_id',
] as const;
</script>

<template>
    <Head title="Medical certificates" />
    <PageFrame>
        <V2WorkLayout
            eyebrow="Visit"
            title="Medical certificates"
            :description="
                loaded && !branchChanged
                    ? `${certificates.patientName} · ${certificates.visitNumber} · MC only · no charge`
                    : undefined
            "
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button
                        v-if="certificates.canIssue && loaded"
                        type="button"
                        :class="primaryButton"
                        :disabled="
                            branchChanged ||
                            !certificates.credentialVerified ||
                            !certificates.canFirstIssue ||
                            certificates.documents.length > 0 ||
                            certificates.page > 1
                        "
                        @click="edit('issue')"
                    >
                        Issue MC
                    </button>
                    <Link
                        href="/queue"
                        :class="[
                            outlineButton,
                            'max-sm:order-last max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary',
                        ]"
                        >← Back to Queue</Link
                    >
                </div>
            </template>

            <template #main>
                <div
                    class="flex items-start gap-3 rounded-[14px] border border-warning/30 bg-warning/10 p-4 text-sm"
                    data-testid="draft-notice"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <p>
                        <strong
                            >Draft wording — awaiting doctor approval.</strong
                        >
                        Fixed EN/BM revision 2. Issuance retains pre-signature
                        content only. Wet-sign the paper and retain a signed
                        duplicate. No signed original or final PDF is archived.
                    </p>
                </div>
                <p
                    v-if="
                        certificates.canIssue &&
                        !certificates.credentialVerified
                    "
                    role="alert"
                    :class="[card, 'border-warning/30 text-sm']"
                >
                    Verified professional name and MMC registration are
                    required. Ask the authorised credential manager; you cannot
                    verify yourself.
                </p>
                <p
                    v-if="saved"
                    role="status"
                    :class="[card, 'border-success/30 text-sm']"
                >
                    MC evidence saved. Review the document before printing.
                </p>
                <p v-if="!loaded || branchChanged" role="alert" :class="card">
                    Branch changed. Reload this visit before viewing MC history.
                </p>
                <section v-else class="grid gap-3" aria-label="MC history">
                    <p v-if="!certificates.documents.length" :class="card">
                        No MC has been issued for this visit. First issue
                        requires Serving, not held, before clinical checkout.
                    </p>
                    <p
                        v-if="certificates.documents.length"
                        class="text-sm text-muted-foreground"
                    >
                        One first MC may be issued per consultation. The
                        original issuer may replace the current MC. Voiding it
                        closes this MC chain; another MC cannot be issued for
                        this consultation.
                    </p>
                    <article
                        v-for="document in certificates.documents"
                        :key="document.id"
                        :class="[
                            card,
                            'flex flex-wrap items-center justify-between gap-4',
                        ]"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2
                                    class="text-base font-semibold whitespace-nowrap"
                                >
                                    {{ document.number }}
                                </h2>
                                <V2Chip
                                    :tone="statusTone(document.status)"
                                    :label="statusLabel(document.status)"
                                />
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Issued
                                <time :datetime="document.issuedAt">{{
                                    formatDateTime(
                                        document.issuedAt,
                                        certificates.branchTimezone,
                                    )
                                }}</time>
                                ({{ certificates.branchTimezone }})
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2 max-sm:w-full">
                            <a
                                v-if="certificates.canPrint"
                                :href="`${baseUrl}/${document.id}?print=1`"
                                target="_blank"
                                rel="noopener"
                                :class="[outlineButton, 'max-sm:flex-1']"
                                >View / print copy</a
                            >
                            <template v-if="document.canCorrect">
                                <button
                                    type="button"
                                    :class="[outlineButton, 'max-sm:flex-1']"
                                    @click="edit('replace', document)"
                                >
                                    Replace
                                </button>
                                <button
                                    type="button"
                                    :class="[outlineButton, 'max-sm:flex-1']"
                                    @click="edit('void', document)"
                                >
                                    Void
                                </button>
                            </template>
                        </div>
                    </article>
                    <nav
                        class="flex flex-wrap items-center gap-3"
                        aria-label="MC history pages"
                    >
                        <Link
                            v-if="certificates.page > 1"
                            :href="`${baseUrl}?page=${certificates.page - 1}`"
                            :class="outlineButton"
                            >Previous</Link
                        >
                        <Link
                            v-if="certificates.hasMore"
                            :href="`${baseUrl}?page=${certificates.page + 1}`"
                            :class="outlineButton"
                            >Next</Link
                        >
                        <button
                            type="button"
                            :class="outlineButton"
                            @click="router.reload()"
                        >
                            Refresh history
                        </button>
                    </nav>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        v-model:open="open"
        :title="dialogTitle"
        description="Only the original issuer may correct or void. Old content stays immutable. Wording remains draft."
        :confirm-label="
            form.action === 'void' ? 'Confirm void' : 'Save pre-signature MC'
        "
        :tone="form.action === 'void' ? 'danger' : 'default'"
        :busy="form.processing"
        :confirm-disabled="branchChanged || !!missing"
        :confirm-disabled-reason="missing"
        :dirty="dirty"
        size="tall"
        @confirm="submit"
    >
        <div class="grid gap-4">
            <div
                v-if="selected"
                class="grid gap-1 rounded-[10px] bg-muted/40 p-3 text-sm"
                data-testid="mc-summary"
            >
                <p class="font-semibold whitespace-nowrap">
                    {{ selected.number }}
                </p>
                <p>{{ statusLabel(selected.status) }}</p>
                <p class="text-xs text-muted-foreground">
                    Issued
                    {{
                        formatDateTime(
                            selected.issuedAt,
                            certificates.branchTimezone,
                        )
                    }}
                    ({{ certificates.branchTimezone }})
                </p>
            </div>
            <template v-if="form.action !== 'void'">
                <V2OptionGroup
                    v-model="form.language"
                    label="Language"
                    :options="[
                        { value: 'en', label: 'English · revision 2' },
                        { value: 'bm', label: 'Bahasa Melayu · revision 2' },
                    ]"
                    :error="form.errors.language"
                />
                <div class="grid items-start gap-3 sm:grid-cols-2">
                    <V2DateField
                        v-model="form.start_date"
                        label="Start date"
                        required
                        :max="certificates.today"
                        :error="form.errors.start_date"
                        @update:invalid="startInvalid = $event"
                    />
                    <V2DateField
                        v-model="form.end_date"
                        label="End date"
                        required
                        :min="form.start_date"
                        :error="form.errors.end_date"
                        @update:invalid="endInvalid = $event"
                    />
                </div>
                <p class="text-sm">
                    {{ days }} inclusive day(s) · maximum 30. Examination and
                    issue dates are recorded by the server.
                </p>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Reason to print (optional, 200 characters)"
                    :hint="`${form.reason.length}/200 characters`"
                    :error="form.errors.reason"
                >
                    <textarea
                        :id="id"
                        v-model="form.reason"
                        maxlength="200"
                        rows="2"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        class="w-full rounded-[10px] border bg-background p-2 text-sm"
                    />
                </V2Field>
                <p class="text-xs text-muted-foreground">
                    No diagnosis is copied automatically. Leave blank unless the
                    patient explicitly consents.
                </p>
                <label
                    v-if="form.reason"
                    class="flex min-h-11 items-start gap-3 py-1 text-sm"
                >
                    <Checkbox
                        v-model:checked="form.reason_consent"
                        class="mt-0.5"
                    />
                    The patient explicitly consents to this reason appearing on
                    the MC.
                </label>
                <InputError :message="form.errors.reason_consent" />
            </template>
            <V2OptionGroup
                v-if="form.action !== 'issue'"
                v-model="form.change_reason"
                label="Change category"
                :options="[
                    { value: 'dates_corrected', label: 'Dates corrected' },
                    { value: 'details_corrected', label: 'Details corrected' },
                    { value: 'entered_in_error', label: 'Entered in error' },
                    { value: 'patient_request', label: 'Patient request' },
                ]"
                :error="form.errors.change_reason"
            />
            <p v-if="form.action === 'void'" class="text-sm">
                This permanently voids the selected MC. Its original snapshot
                stays readable and every copy is marked VOID.
            </p>
            <InputError
                v-for="key in errorKeys"
                :key="key"
                :message="(form.errors as Record<string, string>)[key]"
            />
            <button
                v-if="form.errors.lock_version"
                type="button"
                :class="outlineButton"
                @click="
                    open = false;
                    router.reload();
                "
            >
                Reload history
            </button>
        </div>
        <template v-if="footerNote" #footer-note>
            <p class="text-sm" role="status" data-testid="mc-footer-note">
                {{ footerNote }}
            </p>
        </template>
    </V2Dialog>
</template>
