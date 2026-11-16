<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useIncidentPage } from '@/composables/hr/useIncidentPage';
import type { IncidentPageProps } from '@/composables/hr/useIncidentPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 of one incident report (shown only in V2; Classic is `pages/HR/Incident.vue`, untouched). Same props,
 * routes, permissions, lock versions and idempotency keys: every request comes from `useIncidentPage`, which Classic
 * uses too, so this page sends none of its own. This is a sensitive staff record. The report text, HR notes and outcome
 * arrive in the props exactly as in Classic (the server decides who may read them; a reporter never gets the notes or
 * the outcome), nothing is kept in the browser, and photos open only through the existing links, never as thumbnails.
 */
const props = defineProps<IncidentPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Incidents', href: '/hr/incidents' },
    { title: props.incident.categoryLabel },
]);

const {
    errors,
    failure,
    r,
    base,
    tone,
    textareaClass,
    ack,
    acknowledge,
    note,
    addNote,
    action,
    actionMissing,
    addAction,
    doneBusy,
    markDone,
    link,
    linkCase,
    closing,
    openActions,
    close,
} = useIncidentPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const statusTone = computed(() =>
    tone.value === 'accent' ? 'info' : tone.value,
);
const ownerOptions = computed(() =>
    (r.value.owners ?? []).map((o) => ({ value: String(o.id), label: o.name })),
);
const description = computed(
    () =>
        `${r.value.branch} · happened ${r.value.occurredAt} · reported ${r.value.reportedOn}` +
        (r.value.manager && r.value.reporter ? ` by ${r.value.reporter}` : '') +
        '.',
);
const noteReason = computed(() =>
    note.text.trim() === '' ? 'Write the note to continue.' : '',
);
const linkReason = computed(() =>
    link.caseId === '' ? 'Choose a case to continue.' : '',
);
const closeReason = computed(() =>
    closing.outcome.trim() === '' ? 'Write the outcome to continue.' : '',
);
</script>

<template>
    <Head title="Incident report" />
    <PageFrame>
        <V2WorkLayout :title="r.categoryLabel" :description="description">
            <template #actions>
                <Link
                    :href="
                        r.manager ? '/hr/incidents' : '/hr/incidents?tab=mine'
                    "
                    :class="button"
                    >Back to incidents</Link
                >
                <button
                    v-if="r.manager && r.can?.acknowledge"
                    type="button"
                    :class="primaryButton"
                    :disabled="ack.processing"
                    data-test="incident-acknowledge"
                    @click="acknowledge"
                >
                    Acknowledge
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <V2Chip :tone="statusTone" :label="r.statusLabel" />
                        <V2Chip
                            v-if="r.needsClinicalReview"
                            tone="warning"
                            label="Needs clinical review"
                        />
                        <V2Chip
                            v-if="r.directorOnly"
                            tone="neutral"
                            label="Director only"
                        />
                    </div>
                    <SyntheticNote />
                    <p
                        v-if="failure && !closing.open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <p
                        v-if="r.ownRefusal"
                        :class="`${cardClass} text-sm`"
                        data-test="incident-own"
                    >
                        {{ r.ownRefusal }}
                    </p>

                    <section aria-labelledby="v2-what-title" :class="cardClass">
                        <h2 id="v2-what-title" class="text-sm font-semibold">
                            What happened
                        </h2>
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{ r.description ?? 'This text cannot be read.' }}
                        </p>
                        <ul
                            v-if="r.photos.length"
                            class="flex flex-wrap gap-2 text-sm"
                        >
                            <li v-for="(p, i) in r.photos" :key="p.id">
                                <a
                                    :href="`${base}/photos/${p.id}`"
                                    target="_blank"
                                    rel="noopener"
                                    :class="button"
                                    >Photo {{ i + 1 }}</a
                                >
                            </li>
                        </ul>
                    </section>

                    <section
                        v-if="!r.manager"
                        aria-labelledby="v2-status-title"
                        :class="cardClass"
                        data-test="incident-status"
                    >
                        <h2 id="v2-status-title" class="text-sm font-semibold">
                            Status
                        </h2>
                        <p class="text-sm">
                            <template v-if="r.status === 'reported'"
                                >HR has your report and will look at
                                it.</template
                            >
                            <template v-else-if="r.status === 'acknowledged'"
                                >HR acknowledged your report on
                                {{ r.acknowledgedOn }}.</template
                            >
                            <template v-else
                                >HR closed your report on
                                {{ r.closedOn }}.</template
                            >
                        </p>
                        <p class="text-sm text-muted-foreground">
                            HR's notes and actions are not shown here. Ask HR if
                            you need to know more.
                        </p>
                    </section>

                    <template v-if="r.manager">
                        <p
                            v-if="!r.can?.acknowledge && r.acknowledgedOn"
                            class="text-sm text-muted-foreground"
                        >
                            Acknowledged by {{ r.acknowledgedBy }} on
                            {{ r.acknowledgedOn }}.
                        </p>

                        <section
                            v-if="r.outcome"
                            aria-labelledby="v2-outcome-title"
                            :class="cardClass"
                            data-test="incident-outcome"
                        >
                            <h2
                                id="v2-outcome-title"
                                class="text-sm font-semibold"
                            >
                                Outcome
                            </h2>
                            <p class="text-sm break-words whitespace-pre-wrap">
                                {{ r.outcome }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Closed by {{ r.closedBy }} on {{ r.closedOn }}.
                            </p>
                        </section>

                        <!-- Actions -->
                        <section
                            aria-labelledby="v2-actions-title"
                            :class="cardClass"
                            data-test="incident-actions"
                        >
                            <h2
                                id="v2-actions-title"
                                class="text-sm font-semibold"
                            >
                                Actions
                            </h2>
                            <ul
                                v-if="r.actions?.length"
                                class="flex flex-col gap-3"
                            >
                                <li
                                    v-for="a in r.actions"
                                    :key="a.id"
                                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-3 last:border-b-0 last:pb-0"
                                >
                                    <div class="min-w-0">
                                        <p class="text-sm break-words">
                                            {{ a.description }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ a.owner }} · due {{ a.dueOn
                                            }}<template v-if="a.done">
                                                · done {{ a.doneOn }} by
                                                {{ a.doneBy }}</template
                                            >
                                        </p>
                                    </div>
                                    <V2Chip
                                        v-if="a.done"
                                        tone="success"
                                        label="Done"
                                    />
                                    <div
                                        v-else
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <V2Chip
                                            v-if="a.overdue"
                                            tone="warning"
                                            label="Overdue"
                                        />
                                        <button
                                            v-if="r.can?.add"
                                            type="button"
                                            :class="button"
                                            :disabled="doneBusy.processing"
                                            @click="markDone(a)"
                                        >
                                            Mark done<span class="sr-only">
                                                : {{ a.description }}</span
                                            >
                                        </button>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No actions yet.
                            </p>
                            <div
                                v-if="r.can?.add"
                                class="flex flex-col gap-3 border-t pt-3"
                            >
                                <V2Field
                                    v-slot="{ id, describedBy }"
                                    label="New action"
                                    :error="errors.action"
                                >
                                    <input
                                        :id="id"
                                        v-model="action.text"
                                        maxlength="300"
                                        :class="inputClass"
                                        :aria-describedby="describedBy"
                                    />
                                </V2Field>
                                <div
                                    class="grid gap-3 sm:grid-cols-2 sm:items-start"
                                >
                                    <div class="grid gap-1.5">
                                        <label
                                            id="v2-action-owner-label"
                                            class="text-sm font-medium"
                                            >Who</label
                                        >
                                        <OperationalSelect
                                            :model-value="action.owner"
                                            :options="ownerOptions"
                                            label="Who"
                                            labelledby="v2-action-owner-label"
                                            placeholder="Choose a person"
                                            trigger-class="max-lg:h-11"
                                            @update:model-value="
                                                (v) =>
                                                    (action.owner = String(v))
                                            "
                                        />
                                        <p
                                            v-if="errors.owner_id"
                                            role="alert"
                                            class="text-sm text-destructive"
                                        >
                                            {{ errors.owner_id }}
                                        </p>
                                    </div>
                                    <V2DateField
                                        v-model="action.due"
                                        label="Due"
                                        :min="r.today"
                                        :error="errors.due_on"
                                    />
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="
                                            action.processing ||
                                            actionMissing !== null
                                        "
                                        :aria-describedby="
                                            actionMissing
                                                ? 'v2-action-hint'
                                                : undefined
                                        "
                                        @click="addAction"
                                    >
                                        Add action
                                    </button>
                                    <span
                                        v-if="actionMissing"
                                        id="v2-action-hint"
                                        class="text-sm text-muted-foreground"
                                        >{{ actionMissing }}</span
                                    >
                                </div>
                            </div>
                        </section>

                        <!-- HR notes -->
                        <section
                            aria-labelledby="v2-notes-title"
                            :class="cardClass"
                            data-test="incident-notes"
                        >
                            <h2
                                id="v2-notes-title"
                                class="text-sm font-semibold"
                            >
                                HR notes
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Not shown to the person who reported it.
                            </p>
                            <ul
                                v-if="r.notes?.length"
                                class="flex flex-col gap-3"
                            >
                                <li
                                    v-for="(n, i) in r.notes"
                                    :key="i"
                                    class="border-b pb-3 last:border-b-0 last:pb-0"
                                >
                                    <p
                                        class="text-sm break-words whitespace-pre-wrap"
                                    >
                                        {{ n.body }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ n.by }}, {{ n.on }}
                                    </p>
                                </li>
                            </ul>
                            <template v-if="r.can?.add">
                                <V2Field
                                    v-slot="{ id, describedBy }"
                                    label="Add a note"
                                    :error="errors.note"
                                >
                                    <textarea
                                        :id="id"
                                        v-model="note.text"
                                        rows="3"
                                        :maxlength="r.maxNote"
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
                                            noteReason
                                                ? 'v2-note-hint'
                                                : undefined
                                        "
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
                            </template>
                        </section>

                        <!-- Link to a discipline case -->
                        <section
                            v-if="r.link && (r.link.linked || r.link.canLink)"
                            aria-labelledby="v2-link-title"
                            :class="cardClass"
                            data-test="incident-link"
                        >
                            <h2
                                id="v2-link-title"
                                class="text-sm font-semibold"
                            >
                                Discipline case
                            </h2>
                            <p v-if="r.link.linked" class="text-sm">
                                <template v-if="r.link.caseId"
                                    >Linked to
                                    <Link
                                        :href="`/hr/discipline/${r.link.caseId}`"
                                        class="underline underline-offset-4"
                                        >the discipline case</Link
                                    >.</template
                                >
                                <template v-else
                                    >Linked to a discipline case.</template
                                >
                            </p>
                            <template v-else-if="r.link.options.length">
                                <div class="grid gap-1.5">
                                    <label
                                        id="v2-link-case-label"
                                        class="text-sm font-medium"
                                        >Open case</label
                                    >
                                    <OperationalSelect
                                        :model-value="link.caseId"
                                        :options="r.link.options"
                                        label="Open case"
                                        labelledby="v2-link-case-label"
                                        placeholder="Choose a case"
                                        trigger-class="max-lg:h-11"
                                        @update:model-value="
                                            (v) => (link.caseId = String(v))
                                        "
                                    />
                                    <p
                                        v-if="errors.case_id"
                                        role="alert"
                                        class="text-sm text-destructive"
                                    >
                                        {{ errors.case_id }}
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
                                        @click="linkCase"
                                    >
                                        Link to case
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
                                No open case you may handle. Open one in
                                <Link
                                    href="/hr/discipline"
                                    class="underline underline-offset-4"
                                    >Discipline</Link
                                >
                                first.
                            </p>
                        </section>

                        <!-- Close -->
                        <section
                            v-if="r.can?.close"
                            aria-labelledby="v2-close-title"
                            :class="cardClass"
                            data-test="incident-close-card"
                        >
                            <h2
                                id="v2-close-title"
                                class="text-sm font-semibold"
                            >
                                Close the report
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Record the outcome. Nothing more can be added
                                afterwards.
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="openActions > 0"
                                    :aria-describedby="
                                        openActions > 0
                                            ? 'v2-close-hint'
                                            : undefined
                                    "
                                    data-test="incident-close"
                                    @click="
                                        closing.outcome = '';
                                        closing.open = true;
                                    "
                                >
                                    Close report
                                </button>
                                <span
                                    v-if="openActions > 0"
                                    id="v2-close-hint"
                                    class="text-sm text-muted-foreground"
                                    >Mark every action done first.</span
                                >
                            </div>
                        </section>
                    </template>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="closing.open"
            title="Close this report?"
            description="The reporter sees that it is closed, but not the outcome. Nothing more can be added afterwards."
            confirm-label="Close report"
            cancel-label="Keep open"
            tone="danger"
            :busy="closing.processing"
            :confirm-disabled="closeReason !== ''"
            :confirm-disabled-reason="closeReason"
            @update:open="(open: boolean) => (closing.open = open)"
            @confirm="close"
        >
            <p
                v-if="failure ?? errors.outcome"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ failure ?? errors.outcome }}
            </p>
            <V2Field v-slot="{ id, describedBy }" label="Outcome" required>
                <textarea
                    :id="id"
                    v-model="closing.outcome"
                    rows="3"
                    :maxlength="r.maxNote"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
        </V2Dialog>
    </PageFrame>
</template>
