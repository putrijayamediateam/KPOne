<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useDisciplineCasePage } from '@/composables/hr/useDisciplineCasePage';
import type { DisciplineCasePageProps } from '@/composables/hr/useDisciplineCasePage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 of one restricted discipline case (shown only in V2; Classic is `pages/HR/DisciplineCase.vue`, untouched).
 * Same props, routes, permissions, lock versions and idempotency keys: every request comes from
 * `useDisciplineCasePage`, which Classic uses too, so this page sends none of its own. This is a restricted staff
 * record. Opening the page is recorded by the server before anything is sent, and the allegation, entries and outcome
 * arrive in the props exactly as in Classic (only for people the server allows). Nothing is kept in the browser, files
 * open only through the existing recorded links (a new tab, never a thumbnail), and no case text is used in a title or
 * a label. KPOne never ends employment or changes an account.
 */
const props = defineProps<DisciplineCasePageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Discipline', href: '/hr/discipline' },
    { title: 'Case' },
]);

const {
    errors,
    failure,
    isOpen,
    base,
    size,
    textareaClass,
    note,
    addNote,
    reply,
    addReply,
    link,
    linkLetter,
    chooser,
    upload,
    addFile,
    outcome,
    chosen,
    closeBlocked,
    close,
} = useDisciplineCasePage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
// Plain links in the page are 44 px tall below 1024 (a tap target), as the buttons are.
const linkClass =
    'inline-flex items-center underline underline-offset-4 max-lg:min-h-11';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const description = computed(
    () =>
        `Happened ${props.case.incidentOn} · opened by ${props.case.openedBy} on ${props.case.openedOn}. Restricted: this page and every file opened here are recorded.`,
);
const noteReason = computed(() =>
    note.text.trim() === '' ? 'Write the note to continue.' : '',
);
const linkReason = computed(() =>
    link.letter === '' ? 'Choose a letter to continue.' : '',
);
const replyReason = computed(() =>
    reply.text.trim() === ''
        ? 'Type the reply to continue.'
        : !reply.receivedOn
          ? 'Choose the date received to continue.'
          : '',
);
const fileReason = computed(() =>
    upload.file ? '' : 'Choose a file to continue.',
);
const closeReason = computed(() => closeBlocked.value ?? '');
const openClose = () => {
    outcome.code = '';
    outcome.letter = '';
    outcome.note = '';
    outcome.open = true;
};
</script>

<template>
    <Head title="Discipline case" />
    <PageFrame>
        <V2WorkLayout :title="props.case.person" :description="description">
            <template #actions>
                <Link href="/hr/discipline" :class="button"
                    >Back to discipline</Link
                >
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <V2Chip
                            :tone="isOpen ? 'warning' : 'neutral'"
                            :label="isOpen ? 'Open' : 'Closed'"
                        />
                    </div>
                    <SyntheticNote />
                    <p
                        v-if="failure && !outcome.open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <section
                        aria-labelledby="v2-allegation-title"
                        :class="cardClass"
                        data-test="case-allegation"
                    >
                        <h2
                            id="v2-allegation-title"
                            class="text-sm font-semibold"
                        >
                            Allegation
                        </h2>
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{
                                props.case.allegation ??
                                'This text cannot be read.'
                            }}
                        </p>
                    </section>

                    <section
                        v-if="props.case.outcome"
                        aria-labelledby="v2-outcome-title"
                        :class="cardClass"
                        data-test="case-outcome"
                    >
                        <h2 id="v2-outcome-title" class="text-sm font-semibold">
                            Outcome
                        </h2>
                        <p class="font-medium">
                            {{ props.case.outcome.label }}
                        </p>
                        <p v-if="props.case.outcome.letter" class="text-sm">
                            Letter
                            <Link
                                :href="`/hr/letters/${props.case.outcome.letter.id}`"
                                :class="linkClass"
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
                            By {{ props.case.outcome.by }} on
                            {{ props.case.outcome.on }}. KPOne changed no
                            account and ended no employment.
                        </p>
                    </section>

                    <section
                        aria-labelledby="v2-timeline-title"
                        class="flex flex-col gap-2"
                    >
                        <h2
                            id="v2-timeline-title"
                            class="text-sm font-semibold"
                        >
                            Timeline
                        </h2>
                        <ol
                            class="flex flex-col gap-3"
                            data-test="case-timeline"
                        >
                            <li
                                v-for="(e, i) in props.case.timeline"
                                :key="i"
                                :class="cardClass"
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
                                        :class="linkClass"
                                        >{{ e.letter.reference }}</Link
                                    ><template v-if="e.letter.void">
                                        (void)</template
                                    >
                                </p>
                                <p
                                    v-if="e.file"
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <a
                                        :href="`${base}/files/${e.file.id}`"
                                        target="_blank"
                                        rel="noopener"
                                        :class="button"
                                        >Open
                                        {{
                                            e.file.kind === 'pdf'
                                                ? 'PDF'
                                                : 'photo'
                                        }}</a
                                    >
                                    <span>{{ size(e.file.bytes) }}</span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ e.by }}, {{ e.on }}
                                </p>
                            </li>
                        </ol>
                    </section>

                    <template v-if="isOpen">
                        <!-- Note -->
                        <section
                            aria-labelledby="v2-add-note-title"
                            :class="cardClass"
                        >
                            <h2
                                id="v2-add-note-title"
                                class="text-sm font-semibold"
                            >
                                Add a note
                            </h2>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Note"
                                :error="errors.note"
                            >
                                <textarea
                                    :id="id"
                                    v-model="note.text"
                                    rows="3"
                                    :maxlength="props.case.limits.note"
                                    :class="textareaClass"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </V2Field>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        note.processing || noteReason !== ''
                                    "
                                    :aria-describedby="
                                        noteReason ? 'v2-note-hint' : undefined
                                    "
                                    data-test="case-add-note"
                                    @click="addNote"
                                >
                                    Add note
                                </button>
                                <span
                                    v-if="noteReason"
                                    id="v2-note-hint"
                                    class="text-sm text-muted-foreground"
                                    >{{ noteReason }}</span
                                >
                            </div>
                        </section>

                        <!-- Show-cause letter -->
                        <section
                            aria-labelledby="v2-link-title"
                            :class="cardClass"
                        >
                            <h2
                                id="v2-link-title"
                                class="text-sm font-semibold"
                            >
                                Link a show-cause letter
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Issue the letter in HR letters first; it then
                                shows here.
                            </p>
                            <template v-if="props.case.showCauseLetters.length">
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-link-letter-label"
                                        class="text-sm font-medium"
                                        >Show-cause letter</label
                                    >
                                    <OperationalSelect
                                        :model-value="link.letter"
                                        :options="
                                            props.case.showCauseLetters.map(
                                                (l) => ({
                                                    value: l.id,
                                                    label: `${l.reference}, issued ${l.issuedOn}`,
                                                }),
                                            )
                                        "
                                        label="Show-cause letter"
                                        labelledby="v2-link-letter-label"
                                        placeholder="Choose a letter"
                                        trigger-class="max-lg:h-11"
                                        @update:model-value="
                                            (v) => (link.letter = String(v))
                                        "
                                    />
                                    <p
                                        v-if="errors.letter_id"
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errors.letter_id }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="
                                            link.processing || linkReason !== ''
                                        "
                                        :aria-describedby="
                                            linkReason
                                                ? 'v2-link-hint'
                                                : undefined
                                        "
                                        @click="linkLetter"
                                    >
                                        Link letter
                                    </button>
                                    <span
                                        v-if="linkReason"
                                        id="v2-link-hint"
                                        class="text-sm text-muted-foreground"
                                        >{{ linkReason }}</span
                                    >
                                </div>
                            </template>
                            <p v-else class="text-sm">
                                No current show-cause letter for
                                {{ props.case.person }} that is not already
                                linked.
                            </p>
                        </section>

                        <!-- Reply -->
                        <section
                            aria-labelledby="v2-reply-title"
                            :class="cardClass"
                        >
                            <h2
                                id="v2-reply-title"
                                class="text-sm font-semibold"
                            >
                                Record the staff member's reply
                            </h2>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Reply, as received"
                                :error="errors.reply"
                            >
                                <textarea
                                    :id="id"
                                    v-model="reply.text"
                                    rows="4"
                                    :maxlength="props.case.limits.reply"
                                    :class="textareaClass"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </V2Field>
                            <V2DateField
                                v-model="reply.receivedOn"
                                label="Date received"
                                required
                                :max="props.case.today"
                                :error="errors.received_on"
                            />
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        reply.processing || replyReason !== ''
                                    "
                                    :aria-describedby="
                                        replyReason
                                            ? 'v2-reply-hint'
                                            : undefined
                                    "
                                    @click="addReply"
                                >
                                    Record reply
                                </button>
                                <span
                                    v-if="replyReason"
                                    id="v2-reply-hint"
                                    class="text-sm text-muted-foreground"
                                    >{{ replyReason }}</span
                                >
                            </div>
                        </section>

                        <!-- File -->
                        <section
                            aria-labelledby="v2-file-title"
                            :class="cardClass"
                        >
                            <h2
                                id="v2-file-title"
                                class="text-sm font-semibold"
                            >
                                Add a file
                            </h2>
                            <p
                                id="v2-case-file-help"
                                class="text-sm text-muted-foreground"
                            >
                                PDF, JPG or PNG, at most 10 MB. Files stay with
                                this case only; they are not in the staff file's
                                documents. {{ props.case.fileCount }} of
                                {{ props.case.limits.files }} used.
                            </p>
                            <FileChooseButton
                                ref="chooser"
                                accept="application/pdf,image/jpeg,image/png"
                                :label="
                                    upload.file
                                        ? 'Choose another file'
                                        : 'Choose file'
                                "
                                :status="
                                    upload.file
                                        ? upload.file.name
                                        : 'No file chosen'
                                "
                                status-id="v2-case-file-state"
                                aria-describedby="v2-case-file-help"
                                @change="
                                    upload.file =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                            <p
                                v-if="errors.file"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ errors.file }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        upload.processing || fileReason !== ''
                                    "
                                    :aria-describedby="
                                        fileReason ? 'v2-file-hint' : undefined
                                    "
                                    @click="addFile"
                                >
                                    Add file
                                </button>
                                <span
                                    v-if="fileReason"
                                    id="v2-file-hint"
                                    class="text-sm text-muted-foreground"
                                    >{{ fileReason }}</span
                                >
                            </div>
                        </section>

                        <!-- Close -->
                        <section
                            aria-labelledby="v2-close-title"
                            :class="cardClass"
                            data-test="case-close-card"
                        >
                            <h2
                                id="v2-close-title"
                                class="text-sm font-semibold"
                            >
                                Close the case
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Record the one outcome. The case then closes and
                                nothing more can be added.
                            </p>
                            <div>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="case-close"
                                    @click="openClose"
                                >
                                    Record outcome
                                </button>
                            </div>
                        </section>
                    </template>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="outcome.open"
            title="Record the outcome?"
            description="This closes the case. Nothing more can be added, and the outcome cannot be changed. KPOne does not end employment or change the account."
            confirm-label="Record outcome and close"
            cancel-label="Keep open"
            tone="danger"
            :busy="outcome.processing"
            :confirm-disabled="closeReason !== ''"
            :confirm-disabled-reason="closeReason"
            @update:open="(open: boolean) => (outcome.open = open)"
            @confirm="close"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-1.5">
                <label id="v2-outcome-label" class="text-sm font-medium"
                    >Outcome <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="outcome.code"
                    :options="
                        props.case.outcomes.map((o) => ({
                            value: o.code,
                            label: o.label,
                        }))
                    "
                    label="Outcome"
                    labelledby="v2-outcome-label"
                    placeholder="Choose the outcome"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (outcome.code = String(v))"
                />
                <p
                    v-if="errors.outcome"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.outcome }}
                </p>
            </div>
            <template v-if="chosen?.needsLetter">
                <div
                    v-if="props.case.warningLetters.length"
                    class="grid gap-1.5"
                >
                    <label
                        id="v2-outcome-letter-label"
                        class="text-sm font-medium"
                        >Warning letter <span aria-hidden="true">*</span></label
                    >
                    <OperationalSelect
                        :model-value="outcome.letter"
                        :options="
                            props.case.warningLetters.map((l) => ({
                                value: l.id,
                                label: `${l.reference}, issued ${l.issuedOn}`,
                            }))
                        "
                        label="Warning letter"
                        labelledby="v2-outcome-letter-label"
                        placeholder="Choose the letter"
                        trigger-class="max-lg:h-11"
                        @update:model-value="
                            (v) => (outcome.letter = String(v))
                        "
                    />
                </div>
                <p v-else class="text-sm">
                    Issue the warning letter in
                    <Link href="/hr/letters?tab=issued" :class="linkClass"
                        >HR letters</Link
                    >
                    first.
                </p>
                <p
                    v-if="errors.letter_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.letter_id }}
                </p>
            </template>
            <V2Field v-slot="{ id, describedBy }" label="Note (optional)">
                <textarea
                    :id="id"
                    v-model="outcome.note"
                    rows="3"
                    :maxlength="props.case.limits.outcomeNote"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
        </V2Dialog>
    </PageFrame>
</template>
