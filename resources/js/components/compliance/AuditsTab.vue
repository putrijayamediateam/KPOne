<script setup lang="ts">
// HR-11c monthly audits and supervisor tasks. A record of what a person typed, not a certification. Nothing is sent: the
// "last month not audited" line and the open tasks show only when this page is opened. Photos are images only.
import { router, usePage } from '@inertiajs/vue3';
import { Ellipsis, TriangleAlert } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import type { AuditItem, AuditsTabData, TaskRow } from './auditTypes';

const props = defineProps<{ audits: AuditsTabData }>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const open = (audit?: string) =>
    router.get(base, audit ? { tab: 'audits', audit } : { tab: 'audits' }, {
        preserveScroll: !audit,
    });

// ---- Start an audit ----
const start = reactive({
    branch: '',
    month: props.audits.missedLastMonth.length ? 'previous' : 'current',
    processing: false,
});
const branchOptions = computed(() =>
    props.audits.startable.map((b) => ({ value: String(b.id), label: b.name })),
);
const monthOptions = [
    { value: 'current', label: 'This month' },
    { value: 'previous', label: 'Last month' },
];
const startFor = (branch: number | string, month: string) => {
    start.processing = true;
    router.post(
        `${base}/audits`,
        {
            branch_id: branch,
            month,
            idempotency_key: crypto.randomUUID(),
        },
        { onFinish: () => (start.processing = false) },
    );
};
const startAudit = () => startFor(start.branch, start.month);

// ---- Answers on the open audit (kept on screen until saved) ----
type Draft = { result: string; note: string };
const draft = reactive<Record<string, Draft>>({});
const seed = () => {
    Object.keys(draft).forEach((k) => delete draft[k]);
    props.audits.selected?.sections.forEach((s) =>
        s.items.forEach((i) => {
            draft[i.id] = { result: i.result ?? '', note: i.note ?? '' };
        }),
    );
};
watch(() => props.audits.selected?.id, seed, { immediate: true });
const results = [
    { value: 'pass', label: 'Pass' },
    { value: 'fail', label: 'Fail' },
    { value: 'na', label: 'Not applicable' },
];
const items = computed(() =>
    (props.audits.selected?.sections ?? []).flatMap((s) => s.items),
);
const answered = computed(
    () => items.value.filter((i) => draft[i.id]?.result).length,
);
const saving = ref(false);
const saveAnswers = (after?: () => void) => {
    const a = props.audits.selected;

    if (!a) {
        return;
    }

    saving.value = true;
    router.post(
        `${base}/audits/${a.id}/answers`,
        {
            expected_lock_version: a.lockVersion,
            idempotency_key: crypto.randomUUID(),
            answers: items.value
                .filter((i) => draft[i.id]?.result)
                .map((i) => ({
                    item_id: i.id,
                    result: draft[i.id].result,
                    note: draft[i.id].note,
                })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => after?.(),
            onFinish: () => (saving.value = false),
        },
    );
};
const failedCount = computed(
    () => items.value.filter((i) => draft[i.id]?.result === 'fail').length,
);
const submitDescription = computed(() =>
    failedCount.value === 0
        ? 'Submitting is final. No item failed, so no task will be made.'
        : `Submitting is final. ${failedCount.value} failed ${failedCount.value === 1 ? 'item' : 'items'} will become ${failedCount.value === 1 ? 'a task' : 'tasks'} for the branch.`,
);
const confirmSubmit = ref(false);
const submitting = ref(false);
const submitAudit = () => {
    const a = props.audits.selected;

    if (!a) {
        return;
    }

    submitting.value = true;
    router.post(
        `${base}/audits/${a.id}/submit`,
        {
            expected_lock_version: a.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (confirmSubmit.value = false),
            onFinish: () => (submitting.value = false),
        },
    );
};
const startSubmit = () =>
    saveAnswers(() => {
        confirmSubmit.value = true;
    });
const addAuditPhoto = (item: AuditItem, event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const a = props.audits.selected;

    if (!file || !a) {
        return;
    }

    router.post(
        `${base}/audits/${a.id}/items/${item.id}/photos`,
        { photo: file },
        { forceFormData: true, preserveScroll: true, preserveState: true },
    );
    input.value = '';
};
const removeAuditPhoto = (photoId: string) => {
    const a = props.audits.selected;

    if (a) {
        router.post(
            `${base}/audits/${a.id}/photos/${photoId}/remove`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }
};
const needsNote = (i: AuditItem) =>
    draft[i.id]?.result === 'fail' && !draft[i.id]?.note.trim();

// ---- Tasks ----
const done = reactive({
    open: false,
    task: null as TaskRow | null,
    note: '',
    processing: false,
});
const openDone = (t: TaskRow) =>
    Object.assign(done, { open: true, task: t, note: '', processing: false });
const sendDone = () => {
    if (!done.task) {
        return;
    }

    done.processing = true;
    router.post(
        `${base}/tasks/${done.task.id}/done`,
        {
            note: done.note,
            expected_lock_version: done.task.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (done.open = false),
            onFinish: () => (done.processing = false),
        },
    );
};
const verify = (t: TaskRow) =>
    router.post(
        `${base}/tasks/${t.id}/verify`,
        {
            expected_lock_version: t.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );
const cancel = reactive({
    open: false,
    task: null as TaskRow | null,
    reason: '',
    processing: false,
});
const openCancel = (t: TaskRow) =>
    Object.assign(cancel, {
        open: true,
        task: t,
        reason: '',
        processing: false,
    });
const sendCancel = () => {
    if (!cancel.task) {
        return;
    }

    cancel.processing = true;
    router.post(
        `${base}/tasks/${cancel.task.id}/cancel`,
        {
            reason: cancel.reason,
            expected_lock_version: cancel.task.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (cancel.open = false),
            onFinish: () => (cancel.processing = false),
        },
    );
};
const addTaskPhoto = (t: TaskRow, event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    router.post(
        `${base}/tasks/${t.id}/photos`,
        { photo: file },
        { forceFormData: true, preserveScroll: true },
    );
    input.value = '';
};
const statusLabel = (s: string) =>
    s === 'open'
        ? 'Open'
        : s === 'done'
          ? 'Done, waiting to be verified'
          : s === 'submitted'
            ? 'Submitted'
            : s;
</script>

<template>
    <section class="flex flex-col gap-4" aria-label="Audits">
        <ErrorState
            v-if="errors.compliance && !done.open && !cancel.open"
            :message="errors.compliance"
        />

        <div
            v-if="audits.missedLastMonth.length && !audits.selected"
            class="flex flex-col gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm text-warning-strong"
            role="status"
            data-test="missed-month"
        >
            <p class="flex items-start gap-2">
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>No submitted audit for last month.</span>
            </p>
            <ul class="flex flex-wrap gap-2">
                <li v-for="m in audits.missedLastMonth" :key="m.branchId">
                    <Button
                        v-if="m.canStart"
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :disabled="start.processing"
                        @click="startFor(m.branchId, 'previous')"
                        >Start {{ m.branchName }}, {{ m.monthLabel }}</Button
                    >
                    <span
                        v-else
                        class="inline-flex min-h-11 items-center lg:min-h-9"
                        >{{ m.branchName }}, {{ m.monthLabel }}</span
                    >
                </li>
            </ul>
        </div>

        <!-- One audit open on screen -->
        <template v-if="audits.selected">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    @click="open()"
                    >Back to audits</Button
                >
                <p class="text-sm font-medium">
                    {{ audits.selected.branchName }} ·
                    {{ audits.selected.monthLabel }}
                    <StatusBadge
                        :status="audits.selected.status"
                        :label="
                            statusLabel(
                                audits.selected.status === 'open'
                                    ? 'Open'
                                    : 'submitted',
                            )
                        "
                        :tone="
                            audits.selected.status === 'open'
                                ? 'warning'
                                : 'success'
                        "
                    />
                </p>
            </div>
            <p
                v-if="audits.selected.canEdit"
                class="text-sm text-muted-foreground"
            >
                Answer every item, then submit. A failed item needs a note, and
                a photo where the checklist asks for one. Do not photograph
                patients or papers. Submitting is final. {{ answered }} of
                {{ items.length }} answered.
            </p>
            <fieldset
                v-for="s in audits.selected.sections"
                :key="s.title"
                class="flex flex-col gap-3 rounded-lg border bg-card p-4"
            >
                <legend class="px-1 text-sm font-semibold">
                    {{ s.title }}
                </legend>
                <div
                    v-for="i in s.items"
                    :key="i.id"
                    class="flex flex-col gap-2 border-b pb-3 last:border-0 last:pb-0"
                    data-test="audit-item"
                >
                    <p
                        :id="`item-title-${i.id}`"
                        class="text-sm font-medium break-words"
                    >
                        {{ i.title }}
                    </p>
                    <p v-if="i.help" class="text-xs text-muted-foreground">
                        {{ i.help }}
                    </p>
                    <div
                        v-if="audits.selected.canEdit"
                        role="radiogroup"
                        :aria-labelledby="`item-title-${i.id}`"
                        class="flex flex-wrap gap-2"
                    >
                        <label
                            v-for="r in results"
                            :key="r.value"
                            :class="[
                                'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm lg:min-h-9',
                                draft[i.id]?.result === r.value
                                    ? 'border-brand bg-brand/10 font-medium'
                                    : 'bg-card',
                            ]"
                        >
                            <input
                                v-model="draft[i.id].result"
                                type="radio"
                                :name="`result-${i.id}`"
                                :value="r.value"
                                class="size-4"
                            />
                            {{ r.label }}
                        </label>
                    </div>
                    <p v-else class="text-sm">
                        <StatusBadge
                            :status="i.result ?? 'none'"
                            :label="
                                i.result === 'pass'
                                    ? 'Pass'
                                    : i.result === 'fail'
                                      ? 'Fail'
                                      : i.result === 'na'
                                        ? 'Not applicable'
                                        : 'Not answered'
                            "
                            :tone="
                                i.result === 'fail'
                                    ? 'danger'
                                    : i.result === 'pass'
                                      ? 'success'
                                      : 'neutral'
                            "
                        />
                    </p>
                    <div
                        v-if="audits.selected.canEdit && draft[i.id]?.result"
                        class="grid gap-1.5"
                    >
                        <Label :for="`note-${i.id}`"
                            >Note{{
                                draft[i.id].result === 'fail'
                                    ? ' *'
                                    : ' (optional)'
                            }}</Label
                        >
                        <Input
                            :id="`note-${i.id}`"
                            v-model="draft[i.id].note"
                            maxlength="300"
                            class="max-lg:h-11"
                            :aria-invalid="needsNote(i)"
                        />
                        <p
                            v-if="needsNote(i)"
                            class="text-xs text-destructive"
                            role="status"
                        >
                            A failed item needs a note.
                        </p>
                    </div>
                    <p v-else-if="i.note" class="text-sm break-words">
                        {{ i.note }}
                    </p>
                    <p
                        v-if="
                            i.photoRequired &&
                            (draft[i.id]?.result === 'fail' ||
                                i.result === 'fail')
                        "
                        class="text-xs text-muted-foreground"
                    >
                        A photo is needed if this fails.
                    </p>
                    <ul v-if="i.photos.length" class="flex flex-wrap gap-2">
                        <li
                            v-for="p in i.photos"
                            :key="p.id"
                            class="flex items-center gap-1"
                        >
                            <a
                                :href="`${base}/audit-photos/${p.id}`"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex min-h-11 items-center rounded-md border border-input bg-card px-3 text-sm font-medium text-brand-strong hover:bg-muted/50 lg:min-h-9"
                                >{{ p.label
                                }}<span class="sr-only">
                                    of {{ i.title }}</span
                                ></a
                            >
                            <Button
                                v-if="audits.selected.canEdit"
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                @click="removeAuditPhoto(p.id)"
                                >Remove<span class="sr-only">
                                    {{ p.label }} of {{ i.title }}</span
                                ></Button
                            >
                        </li>
                    </ul>
                    <FileChooseButton
                        v-if="
                            audits.selected.canEdit &&
                            i.photos.length < 3 &&
                            (draft[i.id]?.result === 'fail' || i.photoRequired)
                        "
                        :id="`photo-${i.id}`"
                        accept="image/jpeg,image/png"
                        :label="`Add a photo for ${i.title}`"
                        status="JPG or PNG"
                        :status-id="`photo-state-${i.id}`"
                        @change="addAuditPhoto(i, $event)"
                    />
                    <p
                        v-if="i.taskStatus"
                        class="text-xs text-muted-foreground"
                    >
                        Task: {{ statusLabel(i.taskStatus) }}
                    </p>
                </div>
            </fieldset>
            <InputError :message="errors.photo" />
            <div v-if="audits.selected.canEdit" class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    :disabled="saving || !answered"
                    @click="saveAnswers()"
                    >Save answers</Button
                >
                <Button
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    :disabled="saving || answered < items.length"
                    :aria-describedby="
                        answered < items.length ? 'submit-reason' : undefined
                    "
                    data-test="submit-audit"
                    @click="startSubmit"
                    >Submit audit</Button
                >
                <p
                    v-if="answered < items.length"
                    id="submit-reason"
                    class="basis-full text-xs text-muted-foreground"
                >
                    Answer every item to submit ({{ items.length - answered }}
                    left).
                </p>
            </div>
            <OperationalConfirmDialog
                :open="confirmSubmit"
                title="Submit this audit?"
                :description="submitDescription"
                confirm-label="Submit audit"
                processing-label="Submitting…"
                :processing="submitting"
                :error="errors.compliance"
                @update:open="(v: boolean) => (confirmSubmit = v)"
                @confirm="submitAudit"
            />
        </template>

        <!-- Start and list -->
        <template v-else>
            <div
                v-if="audits.canPerform"
                class="flex flex-col gap-3 rounded-lg border bg-card p-4"
            >
                <h2 class="text-sm font-semibold">Start an audit</h2>
                <p
                    v-if="!audits.checklistReady"
                    class="text-sm text-muted-foreground"
                >
                    There is no checklist yet. Ask the Director or Administrator
                    to add it under Settings.
                </p>
                <form
                    v-else
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="startAudit"
                >
                    <div class="grid min-w-44 gap-1.5">
                        <Label for="start-branch"
                            ><span id="start-branch-label"
                                >Branch *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="start-branch"
                            v-model="start.branch"
                            labelledby="start-branch-label"
                            trigger-class="max-lg:h-11"
                            label="Branch"
                            placeholder="Choose"
                            :options="branchOptions"
                            :invalid="!!errors.branch_id"
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="grid min-w-36 gap-1.5">
                        <Label for="start-month"
                            ><span id="start-month-label">Month *</span></Label
                        >
                        <OperationalSelect
                            id="start-month"
                            v-model="start.month"
                            labelledby="start-month-label"
                            trigger-class="max-lg:h-11"
                            label="Month"
                            :options="monthOptions"
                        />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="start.processing || !start.branch"
                        :aria-describedby="
                            start.branch ? undefined : 'start-reason'
                        "
                        data-test="start-audit"
                        >Start audit</Button
                    >
                    <p
                        v-if="!start.branch"
                        id="start-reason"
                        class="basis-full text-xs text-muted-foreground"
                    >
                        Choose a branch to start.
                    </p>
                </form>
            </div>

            <h2
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Audits
            </h2>
            <EmptyState
                v-if="!audits.audits.length"
                title="No audits yet."
                description="Start the first monthly audit above."
            />
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="a in audits.audits"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
                    data-test="audit-row"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-semibold break-words">
                            {{ a.monthLabel }} · {{ a.branchName }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Started by {{ a.startedBy
                            }}<template v-if="a.submittedBy">
                                · Submitted by {{ a.submittedBy }}</template
                            >
                            · {{ a.tasks }} {{ a.tasks === 1 ? 'task' : 'tasks'
                            }}<template v-if="a.unfinishedTasks">
                                ({{ a.unfinishedTasks }} not finished)</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :status="a.status"
                            :label="a.status === 'open' ? 'Open' : 'Submitted'"
                            :tone="a.status === 'open' ? 'warning' : 'success'"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="open(a.id)"
                            >{{
                                a.status === 'open' && audits.canPerform
                                    ? 'Continue'
                                    : 'View'
                            }}<span class="sr-only">
                                {{ a.monthLabel }} {{ a.branchName }}</span
                            ></Button
                        >
                    </div>
                </li>
            </ul>
        </template>

        <!-- Tasks -->
        <h2
            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
        >
            Supervisor tasks
        </h2>
        <p v-if="!audits.tasks.length" class="text-sm text-muted-foreground">
            No open tasks.
        </p>
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="t in audits.tasks"
                :key="t.id"
                class="flex flex-col gap-2 rounded-lg border bg-card p-4"
                data-test="task-row"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="min-w-0 text-sm font-semibold break-words">
                        {{ t.itemTitle
                        }}<span
                            class="block text-xs font-normal text-muted-foreground"
                            >{{ t.branchName }} · {{ t.monthLabel }}</span
                        >
                    </p>
                    <StatusBadge
                        :status="t.status"
                        :label="
                            statusLabel(
                                t.status === 'done' && !t.verifyRequired
                                    ? 'done'
                                    : t.status,
                            )
                        "
                        :tone="t.status === 'open' ? 'warning' : 'neutral'"
                    />
                </div>
                <p
                    v-if="t.doneBy"
                    class="text-xs break-words text-muted-foreground"
                >
                    Done by {{ t.doneBy
                    }}<template v-if="t.doneNote"> · {{ t.doneNote }}</template>
                </p>
                <ul v-if="t.photos.length" class="flex flex-wrap gap-2">
                    <li v-for="p in t.photos" :key="p.id">
                        <a
                            :href="`${base}/task-photos/${p.id}`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex min-h-11 items-center rounded-md border border-input bg-card px-3 text-sm font-medium text-brand-strong hover:bg-muted/50 lg:min-h-9"
                            >{{ p.label }}</a
                        >
                    </li>
                </ul>
                <div v-if="t.canAct" class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="t.status === 'open'"
                        type="button"
                        class="min-h-11 lg:min-h-9"
                        @click="openDone(t)"
                        >Mark done<span class="sr-only">
                            {{ t.itemTitle }}</span
                        ></Button
                    >
                    <Button
                        v-if="
                            t.status === 'done' &&
                            t.verifyRequired &&
                            !t.doneByMe
                        "
                        type="button"
                        class="min-h-11 lg:min-h-9"
                        @click="verify(t)"
                        >Verify<span class="sr-only">
                            {{ t.itemTitle }}</span
                        ></Button
                    >
                    <p
                        v-if="
                            t.status === 'done' &&
                            t.verifyRequired &&
                            t.doneByMe
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Someone else verifies this.
                    </p>
                    <FileChooseButton
                        v-if="t.photos.length < 3"
                        :id="`task-photo-${t.id}`"
                        accept="image/jpeg,image/png"
                        :label="`Add a photo for ${t.itemTitle}`"
                        status="JPG or PNG"
                        :status-id="`task-photo-state-${t.id}`"
                        @change="addTaskPhoto(t, $event)"
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                class="min-h-11 min-w-11 lg:min-h-9 lg:min-w-9"
                                :aria-label="`More actions for ${t.itemTitle}`"
                                ><Ellipsis class="size-4" aria-hidden="true"
                            /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-48">
                            <DropdownMenuItem
                                class="min-h-11 lg:min-h-9"
                                @select="openCancel(t)"
                                >Cancel task</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
        </ul>

        <Sheet :open="done.open" @update:open="(v: boolean) => (done.open = v)">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="task-done-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Mark done</SheetTitle>
                    <SheetDescription
                        >Say briefly what was done. Add photos of the fix from
                        the task afterwards. Do not photograph patients or
                        papers.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendDone"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="task-note">Note (optional)</Label>
                        <Input
                            id="task-note"
                            v-model="done.note"
                            maxlength="300"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.note"
                        />
                        <InputError :message="errors.note" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="done.processing"
                        >Mark done</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <Sheet
            :open="cancel.open"
            @update:open="(v: boolean) => (cancel.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="task-cancel-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Cancel task</SheetTitle>
                    <SheetDescription
                        >The task stays in the record.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendCancel"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="task-cancel-reason"
                            ><span id="task-cancel-reason-label"
                                >Reason *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="task-cancel-reason"
                            v-model="cancel.reason"
                            labelledby="task-cancel-reason-label"
                            trigger-class="max-lg:h-11"
                            label="Reason"
                            placeholder="Choose"
                            :options="audits.cancelReasons"
                            :invalid="!!errors.reason"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="cancel.processing"
                        >Cancel task</Button
                    >
                </form>
            </SheetContent>
        </Sheet>
    </section>
</template>
