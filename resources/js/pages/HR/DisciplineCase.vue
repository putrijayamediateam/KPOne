<script setup lang="ts">
// HR-10c /hr/discipline/{id}: one restricted case. Opening this page is recorded before anything is shown. Entries are
// added, never edited; one outcome closes the case. KPOne never ends employment or changes an account.
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';

type LetterRef = { id: string; reference: string; void: boolean } | null;
type LetterOption = { id: string; reference: string; issuedOn: string | null };
type Entry = {
    kind: string;
    label: string;
    body: string | null;
    occurredOn: string | null;
    letter: LetterRef;
    file: { id: string; kind: string; bytes: number } | null;
    by: string;
    on: string | null;
};

const props = defineProps<{
    case: {
        id: string;
        person: string;
        status: 'open' | 'closed';
        allegation: string | null;
        incidentOn: string;
        openedBy: string;
        openedOn: string | null;
        outcome: {
            code: string;
            label: string;
            letter: LetterRef;
            note: string | null;
            by: string;
            on: string | null;
        } | null;
        timeline: Entry[];
        showCauseLetters: LetterOption[];
        warningLetters: LetterOption[];
        outcomes: { code: string; label: string; needsLetter: boolean }[];
        fileCount: number;
        limits: {
            note: number;
            reply: number;
            outcomeNote: number;
            files: number;
        };
        lockVersion: number;
        today: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.case ?? errors.value.records);
const isOpen = computed(() => props.case.status === 'open');
const base = computed(() => `/hr/discipline/${props.case.id}`);
const size = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
const post = (
    path: string,
    data: Record<string, FormDataConvertible>,
    done: () => void,
    busy: { processing: boolean },
    forceFormData = false,
) => {
    busy.processing = true;
    router.post(`${base.value}/${path}`, data, {
        preserveScroll: true,
        forceFormData,
        onSuccess: done,
        onFinish: () => (busy.processing = false),
    });
};

// ---- Note ----
const note = reactive({
    text: '',
    key: crypto.randomUUID(),
    processing: false,
});
const addNote = () =>
    post(
        'notes',
        { note: note.text, idempotency_key: note.key },
        () => {
            note.text = '';
            note.key = crypto.randomUUID();
        },
        note,
    );

// ---- Reply ----
const reply = reactive({
    text: '',
    receivedOn: props.case.today,
    key: crypto.randomUUID(),
    processing: false,
});
const addReply = () =>
    post(
        'replies',
        {
            reply: reply.text,
            received_on: reply.receivedOn,
            idempotency_key: reply.key,
        },
        () => {
            reply.text = '';
            reply.key = crypto.randomUUID();
        },
        reply,
    );

// ---- Show-cause letter ----
const link = reactive({
    letter: '',
    key: crypto.randomUUID(),
    processing: false,
});
const linkLetter = () =>
    post(
        'show-cause',
        { letter_id: link.letter, idempotency_key: link.key },
        () => {
            link.letter = '';
            link.key = crypto.randomUUID();
        },
        link,
    );

// ---- File ----
const fileInput = ref<HTMLInputElement | null>(null);
const upload = reactive({
    file: null as File | null,
    key: crypto.randomUUID(),
    processing: false,
});
const addFile = () =>
    post(
        'files',
        { file: upload.file, idempotency_key: upload.key },
        () => {
            upload.file = null;
            upload.key = crypto.randomUUID();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
        upload,
        true,
    );

// ---- Outcome ----
const outcome = reactive({
    open: false,
    code: '',
    letter: '',
    note: '',
    key: crypto.randomUUID(),
    processing: false,
});
const chosen = computed(() =>
    props.case.outcomes.find((o) => o.code === outcome.code),
);
const close = () =>
    post(
        'close',
        {
            outcome: outcome.code,
            letter_id: chosen.value?.needsLetter ? outcome.letter : null,
            note: outcome.note,
            expected_lock_version: props.case.lockVersion,
            idempotency_key: outcome.key,
        },
        () => (outcome.open = false),
        outcome,
    );
</script>

<template>
    <Head title="Discipline case" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 lg:p-6">
        <Link
            href="/hr/discipline"
            class="text-sm underline underline-offset-4 max-lg:py-2"
            >Back to discipline</Link
        >
        <PageHeader eyebrow="KPOne HR · Discipline" :title="props.case.person">
            <template #title-extra>
                <StatusBadge
                    :status="props.case.status"
                    :label="isOpen ? 'Open' : 'Closed'"
                    :tone="isOpen ? 'warning' : 'neutral'"
                />
            </template>
            <template #description>
                Happened {{ props.case.incidentOn }} · opened by
                {{ props.case.openedBy }} on {{ props.case.openedOn }}.
                Restricted: this page and every file opened here are recorded.
            </template>
        </PageHeader>
        <SyntheticNote />
        <ErrorState v-if="failure && !outcome.open" :message="failure" />

        <section
            aria-labelledby="allegation-title"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            data-test="case-allegation"
        >
            <h2 id="allegation-title" class="text-sm font-semibold">
                Allegation
            </h2>
            <p class="text-sm break-words whitespace-pre-wrap">
                {{ props.case.allegation ?? 'This text cannot be read.' }}
            </p>
        </section>

        <section
            v-if="props.case.outcome"
            aria-labelledby="outcome-title"
            class="flex flex-col gap-1 rounded-xl border bg-card p-4"
            data-test="case-outcome"
        >
            <h2 id="outcome-title" class="text-sm font-semibold">Outcome</h2>
            <p class="font-medium">{{ props.case.outcome.label }}</p>
            <p v-if="props.case.outcome.letter" class="text-sm">
                Letter
                <Link
                    :href="`/hr/letters/${props.case.outcome.letter.id}`"
                    class="underline underline-offset-4"
                    >{{ props.case.outcome.letter.reference }}</Link
                >
            </p>
            <p
                v-if="props.case.outcome.note"
                class="text-sm break-words whitespace-pre-wrap"
            >
                {{ props.case.outcome.note }}
            </p>
            <p class="text-sm text-muted-foreground">
                By {{ props.case.outcome.by }} on {{ props.case.outcome.on }}.
                KPOne changed no account and ended no employment.
            </p>
        </section>

        <section aria-labelledby="timeline-title" class="flex flex-col gap-2">
            <h2 id="timeline-title" class="text-sm font-semibold">Timeline</h2>
            <ol
                class="divide-y rounded-xl border bg-card"
                data-test="case-timeline"
            >
                <li
                    v-for="(e, i) in props.case.timeline"
                    :key="i"
                    class="flex flex-col gap-1 p-4"
                >
                    <p class="text-sm font-medium">
                        {{ e.label
                        }}<template v-if="e.occurredOn">
                            · received {{ e.occurredOn }}</template
                        >
                    </p>
                    <p
                        v-if="e.body"
                        class="text-sm break-words whitespace-pre-wrap"
                    >
                        {{ e.body }}
                    </p>
                    <p
                        v-if="e.letter && e.kind === 'show_cause'"
                        class="text-sm"
                    >
                        <Link
                            :href="`/hr/letters/${e.letter.id}`"
                            class="underline underline-offset-4"
                            >{{ e.letter.reference }}</Link
                        ><template v-if="e.letter.void"> (void)</template>
                    </p>
                    <p v-if="e.file" class="text-sm">
                        <a
                            :href="`${base}/files/${e.file.id}`"
                            target="_blank"
                            rel="noopener"
                            class="underline underline-offset-4"
                            >Open
                            {{ e.file.kind === 'pdf' ? 'PDF' : 'photo' }}</a
                        >
                        · {{ size(e.file.bytes) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ e.by }}, {{ e.on }}
                    </p>
                </li>
            </ol>
        </section>

        <template v-if="isOpen">
            <section
                aria-labelledby="add-note-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            >
                <h2 id="add-note-title" class="text-sm font-semibold">
                    Add a note
                </h2>
                <Label for="case-note" class="sr-only">Note</Label>
                <textarea
                    id="case-note"
                    v-model="note.text"
                    rows="3"
                    :maxlength="props.case.limits.note"
                    :class="textareaClass"
                    :aria-invalid="errors.note ? 'true' : undefined"
                ></textarea>
                <InputError :message="errors.note" />
                <div>
                    <Button
                        class="max-lg:min-h-11"
                        :disabled="note.processing || !note.text.trim()"
                        data-test="case-add-note"
                        @click="addNote"
                        >Add note</Button
                    >
                </div>
            </section>

            <section
                aria-labelledby="link-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            >
                <h2 id="link-title" class="text-sm font-semibold">
                    Link a show-cause letter
                </h2>
                <p class="text-sm text-muted-foreground">
                    Issue the letter in HR letters first; it then shows here.
                </p>
                <template v-if="props.case.showCauseLetters.length">
                    <Label id="link-letter-label" class="sr-only"
                        >Show-cause letter</Label
                    >
                    <OperationalSelect
                        :model-value="link.letter"
                        :options="
                            props.case.showCauseLetters.map((l) => ({
                                value: l.id,
                                label: `${l.reference}, issued ${l.issuedOn}`,
                            }))
                        "
                        label="Show-cause letter"
                        labelledby="link-letter-label"
                        placeholder="Choose a letter"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="(v) => (link.letter = String(v))"
                    />
                    <InputError :message="errors.letter_id" />
                    <div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="link.processing || !link.letter"
                            @click="linkLetter"
                            >Link letter</Button
                        >
                    </div>
                </template>
                <p v-else class="text-sm">
                    No current show-cause letter for {{ props.case.person }}
                    that is not already linked.
                </p>
            </section>

            <section
                aria-labelledby="reply-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            >
                <h2 id="reply-title" class="text-sm font-semibold">
                    Record the staff member's reply
                </h2>
                <Label for="case-reply">Reply, as received</Label>
                <textarea
                    id="case-reply"
                    v-model="reply.text"
                    rows="4"
                    :maxlength="props.case.limits.reply"
                    :class="textareaClass"
                    :aria-invalid="errors.reply ? 'true' : undefined"
                ></textarea>
                <InputError :message="errors.reply" />
                <Label for="case-reply-on">Date received</Label>
                <DateField
                    id="case-reply-on"
                    v-model="reply.receivedOn"
                    :max="props.case.today"
                    :aria-invalid="!!errors.received_on"
                />
                <InputError :message="errors.received_on" />
                <div>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="reply.processing || !reply.text.trim()"
                        @click="addReply"
                        >Record reply</Button
                    >
                </div>
            </section>

            <section
                aria-labelledby="file-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            >
                <h2 id="file-title" class="text-sm font-semibold">
                    Add a file
                </h2>
                <p id="case-file-help" class="text-sm text-muted-foreground">
                    PDF, JPG or PNG, at most 10 MB. Files stay with this case
                    only; they are not in the staff file's documents.
                    {{ props.case.fileCount }} of
                    {{ props.case.limits.files }} used.
                </p>
                <Label for="case-file" class="sr-only">File</Label>
                <input
                    id="case-file"
                    ref="fileInput"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    class="text-sm file:mr-3 file:rounded-md file:border file:bg-transparent file:px-3 file:py-2 max-lg:file:min-h-11"
                    aria-describedby="case-file-help"
                    @change="
                        upload.file =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="errors.file" />
                <div>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="upload.processing || !upload.file"
                        @click="addFile"
                        >Add file</Button
                    >
                </div>
            </section>

            <div>
                <Button
                    class="max-lg:min-h-11"
                    data-test="case-close"
                    @click="
                        outcome.code = '';
                        outcome.letter = '';
                        outcome.note = '';
                        outcome.open = true;
                    "
                    >Record outcome</Button
                >
            </div>
        </template>

        <OperationalConfirmDialog
            :open="outcome.open"
            title="Record the outcome?"
            description="This closes the case. Nothing more can be added, and the outcome cannot be changed. KPOne does not end employment or change the account."
            destructive
            confirm-label="Record outcome and close"
            cancel-label="Keep open"
            processing-label="Recording…"
            :processing="outcome.processing"
            :error="failure"
            @update:open="(v: boolean) => (outcome.open = v)"
            @confirm="close"
        >
            <div class="flex flex-col gap-2">
                <Label id="outcome-label">Outcome</Label>
                <OperationalSelect
                    :model-value="outcome.code"
                    :options="
                        props.case.outcomes.map((o) => ({
                            value: o.code,
                            label: o.label,
                        }))
                    "
                    label="Outcome"
                    labelledby="outcome-label"
                    placeholder="Choose the outcome"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (outcome.code = String(v))"
                />
                <InputError :message="errors.outcome" />
                <template v-if="chosen?.needsLetter">
                    <Label id="outcome-letter-label">Warning letter</Label>
                    <OperationalSelect
                        v-if="props.case.warningLetters.length"
                        :model-value="outcome.letter"
                        :options="
                            props.case.warningLetters.map((l) => ({
                                value: l.id,
                                label: `${l.reference}, issued ${l.issuedOn}`,
                            }))
                        "
                        label="Warning letter"
                        labelledby="outcome-letter-label"
                        placeholder="Choose the letter"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="
                            (v) => (outcome.letter = String(v))
                        "
                    />
                    <p v-else class="text-sm">
                        Issue the warning letter in HR letters first.
                    </p>
                    <InputError :message="errors.letter_id" />
                </template>
                <Label for="outcome-note">Note (optional)</Label>
                <textarea
                    id="outcome-note"
                    v-model="outcome.note"
                    rows="3"
                    :maxlength="props.case.limits.outcomeNote"
                    :class="textareaClass"
                ></textarea>
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
