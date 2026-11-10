<script setup lang="ts">
// HR-10d /hr/incidents/{id}: one report. The reporter sees what they sent and its status only. A manager (not the
// reporter) acknowledges, adds notes and actions, links a staff-conduct report to a discipline case, and closes it.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import { ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';

type Status = 'reported' | 'acknowledged' | 'closed';
type Action = {
    id: string;
    description: string | null;
    owner: string;
    dueOn: string;
    overdue: boolean;
    done: boolean;
    doneOn: string | null;
    doneBy: string | null;
    lockVersion: number;
};

const props = defineProps<{
    incident: {
        id: string;
        manager: boolean;
        category: string;
        categoryLabel: string;
        needsClinicalReview: boolean;
        directorOnly: boolean;
        branch: string;
        occurredAt: string | null;
        reportedOn: string | null;
        description: string | null;
        photos: { id: string; kind: string; bytes: number }[];
        status: Status;
        statusLabel: string;
        acknowledgedOn: string | null;
        closedOn: string | null;
        lockVersion: number;
        reporter?: string;
        acknowledgedBy?: string | null;
        closedBy?: string | null;
        outcome?: string | null;
        notes?: { body: string | null; by: string; on: string | null }[];
        actions?: Action[];
        owners?: { id: number; name: string }[];
        link?: {
            linked: boolean;
            caseId: string | null;
            options: { value: string; label: string }[];
            canLink: boolean;
        } | null;
        can?: {
            handle: boolean;
            acknowledge: boolean;
            add: boolean;
            close: boolean;
        };
        ownRefusal?: string | null;
        maxNote?: number;
        today?: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.incident ?? errors.value.records);
const r = computed(() => props.incident);
const base = computed(() => `/hr/incidents/${r.value.id}`);
const tone = computed(() =>
    r.value.status === 'reported'
        ? 'warning'
        : r.value.status === 'acknowledged'
          ? 'accent'
          : 'neutral',
);
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
const post = (
    path: string,
    data: Record<string, string | number | null>,
    busy: { processing: boolean },
    done?: () => void,
) => {
    busy.processing = true;
    router.post(`${base.value}/${path}`, data, {
        preserveScroll: true,
        onSuccess: () => done?.(),
        onFinish: () => (busy.processing = false),
    });
};

const ack = reactive({ key: crypto.randomUUID(), processing: false });
const acknowledge = () =>
    post(
        'acknowledge',
        {
            expected_lock_version: r.value.lockVersion,
            idempotency_key: ack.key,
        },
        ack,
    );

const note = reactive({
    text: '',
    key: crypto.randomUUID(),
    processing: false,
});
const addNote = () =>
    post('notes', { note: note.text, idempotency_key: note.key }, note, () => {
        note.text = '';
        note.key = crypto.randomUUID();
    });

const action = reactive({
    text: '',
    owner: '',
    due: props.incident.today ?? '',
    key: crypto.randomUUID(),
    processing: false,
});
const actionMissing = computed(() =>
    action.text.trim().length < 3
        ? 'Describe the action first.'
        : !action.owner
          ? 'Choose who will do it.'
          : null,
);
const addAction = () =>
    post(
        'actions',
        {
            description: action.text,
            owner_id: action.owner,
            due_on: action.due,
            idempotency_key: action.key,
        },
        action,
        () => {
            action.text = '';
            action.owner = '';
            action.key = crypto.randomUUID();
        },
    );
const doneBusy = reactive({ processing: false });
const markDone = (a: Action) =>
    post(
        `actions/${a.id}/done`,
        {
            expected_lock_version: a.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        doneBusy,
    );

const link = reactive({
    caseId: '',
    key: crypto.randomUUID(),
    processing: false,
});
const linkCase = () =>
    post(
        'link',
        {
            case_id: link.caseId,
            expected_lock_version: r.value.lockVersion,
            idempotency_key: link.key,
        },
        link,
    );

const closing = reactive({
    open: false,
    outcome: '',
    key: crypto.randomUUID(),
    processing: false,
});
const openActions = computed(
    () => (r.value.actions ?? []).filter((a) => !a.done).length,
);
const close = () =>
    post(
        'close',
        {
            outcome: closing.outcome,
            expected_lock_version: r.value.lockVersion,
            idempotency_key: closing.key,
        },
        closing,
        () => (closing.open = false),
    );
</script>

<template>
    <Head title="Incident report" />
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 lg:p-6">
        <Link
            :href="r.manager ? '/hr/incidents' : '/hr/incidents?tab=mine'"
            class="text-sm underline underline-offset-4 max-lg:py-2"
            >Back to incidents</Link
        >
        <PageHeader
            :eyebrow="
                r.manager ? 'KPOne HR · Incident' : 'KPOne HR · My report'
            "
            :title="r.categoryLabel"
        >
            <template #title-extra>
                <StatusBadge
                    :status="r.status"
                    :label="r.statusLabel"
                    :tone="tone"
                />
                <StatusBadge
                    v-if="r.needsClinicalReview"
                    status="clinical"
                    label="Needs clinical review"
                    tone="danger"
                />
                <StatusBadge
                    v-if="r.directorOnly"
                    status="director-only"
                    label="Director only"
                    tone="neutral"
                />
            </template>
            <template #description>
                {{ r.branch }} · happened {{ r.occurredAt }} · reported
                {{ r.reportedOn
                }}<template v-if="r.manager && r.reporter">
                    by {{ r.reporter }}</template
                >.
            </template>
        </PageHeader>
        <SyntheticNote />
        <ErrorState v-if="failure && !closing.open" :message="failure" />
        <p
            v-if="r.ownRefusal"
            class="rounded-xl border bg-card p-4 text-sm"
            data-test="incident-own"
        >
            {{ r.ownRefusal }}
        </p>

        <section
            aria-labelledby="what-title"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
        >
            <h2 id="what-title" class="text-sm font-semibold">What happened</h2>
            <p class="text-sm break-words whitespace-pre-wrap">
                {{ r.description ?? 'This text cannot be read.' }}
            </p>
            <ul v-if="r.photos.length" class="flex flex-wrap gap-3 text-sm">
                <li v-for="(p, i) in r.photos" :key="p.id">
                    <a
                        :href="`${base}/photos/${p.id}`"
                        target="_blank"
                        rel="noopener"
                        class="underline underline-offset-4"
                        >Photo {{ i + 1 }}</a
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="!r.manager"
            aria-labelledby="status-title"
            class="flex flex-col gap-1 rounded-xl border bg-card p-4"
            data-test="incident-status"
        >
            <h2 id="status-title" class="text-sm font-semibold">Status</h2>
            <p class="text-sm">
                <template v-if="r.status === 'reported'"
                    >HR has your report and will look at it.</template
                >
                <template v-else-if="r.status === 'acknowledged'"
                    >HR acknowledged your report on
                    {{ r.acknowledgedOn }}.</template
                >
                <template v-else
                    >HR closed your report on {{ r.closedOn }}.</template
                >
            </p>
            <p class="text-sm text-muted-foreground">
                HR's notes and actions are not shown here. Ask HR if you need to
                know more.
            </p>
        </section>

        <template v-if="r.manager">
            <div v-if="r.can?.acknowledge">
                <Button
                    class="max-lg:min-h-11"
                    :disabled="ack.processing"
                    data-test="incident-acknowledge"
                    @click="acknowledge"
                    >Acknowledge</Button
                >
            </div>
            <p
                v-else-if="r.acknowledgedOn"
                class="text-sm text-muted-foreground"
            >
                Acknowledged by {{ r.acknowledgedBy }} on
                {{ r.acknowledgedOn }}.
            </p>

            <section
                v-if="r.outcome"
                aria-labelledby="outcome-title"
                class="flex flex-col gap-1 rounded-xl border bg-card p-4"
                data-test="incident-outcome"
            >
                <h2 id="outcome-title" class="text-sm font-semibold">
                    Outcome
                </h2>
                <p class="text-sm break-words whitespace-pre-wrap">
                    {{ r.outcome }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Closed by {{ r.closedBy }} on {{ r.closedOn }}.
                </p>
            </section>

            <section
                aria-labelledby="actions-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                data-test="incident-actions"
            >
                <h2 id="actions-title" class="text-sm font-semibold">
                    Actions
                </h2>
                <ul v-if="r.actions?.length" class="divide-y">
                    <li
                        v-for="a in r.actions"
                        :key="a.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <div class="min-w-0">
                            <p class="text-sm break-words">
                                {{ a.description }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ a.owner }} · due {{ a.dueOn
                                }}<template v-if="a.done">
                                    · done {{ a.doneOn }} by
                                    {{ a.doneBy }}</template
                                >
                            </p>
                        </div>
                        <StatusBadge
                            v-if="a.done"
                            status="done"
                            label="Done"
                            tone="success"
                        />
                        <template v-else>
                            <StatusBadge
                                v-if="a.overdue"
                                status="overdue"
                                label="Overdue"
                                tone="warning"
                            />
                            <Button
                                v-if="r.can?.add"
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="doneBusy.processing"
                                @click="markDone(a)"
                                >Mark done<span class="sr-only">
                                    : {{ a.description }}</span
                                ></Button
                            >
                        </template>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    No actions yet.
                </p>
                <form
                    v-if="r.can?.add"
                    class="flex flex-col gap-2 border-t pt-3"
                    @submit.prevent="addAction"
                >
                    <Label for="action-text">New action</Label>
                    <Input
                        id="action-text"
                        v-model="action.text"
                        maxlength="300"
                        class="max-lg:min-h-11"
                        :aria-invalid="!!errors.action"
                    />
                    <InputError :message="errors.action" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label id="action-owner-label">Who</Label>
                            <OperationalSelect
                                :model-value="action.owner"
                                :options="
                                    (r.owners ?? []).map((o) => ({
                                        value: String(o.id),
                                        label: o.name,
                                    }))
                                "
                                label="Who"
                                labelledby="action-owner-label"
                                placeholder="Choose a person"
                                trigger-class="max-lg:min-h-11"
                                @update:model-value="
                                    (v) => (action.owner = String(v))
                                "
                            />
                            <InputError :message="errors.owner_id" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="action-due">Due</Label>
                            <DateField
                                id="action-due"
                                v-model="action.due"
                                :min="r.today"
                                :aria-invalid="!!errors.due_on"
                            />
                            <InputError :message="errors.due_on" />
                        </div>
                    </div>
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="
                                action.processing || actionMissing !== null
                            "
                            >Add action</Button
                        >
                        <p
                            v-if="actionMissing"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            {{ actionMissing }}
                        </p>
                    </div>
                </form>
            </section>

            <section
                aria-labelledby="notes-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                data-test="incident-notes"
            >
                <h2 id="notes-title" class="text-sm font-semibold">HR notes</h2>
                <p class="text-sm text-muted-foreground">
                    Not shown to the person who reported it.
                </p>
                <ul v-if="r.notes?.length" class="divide-y">
                    <li v-for="(n, i) in r.notes" :key="i" class="py-2">
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{ n.body }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ n.by }}, {{ n.on }}
                        </p>
                    </li>
                </ul>
                <template v-if="r.can?.add">
                    <Label for="incident-note" class="sr-only">Note</Label>
                    <textarea
                        id="incident-note"
                        v-model="note.text"
                        rows="3"
                        :maxlength="r.maxNote"
                        :class="textareaClass"
                        :aria-invalid="errors.note ? 'true' : undefined"
                    ></textarea>
                    <InputError :message="errors.note" />
                    <div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="note.processing || !note.text.trim()"
                            @click="addNote"
                            >Add note</Button
                        >
                        <p
                            v-if="!note.text.trim()"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            Write the note first.
                        </p>
                    </div>
                </template>
            </section>

            <section
                v-if="r.link && (r.link.linked || r.link.canLink)"
                aria-labelledby="link-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                data-test="incident-link"
            >
                <h2 id="link-title" class="text-sm font-semibold">
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
                    <template v-else>Linked to a discipline case.</template>
                </p>
                <template v-else-if="r.link.options.length">
                    <Label id="link-case-label">Open case</Label>
                    <OperationalSelect
                        :model-value="link.caseId"
                        :options="r.link.options"
                        label="Open case"
                        labelledby="link-case-label"
                        placeholder="Choose a case"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="(v) => (link.caseId = String(v))"
                    />
                    <InputError :message="errors.case_id" />
                    <div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="link.processing || !link.caseId"
                            @click="linkCase"
                            >Link to case</Button
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

            <section
                v-if="r.can?.close"
                aria-labelledby="close-title"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                data-test="incident-close-card"
            >
                <h2 id="close-title" class="text-sm font-semibold">
                    Close the report
                </h2>
                <p class="text-sm text-muted-foreground">
                    Record the outcome. Nothing more can be added afterwards.
                </p>
                <div>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="openActions > 0"
                        data-test="incident-close"
                        @click="
                            closing.outcome = '';
                            closing.open = true;
                        "
                        >Close report</Button
                    >
                    <p
                        v-if="openActions > 0"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Mark every action done first.
                    </p>
                </div>
            </section>
        </template>

        <OperationalConfirmDialog
            :open="closing.open"
            title="Close this report?"
            description="The reporter sees that it is closed, but not the outcome. Nothing more can be added afterwards."
            destructive
            confirm-label="Close report"
            cancel-label="Keep open"
            processing-label="Closing…"
            :processing="closing.processing"
            :error="failure ?? errors.outcome"
            @update:open="(v: boolean) => (closing.open = v)"
            @confirm="close"
        >
            <div class="flex flex-col gap-2">
                <Label for="incident-outcome">Outcome</Label>
                <textarea
                    id="incident-outcome"
                    v-model="closing.outcome"
                    rows="3"
                    :maxlength="r.maxNote"
                    :class="textareaClass"
                ></textarea>
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
