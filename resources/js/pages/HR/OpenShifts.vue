<script setup lang="ts">
// HR-15b /hr/open-shifts: a linked locum sees open shifts at their branches and asks for one; the Director and HR
// Manager post shifts and confirm one locum, which adds the shift to the branch's next draft roster week.
import { Head, router, usePage } from '@inertiajs/vue3';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'available' | 'mine' | 'open' | 'closed';
type Option = { value: string; label: string };
type Shift = {
    id: string;
    branch: string;
    when: string;
    sessionLabel: string;
    roleLabel: string;
    breakMinutes: number;
    status: 'open' | 'filled' | 'withdrawn';
    lockVersion: number;
};
type Ask = {
    id: string;
    locum: string;
    askedOn: string;
    status: 'asked' | 'confirmed' | 'declined' | 'withdrawn';
    statusLabel: string;
    own: boolean;
    lockVersion: number;
};
type ManagedShift = Shift & { started: boolean; asks: Ask[] };
type MyAsk = Shift & {
    requestId: string;
    requestStatus: Ask['status'];
    requestStatusLabel: string;
    requestLockVersion: number;
};

const props = defineProps<{
    openShifts: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        canAsk: boolean;
        linked: boolean;
        available: Shift[];
        mine: MyAsk[];
        open: ManagedShift[];
        closed: ManagedShift[];
        branches: Option[];
        sessions: Option[];
        roles: Option[];
        declineReasons: Option[];
        maxDaysAhead: number;
        today: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.open_shift ?? errors.value.records);
const tabLabels: Record<Tab, string> = {
    available: 'Open shifts',
    mine: 'My asks',
    open: 'Open',
    closed: 'Filled and withdrawn',
};
const tabs = computed(() =>
    props.openShifts.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/open-shifts', { tab: value }, { preserveScroll: true });
const managed = computed(() =>
    props.openShifts.tab === 'closed'
        ? props.openShifts.closed
        : props.openShifts.open,
);
const shiftTone = (status: Shift['status']) =>
    status === 'open' ? 'warning' : status === 'filled' ? 'success' : 'neutral';
const askTone = (status: Ask['status']) =>
    status === 'confirmed'
        ? 'success'
        : status === 'asked'
          ? 'warning'
          : 'neutral';

const post = (url: string, data: Record<string, unknown>, done?: () => void) =>
    router.post(url, data as never, {
        preserveScroll: true,
        onSuccess: () => done?.(),
        onFinish: () => (busy.id = null),
    });
const busy = reactive<{ id: string | null }>({ id: null });

// ---- Post an open shift ----
const form = reactive({
    open: false,
    branch: '',
    date: '',
    start_time: '08:00',
    end_time: '14:00',
    break_minutes: '0',
    session_type: 'morning',
    role_needed: '',
    key: crypto.randomUUID(),
    processing: false,
});
const startPost = () =>
    Object.assign(form, {
        open: true,
        branch: props.openShifts.branches[0]?.value ?? '',
        date: props.openShifts.today,
        start_time: '08:00',
        end_time: '14:00',
        break_minutes: '0',
        session_type: 'morning',
        role_needed: '',
        key: crypto.randomUUID(),
    });
const missing = computed(() =>
    !form.role_needed
        ? 'Choose who is needed.'
        : !form.date
          ? 'Choose the date.'
          : null,
);
const submit = () => {
    form.processing = true;
    router.post(
        '/hr/open-shifts',
        {
            branch_id: form.branch,
            date: form.date,
            start_time: form.start_time,
            end_time: form.end_time,
            break_minutes: form.break_minutes,
            session_type: form.session_type,
            role_needed: form.role_needed,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (form.open = false),
            onFinish: () => (form.processing = false),
        },
    );
};

// ---- Confirm, decline, withdraw ----
const confirming = reactive<{ ask: Ask | null; shift: ManagedShift | null }>({
    ask: null,
    shift: null,
});
const confirm = () => {
    const ask = confirming.ask;

    if (!ask) {
        return;
    }

    busy.id = ask.id;
    post(
        `/hr/open-shifts/asks/${ask.id}/confirm`,
        {
            expected_lock_version: ask.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        () => (confirming.ask = null),
    );
};
const declining = reactive<{ ask: Ask | null; reason: string }>({
    ask: null,
    reason: '',
});
const decline = () => {
    const ask = declining.ask;

    if (!ask) {
        return;
    }

    busy.id = ask.id;
    post(
        `/hr/open-shifts/asks/${ask.id}/decline`,
        {
            reason: declining.reason,
            expected_lock_version: ask.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        () => (declining.ask = null),
    );
};
const withdrawing = reactive<{ shift: ManagedShift | null }>({ shift: null });
const withdraw = () => {
    const shift = withdrawing.shift;

    if (!shift) {
        return;
    }

    busy.id = shift.id;
    post(
        `/hr/open-shifts/${shift.id}/withdraw`,
        {
            expected_lock_version: shift.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        () => (withdrawing.shift = null),
    );
};

// ---- A locum asks or withdraws ----
const ask = (shift: Shift) => {
    busy.id = shift.id;
    post(`/hr/open-shifts/${shift.id}/ask`, {
        idempotency_key: crypto.randomUUID(),
    });
};
const withdrawAsk = (mine: MyAsk) => {
    busy.id = mine.requestId;
    post(`/hr/open-shifts/asks/${mine.requestId}/withdraw`, {
        expected_lock_version: mine.requestLockVersion,
        idempotency_key: crypto.randomUUID(),
    });
};
</script>

<template>
    <Head title="Open shifts" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 lg:p-6">
        <PageHeader
            eyebrow="KPOne HR"
            title="Open shifts"
            :description="
                openShifts.canManage
                    ? 'Post shifts that need a locum. Locums ask; you choose one, and the shift joins the next draft roster for that week.'
                    : 'Shifts the clinic needs covered at your branches. Ask for one; HR chooses who takes it.'
            "
        >
            <template v-if="openShifts.canManage" #actions>
                <Button
                    class="max-lg:min-h-11"
                    data-test="open-shift-new"
                    @click="startPost"
                    >Post an open shift</Button
                >
            </template>
        </PageHeader>
        <SyntheticNote />
        <ErrorState
            v-if="failure && !form.open && !declining.ask && !confirming.ask"
            :message="failure"
        />
        <p
            v-if="openShifts.canAsk && !openShifts.linked"
            class="text-sm text-muted-foreground"
            data-test="open-shift-not-linked"
        >
            Your sign-in is not linked to a locum file yet. Ask HR.
        </p>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="openShifts.tab"
            :tabs="tabs"
            label="Open shift sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <!-- A locum: open shifts at their branches -->
        <template v-if="openShifts.tab === 'available'">
            <EmptyState
                v-if="openShifts.available.length === 0"
                title="No open shifts at your branches."
                description="When HR posts one, it shows here."
            />
            <ul
                v-else
                class="divide-y rounded-xl border bg-card"
                data-test="open-shift-available"
            >
                <li
                    v-for="s in openShifts.available"
                    :key="s.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ s.when }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ s.branch }} · {{ s.sessionLabel }} ·
                            {{ s.roleLabel }}
                            <template v-if="s.breakMinutes">
                                · {{ s.breakMinutes }} min break</template
                            >
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="busy.id === s.id"
                        :aria-label="`Ask for the shift on ${s.when}`"
                        data-test="open-shift-ask"
                        @click="ask(s)"
                        >{{
                            busy.id === s.id ? 'Asking…' : 'Ask for this shift'
                        }}</Button
                    >
                </li>
            </ul>
        </template>

        <!-- A locum: their own asks -->
        <template v-else-if="openShifts.tab === 'mine'">
            <EmptyState
                v-if="openShifts.mine.length === 0"
                title="You have not asked for a shift."
                description="Ask for one on the Open shifts tab."
            />
            <ul
                v-else
                class="divide-y rounded-xl border bg-card"
                data-test="open-shift-mine"
            >
                <li
                    v-for="m in openShifts.mine"
                    :key="m.requestId"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            <span>{{ m.when }}</span>
                            <StatusBadge
                                :status="m.requestStatus"
                                :label="m.requestStatusLabel"
                                :tone="askTone(m.requestStatus)"
                            />
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ m.branch }} · {{ m.sessionLabel }} ·
                            {{ m.roleLabel }}
                        </p>
                    </div>
                    <Button
                        v-if="m.requestStatus === 'asked'"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="busy.id === m.requestId"
                        :aria-label="`Withdraw your ask for ${m.when}`"
                        data-test="open-shift-ask-withdraw"
                        @click="withdrawAsk(m)"
                        >Withdraw</Button
                    >
                </li>
            </ul>
        </template>

        <!-- Managers: open, or filled and withdrawn -->
        <template v-else>
            <EmptyState
                v-if="managed.length === 0"
                :title="
                    openShifts.tab === 'closed'
                        ? 'No filled or withdrawn shifts yet.'
                        : 'No open shifts.'
                "
                :description="
                    openShifts.tab === 'closed'
                        ? 'Shifts move here once a locum is confirmed or the shift is withdrawn.'
                        : 'Use Post an open shift when a shift needs a locum.'
                "
            />
            <ul
                v-else
                class="flex flex-col gap-3"
                data-test="open-shift-managed"
            >
                <li
                    v-for="s in managed"
                    :key="s.id"
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                <span>{{ s.when }}</span>
                                <StatusBadge
                                    :status="s.status"
                                    :label="
                                        s.status === 'open'
                                            ? 'Open'
                                            : s.status === 'filled'
                                              ? 'Filled'
                                              : 'Withdrawn'
                                    "
                                    :tone="shiftTone(s.status)"
                                />
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ s.branch }} · {{ s.sessionLabel }} ·
                                {{ s.roleLabel }}
                                <template v-if="s.breakMinutes">
                                    · {{ s.breakMinutes }} min break</template
                                >
                            </p>
                        </div>
                        <Button
                            v-if="s.status === 'open'"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Withdraw the shift on ${s.when}`"
                            data-test="open-shift-withdraw"
                            @click="withdrawing.shift = s"
                            >Withdraw shift</Button
                        >
                    </div>
                    <p
                        v-if="s.asks.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No locum has asked yet.
                    </p>
                    <ul v-else class="flex flex-col divide-y rounded-lg border">
                        <li
                            v-for="a in s.asks"
                            :key="a.id"
                            class="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                            data-test="open-shift-ask-row"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-2 font-medium"
                                >
                                    <span>{{ a.locum }}</span>
                                    <StatusBadge
                                        :status="a.status"
                                        :label="a.statusLabel"
                                        :tone="askTone(a.status)"
                                    />
                                </p>
                                <p class="text-muted-foreground">
                                    Asked on {{ a.askedOn }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    a.status === 'asked' && s.status === 'open'
                                "
                                class="flex flex-wrap gap-2"
                            >
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="a.own || s.started"
                                    :aria-label="`Confirm ${a.locum}`"
                                    data-test="open-shift-confirm"
                                    @click="
                                        confirming.ask = a;
                                        confirming.shift = s;
                                    "
                                    >Confirm</Button
                                >
                                <Button
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="a.own"
                                    :aria-label="`Decline ${a.locum}`"
                                    data-test="open-shift-decline"
                                    @click="
                                        declining.ask = a;
                                        declining.reason = '';
                                    "
                                    >Decline</Button
                                >
                            </div>
                            <p
                                v-if="
                                    a.status === 'asked' &&
                                    s.status === 'open' &&
                                    (a.own || s.started)
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    a.own
                                        ? 'This is your own ask. Someone else decides it.'
                                        : 'This shift has started, so it can no longer be confirmed.'
                                }}
                            </p>
                        </li>
                    </ul>
                </li>
            </ul>
        </template>

        <!-- Confirm one locum -->
        <OperationalConfirmDialog
            :open="confirming.ask !== null"
            :title="`Confirm ${confirming.ask?.locum ?? ''}?`"
            :description="`The shift on ${confirming.shift?.when ?? ''} joins the draft roster for that week at ${confirming.shift?.branch ?? ''}, and the other asks are declined. The locum sees it on My roster once the week is approved.`"
            confirm-label="Confirm"
            processing-label="Confirming…"
            :processing="busy.id === confirming.ask?.id"
            :error="errors.open_shift ?? errors.staff_user_id"
            @update:open="(v: boolean) => !v && (confirming.ask = null)"
            @confirm="confirm"
        />

        <!-- Decline with a reason -->
        <OperationalConfirmDialog
            :open="declining.ask !== null"
            :title="`Decline ${declining.ask?.locum ?? ''}?`"
            description="The locum sees that they were not chosen, with the reason."
            confirm-label="Decline"
            processing-label="Declining…"
            :processing="busy.id === declining.ask?.id"
            :error="errors.reason ?? errors.open_shift"
            @update:open="(v: boolean) => !v && (declining.ask = null)"
            @confirm="decline"
        >
            <div class="flex flex-col gap-2">
                <Label id="decline-reason-label">Reason</Label>
                <OperationalSelect
                    :model-value="declining.reason"
                    :options="openShifts.declineReasons"
                    label="Reason"
                    labelledby="decline-reason-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (declining.reason = String(v))"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Withdraw a shift -->
        <OperationalConfirmDialog
            :open="withdrawing.shift !== null"
            title="Withdraw this open shift?"
            :description="`The shift on ${withdrawing.shift?.when ?? ''} is no longer offered, and every waiting ask is declined. This cannot be undone.`"
            confirm-label="Withdraw shift"
            processing-label="Withdrawing…"
            destructive
            :processing="busy.id === withdrawing.shift?.id"
            :error="errors.open_shift"
            @update:open="(v: boolean) => !v && (withdrawing.shift = null)"
            @confirm="withdraw"
        />

        <!-- Post an open shift -->
        <Sheet v-model:open="form.open">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="open-shift-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Post an open shift</SheetTitle>
                    <SheetDescription
                        >Locums assigned to the branch can ask for it. The
                        roster rules apply: at most 16 hours, a break shorter
                        than the shift.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-4"
                    @submit.prevent="submit"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="flex flex-col gap-2">
                        <Label id="open-shift-branch-label">Branch</Label>
                        <OperationalSelect
                            :model-value="form.branch"
                            :options="openShifts.branches"
                            label="Branch"
                            labelledby="open-shift-branch-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.branch = String(v))
                            "
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="open-shift-date">Date</Label>
                        <DateField
                            id="open-shift-date"
                            v-model="form.date"
                            :min="openShifts.today"
                            :aria-invalid="errors.date ? 'true' : undefined"
                        />
                        <InputError :message="errors.date" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="open-shift-start">Start time</Label>
                            <Input
                                id="open-shift-start"
                                v-model="form.start_time"
                                type="time"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.start_time ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.start_time" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="open-shift-end">End time</Label>
                            <Input
                                id="open-shift-end"
                                v-model="form.end_time"
                                type="time"
                                class="max-lg:min-h-11"
                                aria-describedby="open-shift-end-help"
                                :aria-invalid="
                                    errors.end_time ? 'true' : undefined
                                "
                            />
                            <p
                                id="open-shift-end-help"
                                class="text-xs text-muted-foreground"
                            >
                                If the end is earlier than the start, the shift
                                ends the next day.
                            </p>
                            <InputError :message="errors.end_time" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label id="open-shift-session-label">Session</Label>
                            <OperationalSelect
                                :model-value="form.session_type"
                                :options="openShifts.sessions"
                                label="Session"
                                labelledby="open-shift-session-label"
                                trigger-class="max-lg:min-h-11"
                                @update:model-value="
                                    (v) => (form.session_type = String(v))
                                "
                            />
                            <InputError :message="errors.session_type" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="open-shift-break"
                                >Break (minutes)</Label
                            >
                            <Input
                                id="open-shift-break"
                                v-model="form.break_minutes"
                                type="number"
                                min="0"
                                max="180"
                                inputmode="numeric"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.break_minutes ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.break_minutes" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label id="open-shift-role-label">Who is needed</Label>
                        <OperationalSelect
                            :model-value="form.role_needed"
                            :options="openShifts.roles"
                            label="Who is needed"
                            labelledby="open-shift-role-label"
                            placeholder="Choose who is needed"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.role_needed = String(v))
                            "
                        />
                        <InputError :message="errors.role_needed" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="form.processing || missing !== null"
                            data-test="open-shift-save"
                            >{{
                                form.processing ? 'Posting…' : 'Post open shift'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="form.open = false"
                            >Cancel</Button
                        >
                    </div>
                    <p v-if="missing" class="text-sm text-muted-foreground">
                        {{ missing }}
                    </p>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
