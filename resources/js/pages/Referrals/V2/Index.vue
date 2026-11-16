<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDateTime } from '@/lib/presentation';

type Document = {
    id: number;
    number: string;
    status: string;
    issuedAt: string;
    version: number;
    voidToken: string;
};
type Source = {
    token: string;
    patient: { name: string; number: string; dateOfBirth: string | null };
    allergyStatus: string;
    diagnoses: Choice[];
    medicines: Choice[];
    allergies: Choice[];
};
type Choice = { id: number; text: string };
type Snapshot = {
    letterhead: {
        details: Record<string, string | null>;
        logo: { url: string } | null;
    };
    doctor: { name: string; mmc: string };
    examDate: string;
    issueDate: string;
    title: string;
    patient: Source['patient'];
    visit: string;
    pages: { text: string; heading: boolean }[][];
    number: string;
};
const props = defineProps<{
    referrals: {
        visitNumber: string;
        branchId: number;
        timezone: string;
        canIssue: boolean;
        canFirstIssue: boolean;
        canPrint: boolean;
        documents: Document[];
        page: number;
        hasMore: boolean;
    };
}>();
const page = usePage();
const endpoint = `/visits/${props.referrals.visitNumber}/referrals`;
const source = ref<Source | null>(null);
const preview = ref<Snapshot | null>(null);
const busy = ref(false);
const error = ref('');
const editing = ref(false);
const stale = ref(false);
const branchChanged = computed(
    () => page.props.branchContext?.active?.id !== props.referrals.branchId,
);
const blank = () => ({
    expected_branch_id: props.referrals.branchId,
    action: 'issue',
    document_id: null as number | null,
    lock_version: 0,
    source_token: '',
    review_token: '',
    idempotency_key: crypto.randomUUID(),
    language: 'en',
    institution: '',
    department: '',
    clinician: '',
    reason: '',
    urgency: 'routine',
    history: '',
    findings: '',
    management: '',
    diagnoses: [] as number[],
    medicines: [] as number[],
    allergies: [] as number[],
    include_allergies: false,
    consent: false,
    change_reason: 'details_corrected',
});
const form = reactive(blank());
let controller: AbortController | null = null;
let generation = 0;
function clear() {
    generation++;
    controller?.abort();
    source.value = null;
    preview.value = null;
    stale.value = false;
    editing.value = false;
    error.value = '';
    busy.value = false;
    Object.assign(form, blank());
}
watch(() => page.props.branchContext?.active?.id, clear);
watch(() => props.referrals.branchId, clear);
onBeforeUnmount(clear);
watch(
    () => JSON.stringify({ ...form, review_token: '', idempotency_key: '' }),
    () => {
        // V2 only: say so when a preview is dropped because the selection changed (nothing else differs from Classic).
        stale.value = preview.value !== null;
        preview.value = null;
        form.review_token = '';
        form.idempotency_key = crypto.randomUUID();
    },
);
async function request(path: string, body?: object) {
    const ownGeneration = generation;
    busy.value = true;
    error.value = '';
    controller = new AbortController();

    try {
        const response = await fetch(path, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const payload = await response.json();

        if (ownGeneration !== generation || branchChanged.value) {
            return null;
        }

        if (!response.ok) {
            throw new Error(
                payload.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : 'Access refused or source unavailable. Reload and review.',
            );
        }

        return payload;
    } catch (failure) {
        if (
            ownGeneration === generation &&
            !(failure instanceof DOMException && failure.name === 'AbortError')
        ) {
            error.value =
                failure instanceof Error ? failure.message : 'Request failed.';
        }

        return null;
    } finally {
        if (ownGeneration === generation) {
            busy.value = false;
        }
    }
}
async function compose(doc: Document | null = null) {
    clear();
    editing.value = true;

    if (doc) {
        Object.assign(form, {
            action: 'replace',
            document_id: doc.id,
            lock_version: doc.version,
        });
    }

    const payload = await request(`${endpoint}/source`);

    if (payload) {
        source.value = payload;
        form.source_token = payload.token;
    }
}
async function prepare() {
    const reviewedInput = JSON.stringify(form);
    const payload = await request(`${endpoint}/preview`, { ...form });

    if (JSON.stringify(form) !== reviewedInput) {
        return;
    }

    if (payload) {
        preview.value = payload.snapshot;
        form.review_token = payload.reviewToken;
        stale.value = false;
    }
}
async function issue() {
    if (!preview.value || !form.review_token || branchChanged.value) {
        return;
    }

    const result = await request(endpoint, { ...form });

    if (result) {
        clear();
        router.reload();
    }
}
const voiding = ref<Document | null>(null);
// V2: a void starts with no category picked (Classic starts on "Details corrected"); a void is permanent.
const voidReason = ref('');
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        voiding.value = null;
    },
);
async function voidReferral() {
    const doc = voiding.value;

    if (!doc) {
        return;
    }

    const result = await request(endpoint, {
        expected_branch_id: props.referrals.branchId,
        action: 'void',
        document_id: doc.id,
        lock_version: doc.version,
        source_token: doc.voidToken,
        idempotency_key: form.idempotency_key,
        change_reason: voidReason.value,
    });

    if (result) {
        voiding.value = null;
        clear();
        router.reload();
    }
}

usePageCrumbs(() => [
    { title: 'Registration', href: '/registration' },
    { title: props.referrals.visitNumber },
]);

const guard = ref<InstanceType<typeof V2LeaveGuard> | null>(null);
const fieldsJson = () =>
    JSON.stringify([
        form.language,
        form.institution,
        form.department,
        form.clinician,
        form.reason,
        form.urgency,
        form.history,
        form.findings,
        form.management,
        form.diagnoses,
        form.medicines,
        form.allergies,
        form.include_allergies,
        form.consent,
        form.change_reason,
    ]);
const baseline = ref('');
watch(source, (value) => {
    if (value) {
        baseline.value = fieldsJson();
    }
});
// Unsaved work: something typed or ticked in the compose form since the saved source loaded.
const dirty = computed(
    () =>
        editing.value &&
        source.value !== null &&
        fieldsJson() !== baseline.value,
);
const ask = (run: () => void) => guard.value?.ask(run);
watch(voiding, (value) => {
    if (value) {
        voidReason.value = '';
    }
});
const toggle = (list: number[], id: number, on: boolean) => {
    const at = list.indexOf(id);

    if (on && at === -1) {
        list.push(id);
    } else if (!on && at !== -1) {
        list.splice(at, 1);
    }
};
// Why the Preview button is off, in words (each can be fixed on this screen).
const missing = computed(() => {
    if (!form.institution) {
        return 'Enter the institution to continue.';
    }

    if (!form.reason) {
        return 'Enter the reason to continue.';
    }

    if (form.diagnoses.length > 20 || form.medicines.length > 20) {
        return 'Select at most 20 diagnoses and 20 medicines to continue.';
    }

    if (!form.consent) {
        return "Acknowledge the patient's agreement to continue.";
    }

    return undefined;
});
const issueReason = computed(() => {
    if (busy.value) {
        return undefined;
    }

    if (stale.value) {
        return 'Your selection changed. Preview again before issuing.';
    }

    return preview.value && form.review_token
        ? undefined
        : 'Preview the selected content to continue.';
});
const issueSentence = computed(() =>
    preview.value && source.value
        ? `${form.action === 'replace' ? 'Issues a replacement referral' : 'Issues a referral'} to ${form.institution}${form.department ? `, ${form.department}` : ''} for ${source.value.patient.name}. Urgency: ${form.urgency}. It is a draft until the doctor approves the wording.`
        : null,
);
const statusLabel = (status: string) =>
    status.charAt(0).toUpperCase() + status.slice(1);
const statusTone = (status: string) =>
    status === 'void' ? 'danger' : status === 'current' ? 'success' : 'neutral';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const card = 'rounded-[14px] border bg-card p-4 sm:p-5';
const box =
    'w-full rounded-[10px] border bg-background p-2 text-sm min-h-11 lg:min-h-9';
const textAreas = [
    { key: 'reason', label: 'Reason', max: 1000, required: true },
    { key: 'history', label: 'History', max: 4000, required: false },
    { key: 'findings', label: 'Findings', max: 4000, required: false },
    { key: 'management', label: 'Management', max: 4000, required: false },
] as const;
const textFields = [
    { key: 'institution', label: 'Institution (required)', required: true },
    { key: 'department', label: 'Department (optional)', required: false },
    { key: 'clinician', label: 'Clinician (optional)', required: false },
] as const;
</script>

<template>
    <Head title="Referral letters" />
    <PageFrame>
        <V2LeaveGuard ref="guard" :dirty="dirty" />
        <V2WorkLayout
            eyebrow="Visit"
            title="Referral letters"
            :description="`${referrals.visitNumber} · Own referrals only`"
        >
            <template #actions>
                <button
                    v-if="referrals.canIssue && referrals.canFirstIssue"
                    type="button"
                    :class="editing ? outlineButton : primaryButton"
                    :disabled="busy || branchChanged"
                    @click="ask(() => compose())"
                >
                    New referral
                </button>
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
                        DRAFT EN/BM wording awaits doctor approval.
                        Pre-signature evidence only; wet-sign paper and retain a
                        signed duplicate. No charge. Each referral has its own
                        chain; voiding closes that chain.
                    </p>
                </div>
                <p
                    v-if="!referrals.canFirstIssue"
                    class="text-sm text-muted-foreground"
                >
                    First issue requires Serving, not held, before clinical
                    checkout. Reopening does not restore first issue; the
                    original issuer may replace or void an existing current
                    referral.
                </p>
                <p v-if="branchChanged" role="alert" :class="card">
                    Branch changed. Reload this page; loaded content and drafts
                    have been cleared.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    :class="[card, 'border-destructive text-destructive']"
                >
                    {{ error }}
                </p>

                <section
                    v-if="editing && !branchChanged"
                    aria-label="Compose referral"
                    class="grid min-w-0 gap-4 xl:grid-cols-2"
                >
                    <form
                        :class="[card, 'grid min-w-0 content-start gap-4']"
                        @submit.prevent="prepare"
                    >
                        <h2 class="text-lg font-semibold">
                            {{
                                form.action === 'replace'
                                    ? 'Replace referral'
                                    : 'Issue referral'
                            }}
                        </h2>
                        <p class="text-sm">
                            Only SAVED source data is used. Unsaved clinical
                            work is not copied. No diagnosis, medicine, allergy
                            section or Encounter note is selected automatically.
                        </p>
                        <div>
                            <button
                                type="button"
                                :class="outlineButton"
                                :disabled="busy"
                                @click="ask(() => compose())"
                            >
                                Reload saved source (clears draft)
                            </button>
                        </div>
                        <template v-if="source">
                            <p class="font-semibold break-words">
                                {{ source.patient.name }} ·
                                {{ source.patient.number }}
                            </p>
                            <V2OptionGroup
                                v-model="form.language"
                                label="Language"
                                :options="[
                                    { value: 'en', label: 'English' },
                                    { value: 'bm', label: 'Bahasa Melayu' },
                                ]"
                            />
                            <V2Field
                                v-for="field in textFields"
                                :key="field.key"
                                v-slot="{ id }"
                                :label="field.label"
                            >
                                <input
                                    :id="id"
                                    v-model="form[field.key]"
                                    :required="field.required"
                                    maxlength="200"
                                    :class="box"
                                />
                            </V2Field>
                            <V2OptionGroup
                                v-model="form.urgency"
                                label="Urgency"
                                :options="[
                                    { value: 'routine', label: 'Routine' },
                                    { value: 'urgent', label: 'Urgent' },
                                ]"
                            />
                            <V2Field
                                v-for="field in textAreas"
                                :key="field.key"
                                v-slot="{ id, describedBy }"
                                :label="`${field.label} (${field.max} characters maximum)`"
                                :hint="`${form[field.key].length}/${field.max} characters`"
                            >
                                <textarea
                                    :id="id"
                                    v-model="form[field.key]"
                                    :required="field.required"
                                    :maxlength="field.max"
                                    :aria-describedby="describedBy"
                                    rows="3"
                                    :class="box"
                                />
                            </V2Field>
                            <fieldset
                                v-for="kind in [
                                    'diagnoses',
                                    'medicines',
                                ] as const"
                                :key="kind"
                                class="grid gap-1"
                            >
                                <legend class="font-semibold">
                                    Select relevant {{ kind }} (maximum 20)
                                </legend>
                                <p class="text-[13px] text-muted-foreground">
                                    {{ form[kind].length }} of 20 selected
                                </p>
                                <label
                                    v-for="choice in source[kind]"
                                    :key="choice.id"
                                    class="flex min-h-11 items-start gap-3 py-1 break-words"
                                >
                                    <Checkbox
                                        :model-value="
                                            form[kind].includes(choice.id)
                                        "
                                        class="mt-0.5 shrink-0"
                                        @update:model-value="
                                            toggle(
                                                form[kind],
                                                choice.id,
                                                $event === true,
                                            )
                                        "
                                    />
                                    <span class="min-w-0">{{
                                        choice.text
                                    }}</span>
                                </label>
                                <p
                                    v-if="!source[kind].length"
                                    class="text-sm text-muted-foreground"
                                >
                                    No saved active records.
                                </p>
                            </fieldset>
                            <fieldset class="grid gap-1">
                                <legend class="font-semibold">
                                    Allergy information
                                </legend>
                                <p class="text-sm">
                                    Saved status:
                                    {{
                                        source.allergyStatus.replaceAll(
                                            '_',
                                            ' ',
                                        )
                                    }}. Review before selecting; unknown is
                                    never treated as none.
                                </p>
                                <label
                                    class="flex min-h-11 items-center gap-3 py-1"
                                >
                                    <Checkbox
                                        v-model="form.include_allergies"
                                        class="shrink-0"
                                    />Include reviewed allergy status
                                </label>
                                <template v-if="form.include_allergies">
                                    <label
                                        v-for="choice in source.allergies"
                                        :key="choice.id"
                                        class="flex min-h-11 items-start gap-3 py-1 break-words"
                                    >
                                        <Checkbox
                                            :model-value="
                                                form.allergies.includes(
                                                    choice.id,
                                                )
                                            "
                                            class="mt-0.5 shrink-0"
                                            @update:model-value="
                                                toggle(
                                                    form.allergies,
                                                    choice.id,
                                                    $event === true,
                                                )
                                            "
                                        />
                                        <span class="min-w-0">{{
                                            choice.text
                                        }}</span>
                                    </label>
                                </template>
                            </fieldset>
                            <label
                                class="flex min-h-11 items-start gap-3 py-1 text-sm"
                            >
                                <Checkbox
                                    v-model="form.consent"
                                    class="mt-0.5 shrink-0"
                                />
                                <span
                                    >I explicitly acknowledge the patient's
                                    agreement to disclosure to the stated
                                    recipient.</span
                                >
                            </label>
                            <p class="text-sm text-muted-foreground">
                                A tick does not prove the clinic's
                                written-consent or guardian procedure was
                                followed.
                            </p>
                            <V2OptionGroup
                                v-if="form.action === 'replace'"
                                v-model="form.change_reason"
                                label="Correction category"
                                :options="[
                                    {
                                        value: 'details_corrected',
                                        label: 'Details corrected',
                                    },
                                    {
                                        value: 'entered_in_error',
                                        label: 'Entered in error',
                                    },
                                    {
                                        value: 'patient_request',
                                        label: 'Patient request',
                                    },
                                ]"
                            />
                            <p
                                v-if="missing"
                                class="text-sm text-muted-foreground"
                                role="status"
                                data-testid="preview-reason"
                            >
                                {{ missing }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    :class="
                                        preview ? outlineButton : primaryButton
                                    "
                                    :disabled="busy || !!missing"
                                >
                                    Preview selected content
                                </button>
                                <button
                                    type="button"
                                    :class="outlineButton"
                                    @click="ask(clear)"
                                >
                                    Cancel
                                </button>
                            </div>
                        </template>
                    </form>

                    <section
                        aria-label="Referral preview"
                        :class="[card, 'grid min-w-0 content-start gap-3']"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold">Preview</h2>
                            <V2Chip
                                tone="warning"
                                label="PREVIEW — not issued"
                            />
                        </div>
                        <p v-if="!preview" class="text-sm">
                            Preview the exact saved selections and wording
                            before issue.
                        </p>
                        <p
                            v-if="stale"
                            role="status"
                            class="rounded-[10px] border border-warning/30 bg-warning/10 p-3 text-sm"
                            data-testid="stale-preview"
                        >
                            Your selection changed. Preview again before
                            issuing.
                        </p>
                        <template v-if="preview">
                            <article
                                v-for="(lines, index) in preview.pages"
                                :key="index"
                                class="space-y-2 rounded-[10px] border bg-white p-4 text-zinc-900"
                            >
                                <img
                                    v-if="preview.letterhead.logo"
                                    :src="preview.letterhead.logo.url"
                                    alt="Captured clinic logo"
                                    class="max-h-14 max-w-20 object-contain"
                                />
                                <p class="font-bold">
                                    DRAFT · {{ preview.title }}
                                </p>
                                <p class="text-xs">
                                    {{ preview.letterhead.details.clinic_name
                                    }}<br />{{
                                        Object.values(
                                            preview.letterhead.details,
                                        )
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p class="text-xs">
                                    Examined: {{ preview.examDate }} · Issue
                                    date: {{ preview.issueDate }}<br />{{
                                        preview.doctor.name
                                    }}
                                    · MMC {{ preview.doctor.mmc }}
                                </p>
                                <p>
                                    {{ preview.patient.name }} ·
                                    {{ preview.patient.number }}
                                    <span v-if="preview.patient.dateOfBirth">
                                        · DOB:
                                        {{ preview.patient.dateOfBirth }}</span
                                    >
                                </p>
                                <p class="text-xs">
                                    Visit: {{ preview.visit }}
                                </p>
                                <p>
                                    PREVIEW — not issued · Page
                                    {{ index + 1 }} /
                                    {{ preview.pages.length }}
                                </p>
                                <div
                                    v-for="(line, n) in lines"
                                    :key="n"
                                    class="min-h-4 text-sm break-all whitespace-pre-wrap"
                                    :class="line.heading ? 'font-semibold' : ''"
                                >
                                    {{ line.text }}
                                </div>
                            </article>
                        </template>
                        <p
                            v-if="issueSentence"
                            class="text-sm"
                            role="status"
                            data-testid="issue-sentence"
                        >
                            {{ issueSentence }}
                        </p>
                        <p
                            v-if="issueReason"
                            class="text-sm text-muted-foreground"
                            role="status"
                            data-testid="issue-reason"
                        >
                            {{ issueReason }}
                        </p>
                        <div>
                            <button
                                type="button"
                                :class="
                                    preview && form.review_token
                                        ? primaryButton
                                        : outlineButton
                                "
                                :disabled="
                                    busy ||
                                    !preview ||
                                    !form.review_token ||
                                    branchChanged
                                "
                                @click="issue"
                            >
                                {{
                                    form.action === 'replace'
                                        ? 'Issue replacement'
                                        : 'Issue referral'
                                }}
                            </button>
                        </div>
                    </section>
                </section>

                <section
                    v-if="!branchChanged"
                    aria-label="Referral history"
                    class="grid gap-3"
                >
                    <h2 class="text-lg font-semibold">History</h2>
                    <p v-if="!referrals.documents.length" :class="card">
                        No referrals issued.
                    </p>
                    <article
                        v-for="doc in referrals.documents"
                        :key="doc.id"
                        :class="[
                            card,
                            'flex flex-wrap items-center justify-between gap-4',
                        ]"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3
                                    class="text-base font-semibold whitespace-nowrap"
                                >
                                    {{ doc.number }}
                                </h3>
                                <V2Chip
                                    :tone="statusTone(doc.status)"
                                    :label="statusLabel(doc.status)"
                                />
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Issued
                                {{
                                    formatDateTime(
                                        doc.issuedAt,
                                        referrals.timezone,
                                    )
                                }}
                                ({{ referrals.timezone }})
                            </p>
                        </div>
                        <div
                            class="flex flex-wrap gap-2 max-sm:w-full max-sm:flex-col"
                        >
                            <a
                                v-if="referrals.canPrint"
                                :href="`${endpoint}/${doc.id}`"
                                target="_blank"
                                rel="noopener"
                                :class="[
                                    outlineButton,
                                    'whitespace-nowrap max-sm:w-full',
                                ]"
                                >Open COPY / print</a
                            >
                            <button
                                v-if="
                                    referrals.canIssue &&
                                    doc.status === 'current'
                                "
                                type="button"
                                :class="[
                                    outlineButton,
                                    'whitespace-nowrap max-sm:w-full',
                                ]"
                                :disabled="busy"
                                @click="ask(() => compose(doc))"
                            >
                                Replace
                            </button>
                            <button
                                v-if="
                                    referrals.canIssue &&
                                    doc.status === 'current'
                                "
                                type="button"
                                :class="[
                                    outlineButton,
                                    'whitespace-nowrap max-sm:w-full',
                                ]"
                                :disabled="busy"
                                @click="voiding = doc"
                            >
                                Void
                            </button>
                        </div>
                    </article>
                    <nav
                        aria-label="Referral pages"
                        class="flex flex-wrap gap-3"
                    >
                        <a
                            v-if="referrals.page > 1"
                            :href="`${endpoint}?page=${referrals.page - 1}`"
                            :class="outlineButton"
                            >Previous</a
                        ><a
                            v-if="referrals.hasMore"
                            :href="`${endpoint}?page=${referrals.page + 1}`"
                            :class="outlineButton"
                            >Next</a
                        >
                    </nav>
                </section>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        :open="voiding !== null && !branchChanged"
        :title="`Void ${voiding?.number ?? ''}?`"
        description="This closes this referral's chain. The retained evidence cannot be deleted."
        confirm-label="Confirm void"
        tone="danger"
        :busy="busy"
        :confirm-disabled="!voidReason"
        confirm-disabled-reason="Pick a reason to continue."
        @update:open="
            (value) => {
                if (!value) voiding = null;
            }
        "
        @confirm="voidReferral"
    >
        <div v-if="voiding" class="grid gap-4">
            <div class="grid gap-1 rounded-[10px] bg-muted/40 p-3 text-sm">
                <p class="font-semibold whitespace-nowrap">
                    {{ voiding.number }}
                </p>
                <p>{{ statusLabel(voiding.status) }}</p>
                <p class="text-xs text-muted-foreground">
                    Issued
                    {{ formatDateTime(voiding.issuedAt, referrals.timezone) }}
                    ({{ referrals.timezone }})
                </p>
            </div>
            <V2OptionGroup
                v-model="voidReason"
                label="Category"
                :options="[
                    { value: 'details_corrected', label: 'Details corrected' },
                    { value: 'entered_in_error', label: 'Entered in error' },
                    { value: 'patient_request', label: 'Patient request' },
                ]"
            />
        </div>
    </V2Dialog>
</template>
