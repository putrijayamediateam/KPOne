<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
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
        change_reason: form.change_reason,
    });

    if (result) {
        voiding.value = null;
        clear();
        router.reload();
    }
}
</script>

<template>
    <Head title="Referral letters" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Referral letters</h1>
                <p class="text-sm text-muted-foreground">
                    {{ referrals.visitNumber }} · Own referrals only
                </p>
            </div>
            <Button
                v-if="referrals.canIssue && referrals.canFirstIssue"
                :disabled="busy || branchChanged"
                @click="compose()"
                >New referral</Button
            >
        </header>
        <p class="rounded-md border p-3 text-sm">
            DRAFT EN/BM wording awaits doctor approval. Pre-signature evidence
            only; wet-sign paper and retain a signed duplicate. No charge. Each
            referral has its own chain; voiding closes that chain.
        </p>
        <p v-if="!referrals.canFirstIssue" class="text-sm">
            First issue requires Serving, not held, before clinical checkout.
            Reopening does not restore first issue; the original issuer may
            replace or void an existing current referral.
        </p>
        <p v-if="branchChanged" role="alert">
            Branch changed. Reload this page; loaded content and drafts have
            been cleared.
        </p>
        <p
            v-if="error"
            role="alert"
            class="rounded-md border border-destructive p-3 text-destructive"
        >
            {{ error }}
        </p>
        <section
            v-if="editing && !branchChanged"
            aria-label="Compose referral"
            class="grid min-w-0 gap-6 lg:grid-cols-2"
        >
            <form
                class="min-w-0 space-y-4 rounded-lg border bg-card p-4"
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
                    Only SAVED source data is used. Unsaved clinical work is not
                    copied. No diagnosis, medicine, allergy section or Encounter
                    note is selected automatically.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="compose()"
                    >Reload saved source (clears draft)</Button
                >
                <template v-if="source">
                    <p class="font-semibold">
                        {{ source.patient.name }} · {{ source.patient.number }}
                    </p>
                    <label class="block"
                        >Language<select
                            v-model="form.language"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                        >
                            <option value="en">English</option>
                            <option value="bm">Bahasa Melayu</option>
                        </select></label
                    >
                    <label
                        v-for="field in [
                            'institution',
                            'department',
                            'clinician',
                        ] as const"
                        :key="field"
                        class="block capitalize"
                        >{{ field }}
                        {{
                            field === 'institution'
                                ? '(required)'
                                : '(optional)'
                        }}<input
                            v-model="form[field]"
                            :required="field === 'institution'"
                            maxlength="200"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                    /></label>
                    <label class="block"
                        >Urgency<select
                            v-model="form.urgency"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                        >
                            <option value="routine">Routine</option>
                            <option value="urgent">Urgent</option>
                        </select></label
                    >
                    <label
                        v-for="field in [
                            'reason',
                            'history',
                            'findings',
                            'management',
                        ] as const"
                        :key="field"
                        class="block capitalize"
                        >{{ field }} ({{
                            field === 'reason' ? 1000 : 4000
                        }}
                        characters maximum)<textarea
                            v-model="form[field]"
                            :required="field === 'reason'"
                            :maxlength="field === 'reason' ? 1000 : 4000"
                            rows="3"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                        />
                    </label>
                    <fieldset
                        v-for="kind in ['diagnoses', 'medicines'] as const"
                        :key="kind"
                        class="space-y-2"
                    >
                        <legend class="font-semibold capitalize">
                            Select relevant {{ kind }} (maximum 20)
                        </legend>
                        <label
                            v-for="choice in source[kind]"
                            :key="choice.id"
                            class="flex items-start gap-2 break-words"
                            ><input
                                v-model="form[kind]"
                                type="checkbox"
                                :value="choice.id"
                                class="mt-1 shrink-0"
                            /><span class="min-w-0">{{
                                choice.text
                            }}</span></label
                        >
                        <p
                            v-if="!source[kind].length"
                            class="text-sm text-muted-foreground"
                        >
                            No saved active records.
                        </p>
                    </fieldset>
                    <fieldset class="space-y-2">
                        <legend class="font-semibold">
                            Allergy information
                        </legend>
                        <p class="text-sm">
                            Saved status:
                            {{ source.allergyStatus.replaceAll('_', ' ') }}.
                            Review before selecting; unknown is never treated as
                            none.
                        </p>
                        <label class="flex gap-2"
                            ><input
                                v-model="form.include_allergies"
                                type="checkbox"
                            />Include reviewed allergy status</label
                        ><template v-if="form.include_allergies"
                            ><label
                                v-for="choice in source.allergies"
                                :key="choice.id"
                                class="flex items-start gap-2"
                                ><input
                                    v-model="form.allergies"
                                    type="checkbox"
                                    :value="choice.id"
                                    class="mt-1"
                                /><span>{{ choice.text }}</span></label
                            ></template
                        >
                    </fieldset>
                    <label class="flex items-start gap-2"
                        ><input
                            v-model="form.consent"
                            type="checkbox"
                            required
                            class="mt-1"
                        /><span
                            >I explicitly acknowledge the patient's agreement to
                            disclosure to the stated recipient.</span
                        ></label
                    >
                    <p class="text-sm text-muted-foreground">
                        A tick does not prove the clinic's written-consent or
                        guardian procedure was followed.
                    </p>
                    <label v-if="form.action === 'replace'" class="block"
                        >Correction category<select
                            v-model="form.change_reason"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                        >
                            <option value="details_corrected">
                                Details corrected
                            </option>
                            <option value="entered_in_error">
                                Entered in error
                            </option>
                            <option value="patient_request">
                                Patient request
                            </option>
                        </select></label
                    >
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="busy"
                            >Preview selected content</Button
                        ><Button type="button" variant="outline" @click="clear"
                            >Cancel</Button
                        >
                    </div>
                </template>
            </form>
            <section
                aria-label="Referral preview"
                class="min-w-0 space-y-3 rounded-lg border bg-muted p-4"
            >
                <h2 class="text-lg font-semibold">Preview</h2>
                <p v-if="!preview" class="text-sm">
                    Preview the exact saved selections and wording before issue.
                </p>
                <template v-if="preview"
                    ><article
                        v-for="(lines, index) in preview.pages"
                        :key="index"
                        class="space-y-2 bg-white p-4 text-zinc-900"
                    >
                        <img
                            v-if="preview.letterhead.logo"
                            :src="preview.letterhead.logo.url"
                            alt="Captured clinic logo"
                            class="max-h-14 max-w-20 object-contain"
                        />
                        <p class="font-bold">DRAFT · {{ preview.title }}</p>
                        <p class="text-xs">
                            {{ preview.letterhead.details.clinic_name }}<br />{{
                                Object.values(preview.letterhead.details)
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <p class="text-xs">
                            Examined: {{ preview.examDate }} · Issue date:
                            {{ preview.issueDate }}<br />{{
                                preview.doctor.name
                            }}
                            · MMC {{ preview.doctor.mmc }}
                        </p>
                        <p>
                            {{ preview.patient.name }} ·
                            {{ preview.patient.number }}
                            <span v-if="preview.patient.dateOfBirth">
                                · DOB: {{ preview.patient.dateOfBirth }}</span
                            >
                        </p>
                        <p class="text-xs">Visit: {{ preview.visit }}</p>
                        <p>
                            PREVIEW — not issued · Page {{ index + 1 }} /
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
                    <Button
                        :disabled="busy || !form.review_token"
                        @click="issue"
                        >{{
                            form.action === 'replace'
                                ? 'Issue replacement'
                                : 'Issue referral'
                        }}</Button
                    ></template
                >
            </section>
        </section>
        <section
            v-if="!branchChanged"
            aria-label="Referral history"
            class="space-y-3"
        >
            <h2 class="text-lg font-semibold">History</h2>
            <p v-if="!referrals.documents.length">No referrals issued.</p>
            <article
                v-for="doc in referrals.documents"
                :key="doc.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
            >
                <div>
                    <h3 class="font-semibold">{{ doc.number }}</h3>
                    <p class="capitalize">{{ doc.status }}</p>
                    <p class="text-xs text-muted-foreground">
                        Issued
                        {{ formatDateTime(doc.issuedAt, referrals.timezone) }}
                        ({{ referrals.timezone }})
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="referrals.canPrint"
                        :href="`${endpoint}/${doc.id}`"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border px-3 py-2 text-sm"
                        >Open COPY / print</a
                    ><Button
                        v-if="referrals.canIssue && doc.status === 'current'"
                        variant="outline"
                        :disabled="busy"
                        @click="compose(doc)"
                        >Replace</Button
                    ><Button
                        v-if="referrals.canIssue && doc.status === 'current'"
                        variant="outline"
                        :disabled="busy"
                        @click="voiding = doc"
                        >Void</Button
                    >
                </div>
            </article>
            <nav aria-label="Referral pages" class="flex gap-3">
                <a
                    v-if="referrals.page > 1"
                    :href="`${endpoint}?page=${referrals.page - 1}`"
                    >Previous</a
                ><a
                    v-if="referrals.hasMore"
                    :href="`${endpoint}?page=${referrals.page + 1}`"
                    >Next</a
                >
            </nav>
        </section>
        <section
            v-if="voiding && !branchChanged"
            role="dialog"
            aria-labelledby="void-title"
            class="rounded-lg border bg-card p-4"
        >
            <h2 id="void-title" class="font-semibold">
                Void {{ voiding.number }}?
            </h2>
            <p>
                This closes this referral's chain. The retained evidence cannot
                be deleted.
            </p>
            <label class="block"
                >Category<select
                    v-model="form.change_reason"
                    class="my-2 block w-full rounded-md border bg-background p-2"
                >
                    <option value="details_corrected">Details corrected</option>
                    <option value="entered_in_error">Entered in error</option>
                    <option value="patient_request">Patient request</option>
                </select></label
            >
            <div class="flex gap-2">
                <Button :disabled="busy" @click="voidReferral"
                    >Confirm void</Button
                ><Button variant="outline" @click="voiding = null"
                    >Cancel</Button
                >
            </div>
        </section>
    </main>
</template>
