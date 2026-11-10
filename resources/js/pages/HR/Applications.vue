<script setup lang="ts">
// HR-13 /hr/applications (Director, HR Manager): applications from the public form, the open positions and the
// retention. HR-PLAN 15.2, with HR-17a's position types, form answers, phone screening and call checklist (HR-PLAN 19). Every CV open is recorded by the server before any byte is sent.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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

type Tab = 'applications' | 'positions' | 'settings';
type Option = { value: string; label: string };
type Application = {
    id: string;
    position: string;
    status: string;
    statusLabel: string;
    next: Option[];
    name: string | null;
    phone: string | null;
    email: string | null;
    message: string | null;
    type: string;
    details: { label: string; value: string }[];
    screening: {
        outcome: 'pass' | 'reject';
        rating: number;
        reason: string | null;
        note: string | null;
        items: { text: string; ticked: boolean }[];
        by: string;
        at: string;
    } | null;
    hasCv: boolean;
    cvKb: number;
    privacyNoticeVersion: string;
    submittedAt: string;
    closedAt: string | null;
    purged: boolean;
    lockVersion: number;
};
type Position = {
    id: string;
    title: string;
    type: string;
    typeLabel: string;
    branchId: string | null;
    branch: string | null;
    isOpen: boolean;
    openApplications: number;
    applications: number;
    lockVersion: number;
};

const props = defineProps<{
    applications: {
        tab: Tab;
        tabs: Tab[];
        form: {
            enabled: boolean;
            state: 'off' | 'needs_retention' | 'on';
            url: string;
            privacyNoticeVersion: string;
        };
        options: {
            statuses: Option[];
            branches: Option[];
            positions: Option[];
            graceDays: number;
            types: Option[];
            rejectReasons: Option[];
        };
        positions: Position[];
        applications: {
            filters: { status: string; position: string };
            page: number;
            perPage: number;
            total: number;
            rows: Application[];
        } | null;
        settings: {
            version: number;
            retentionDays: number | null;
            pending: { days: number | null; from: string } | null;
        };
        checklist: { version: number; items: string[] };
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(
    () =>
        errors.value.applications ??
        errors.value.records ??
        errors.value.position,
);
const tabLabels: Record<Tab, string> = {
    applications: 'Applications',
    positions: 'Positions',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.applications.tabs.map((value) => ({
        value,
        label: tabLabels[value],
    })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/applications', { tab: value }, { preserveScroll: true });

// ---- Applications ----
const list = computed(() => props.applications.applications);
const statusFilter = computed(() => [
    { value: 'open', label: 'Open (New to Interview)' },
    ...props.applications.options.statuses,
]);
const positionFilter = computed(() => [
    { value: 'all', label: 'All positions' },
    ...props.applications.options.positions,
]);
const filter = (changes: Record<string, string | number>) => {
    const current = list.value?.filters ?? { status: 'open', position: '' };
    const next = { ...current, page: 1, ...changes };
    router.get(
        '/hr/applications',
        {
            tab: 'applications',
            status: next.status,
            ...(next.position && next.position !== 'all'
                ? { position: next.position }
                : {}),
            ...(Number(next.page) > 1 ? { page: next.page } : {}),
        },
        { preserveScroll: true },
    );
};
const pages = computed(() =>
    list.value
        ? Math.max(1, Math.ceil(list.value.total / list.value.perPage))
        : 1,
);
const tone = (status: string) =>
    (
        ({
            // Each open step its own tone (Daniel, HR-13).
            new: 'accent',
            phone_screening: 'neutral',
            scheduling: 'neutral',
            interview: 'warning',
            passed: 'success',
            failed: 'danger',
            not_taken_forward: 'danger',
            auto_rejected: 'danger',
            withdrawn: 'neutral',
            shortlisted: 'neutral',
            offered: 'success',
        }) as const
    )[status] ?? 'neutral';

const viewing = ref<Application | null>(null);
const moving = reactive({
    to: '',
    key: '',
    processing: false,
    confirm: false,
});
const openApplication = (application: Application) => {
    viewing.value = application;
    Object.assign(moving, {
        to: '',
        key: '',
        processing: false,
        confirm: false,
    });
    Object.assign(screening, {
        outcome: '',
        rating: '',
        ticked: [],
        note: '',
        key: crypto.randomUUID(),
        processing: false,
    });
};

// ---- Phone screening (HR-17a) ----
const screening = reactive({
    outcome: '' as '' | 'pass' | 'reject',
    rating: '',
    ticked: [] as number[],
    note: '',
    key: '',
    processing: false,
});
// Pass is the primary action; Reject asks first and needs a reason (Daniel, HR-17a).
const rejecting = reactive({ open: false, reason: '' });
const recordPass = () => {
    screening.outcome = 'pass';
    saveScreening();
};
const askReject = () => {
    Object.assign(rejecting, { open: true, reason: '' });
};
const confirmReject = () => {
    screening.outcome = 'reject';
    saveScreening();
};
const saveScreening = () => {
    const application = viewing.value;

    if (!application) {
        return;
    }

    screening.processing = true;
    router.post(
        `/hr/applications/${application.id}/screening`,
        {
            outcome: screening.outcome,
            rating: screening.rating,
            ticked: screening.ticked,
            note: screening.note,
            reject_reason:
                screening.outcome === 'reject' ? rejecting.reason : null,
            expected_lock_version: application.lockVersion,
            idempotency_key: screening.key,
            ...(list.value?.filters ?? {}),
            page: list.value?.page ?? 1,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                viewing.value = null;
                rejecting.open = false;
            },
            onFinish: () => (screening.processing = false),
        },
    );
};
const askMove = (to: string) => {
    if (!viewing.value) {
        return;
    }

    if (moving.to !== to) {
        Object.assign(moving, { to, key: crypto.randomUUID() });
    }

    // A step that closes the application asks first.
    if (['passed', 'failed', 'not_taken_forward', 'withdrawn'].includes(to)) {
        moving.confirm = true;

        return;
    }

    move();
};
const move = () => {
    const application = viewing.value;

    if (!application || !moving.to) {
        return;
    }

    moving.processing = true;
    router.post(
        `/hr/applications/${application.id}/move`,
        {
            to: moving.to,
            expected_lock_version: application.lockVersion,
            idempotency_key: moving.key,
            ...(list.value?.filters ?? {}),
            page: list.value?.page ?? 1,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                viewing.value = null;
                moving.confirm = false;
            },
            onFinish: () => (moving.processing = false),
        },
    );
};
const label = (value: string) =>
    props.applications.options.statuses.find((s) => s.value === value)?.label ??
    value;

// ---- Positions ----
const positionForm = reactive({
    open: false,
    position: null as Position | null,
    title: '',
    type: 'ca',
    branchId: 'any',
    isOpen: true,
    key: '',
    processing: false,
});
const branchOptions = computed(() => [
    { value: 'any', label: 'Any branch' },
    ...props.applications.options.branches,
]);
const startAdd = () =>
    Object.assign(positionForm, {
        open: true,
        position: null,
        title: '',
        type: 'ca',
        branchId: 'any',
        isOpen: true,
        key: crypto.randomUUID(),
    });
const startEdit = (position: Position) =>
    Object.assign(positionForm, {
        open: true,
        position,
        title: position.title,
        type: position.type,
        branchId: position.branchId ?? 'any',
        isOpen: position.isOpen,
        key: crypto.randomUUID(),
    });
const savePosition = () => {
    positionForm.processing = true;
    const position = positionForm.position;
    router.post(
        position
            ? `/hr/applications/positions/${position.id}`
            : '/hr/applications/positions',
        {
            title: positionForm.title,
            position_type: positionForm.type,
            branch_id: positionForm.branchId,
            ...(position
                ? {
                      is_open: positionForm.isOpen,
                      expected_lock_version: position.lockVersion,
                  }
                : {}),
            idempotency_key: positionForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (positionForm.open = false),
            onFinish: () => (positionForm.processing = false),
        },
    );
};

// ---- Settings: the phone-screening call checklist (HR-17a) ----
const checklist = reactive({
    text: props.applications.checklist.items.join('\n'),
    key: crypto.randomUUID(),
    processing: false,
});
const saveChecklist = () => {
    checklist.processing = true;
    router.post(
        '/hr/applications/checklist',
        {
            items: checklist.text
                .split('\n')
                .map((line) => line.trim())
                .filter((line) => line !== ''),
            expected_version: props.applications.checklist.version,
            idempotency_key: checklist.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (checklist.key = crypto.randomUUID()),
            onFinish: () => (checklist.processing = false),
        },
    );
};

// Save is the primary button only while its card has unsaved changes (Daniel, HR-17a).
const checklistDirty = computed(
    () =>
        JSON.stringify(
            checklist.text
                .split('\n')
                .map((line) => line.trim())
                .filter((line) => line !== ''),
        ) !== JSON.stringify(props.applications.checklist.items),
);
const retentionDirty = computed(
    () =>
        retention.days.trim() !==
        (props.applications.settings.retentionDays?.toString() ?? ''),
);

// ---- Settings ----
const retention = reactive({
    days: props.applications.settings.retentionDays?.toString() ?? '',
    key: crypto.randomUUID(),
    processing: false,
});
const saveRetention = () => {
    retention.processing = true;
    router.post(
        '/hr/applications/retention',
        {
            retention_days:
                retention.days.trim() === '' ? null : retention.days.trim(),
            expected_version: props.applications.settings.version,
            idempotency_key: retention.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (retention.key = crypto.randomUUID()),
            onFinish: () => (retention.processing = false),
        },
    );
};
</script>

<template>
    <Head title="Job applications" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Job applications"
                description="Applications sent from the public form, the positions shown on it, and how long applications are kept. Turning an applicant into staff stays with the account administrator."
            />
            <SyntheticNote />
        </div>

        <p
            class="rounded-xl border bg-card p-3 text-sm"
            data-test="applications-form-state"
        >
            <template v-if="applications.form.state === 'on'">
                The public form is <strong>on</strong> at
                <span class="break-all">{{ applications.form.url }}</span
                >.
            </template>
            <template v-else-if="applications.form.state === 'needs_retention'">
                The public form is <strong>off</strong> until you set how long
                applications are kept (Settings), because the privacy notice
                promises deletion after that period.
            </template>
            <template v-else>
                The public form is <strong>off</strong>. Nobody can apply until
                the privacy notice is approved and the form is switched on.
            </template>
            <span class="text-muted-foreground">
                Privacy notice version
                {{ applications.form.privacyNoticeVersion }}.</span
            >
        </p>

        <OperationalTabs
            :model-value="applications.tab"
            :tabs="tabs"
            label="Job application sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="failure && !viewing && !positionForm.open"
            :message="failure"
        />

        <!-- Applications -->
        <section
            v-if="applications.tab === 'applications' && list"
            aria-labelledby="applications-title"
            class="flex flex-col gap-3"
            data-test="applications-list"
        >
            <h2 id="applications-title" class="sr-only">Applications</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:max-w-3xl">
                <div class="flex flex-col gap-1.5">
                    <Label id="filter-status-label">Step</Label>
                    <OperationalSelect
                        :model-value="list.filters.status"
                        :options="statusFilter"
                        label="Step"
                        labelledby="filter-status-label"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="
                            (v) => filter({ status: String(v) })
                        "
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label id="filter-position-label">Position</Label>
                    <OperationalSelect
                        :model-value="list.filters.position || 'all'"
                        :options="positionFilter"
                        label="Position"
                        labelledby="filter-position-label"
                        trigger-class="max-lg:min-h-11"
                        @update:model-value="
                            (v) => filter({ position: String(v) })
                        "
                    />
                </div>
            </div>

            <EmptyState
                v-if="!list.rows.length"
                title="No applications here."
                :description="
                    applications.form.state === 'on'
                        ? 'Applications sent from the public form appear here.'
                        : 'The public form is off, so no applications arrive yet.'
                "
            />
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="row in list.rows"
                    :key="row.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                    data-test="application-row"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ row.purged ? 'Details deleted' : row.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ row.position }} · sent {{ row.submittedAt }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :status="row.status"
                            :label="row.statusLabel"
                            :tone="tone(row.status)"
                        />
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Open the application from ${row.purged ? 'a deleted applicant' : row.name}`"
                            data-test="application-open"
                            @click="openApplication(row)"
                            >Open</Button
                        >
                    </div>
                </li>
            </ul>
            <nav
                v-if="pages > 1"
                class="flex items-center justify-between gap-2 text-sm"
                aria-label="Application pages"
            >
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="list.page <= 1"
                    @click="filter({ page: list.page - 1 })"
                    >Previous</Button
                >
                <span class="tabular-nums"
                    >Page {{ list.page }} of {{ pages }} ·
                    {{ list.total }} applications</span
                >
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="list.page >= pages"
                    @click="filter({ page: list.page + 1 })"
                    >Next</Button
                >
            </nav>
        </section>

        <!-- Positions -->
        <section
            v-if="applications.tab === 'positions'"
            aria-labelledby="positions-title"
            class="flex flex-col gap-3"
            data-test="positions"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="positions-title" class="text-base font-semibold">
                    Positions
                </h2>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="position-add"
                    @click="startAdd"
                    >Add a position</Button
                >
            </div>
            <EmptyState
                v-if="!applications.positions.length"
                title="No positions yet."
                description="Add the positions you are hiring for. Only open positions show on the public form."
            />
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="position in applications.positions"
                    :key="position.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                    data-test="position-row"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">
                            {{ position.title }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ position.typeLabel }} ·
                            {{ position.branch ?? 'Any branch' }} ·
                            {{ position.openApplications }} open of
                            {{ position.applications }}
                            {{
                                position.applications === 1
                                    ? 'application'
                                    : 'applications'
                            }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :status="position.isOpen ? 'open' : 'closed'"
                            :label="position.isOpen ? 'Open' : 'Closed'"
                            :tone="position.isOpen ? 'success' : 'neutral'"
                        />
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Edit ${position.title}`"
                            data-test="position-edit"
                            @click="startEdit(position)"
                            >Edit</Button
                        >
                    </div>
                </li>
            </ul>
        </section>

        <!-- Settings -->
        <section
            v-if="applications.tab === 'settings'"
            aria-labelledby="settings-title"
            class="flex max-w-xl flex-col gap-3 rounded-xl border bg-card p-4"
            data-test="applications-settings"
        >
            <h2 id="settings-title" class="text-base font-semibold">
                How long applications are kept
            </h2>
            <p class="text-sm text-muted-foreground">
                Days before the applicant's details and CV are deleted, counted
                from when the application closed (Passed, Failed, rejected or
                Withdrawn), or from when it was sent if nobody closed it. KPOne
                ships no number. Leave it empty to keep applications.
            </p>
            <p class="text-sm" data-test="retention-in-force">
                In force:
                <strong>{{
                    applications.settings.retentionDays === null
                        ? 'Not set (kept)'
                        : `${applications.settings.retentionDays} days`
                }}</strong>
            </p>
            <p
                v-if="applications.settings.pending"
                class="text-sm"
                data-test="retention-pending"
            >
                From {{ applications.settings.pending.from }}:
                {{
                    applications.settings.pending.days === null
                        ? 'kept'
                        : `${applications.settings.pending.days} days`
                }}. A first or shorter period waits
                {{ applications.options.graceDays }} days, so a mistake can be
                undone.
            </p>
            <div class="flex flex-col gap-1.5">
                <Label for="retention-days">Days after closing</Label>
                <Input
                    id="retention-days"
                    v-model="retention.days"
                    inputmode="numeric"
                    class="max-w-40 max-lg:min-h-11"
                    :aria-invalid="errors.retention_days ? 'true' : undefined"
                    aria-describedby="retention-days-error"
                />
                <InputError
                    id="retention-days-error"
                    :message="errors.retention_days"
                />
            </div>
            <div>
                <Button
                    class="max-lg:min-h-11"
                    :variant="retentionDirty ? 'default' : 'outline'"
                    :disabled="!retentionDirty || retention.processing"
                    data-test="retention-save"
                    @click="saveRetention"
                    >{{ retention.processing ? 'Saving…' : 'Save' }}</Button
                >
            </div>
        </section>

        <!-- Settings: the phone-screening call checklist (HR-17a) -->
        <section
            v-if="applications.tab === 'settings'"
            aria-labelledby="checklist-title"
            class="flex max-w-xl flex-col gap-3 rounded-xl border bg-card p-4"
            data-test="screening-checklist"
        >
            <h2 id="checklist-title" class="text-base font-semibold">
                Phone screening checklist
            </h2>
            <p class="text-sm text-muted-foreground">
                The questions you ask on the call, one per line, up to 15. KPOne
                ships none. Each save is a new version; calls already recorded
                keep the version they used.
            </p>
            <div class="flex max-w-xl flex-col gap-1.5">
                <Label for="checklist-items">Questions</Label>
                <textarea
                    id="checklist-items"
                    v-model="checklist.text"
                    rows="6"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive"
                    placeholder="For example: Speaks clearly and politely"
                    :aria-invalid="errors.items ? 'true' : undefined"
                    aria-describedby="checklist-items-error"
                ></textarea>
                <InputError
                    id="checklist-items-error"
                    :message="errors.items"
                />
            </div>
            <div>
                <Button
                    class="max-lg:min-h-11"
                    :variant="checklistDirty ? 'default' : 'outline'"
                    :disabled="!checklistDirty || checklist.processing"
                    data-test="checklist-save"
                    @click="saveChecklist"
                    >{{ checklist.processing ? 'Saving…' : 'Save' }}</Button
                >
            </div>
        </section>

        <!-- One application -->
        <Sheet
            :open="viewing !== null"
            @update:open="(open) => !open && (viewing = null)"
        >
            <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>{{
                        viewing?.purged
                            ? 'Application (details deleted)'
                            : viewing?.name
                    }}</SheetTitle>
                    <SheetDescription
                        >{{ viewing?.position }} · sent
                        {{ viewing?.submittedAt }}</SheetDescription
                    >
                </SheetHeader>
                <div v-if="viewing" class="flex flex-col gap-4 px-4 pb-6">
                    <ErrorState
                        v-if="errors.to || failure"
                        :message="errors.to ?? failure ?? ''"
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="viewing.status"
                            :label="viewing.statusLabel"
                            :tone="tone(viewing.status)"
                        />
                        <span
                            v-if="viewing.closedAt"
                            class="text-sm text-muted-foreground"
                            >Closed {{ viewing.closedAt }}</span
                        >
                    </div>

                    <!-- The stage's own action comes first; the application's details follow (Daniel, HR-17a). -->
                    <form
                        v-if="viewing.status === 'phone_screening'"
                        class="flex flex-col gap-3 rounded-lg border p-3"
                        aria-labelledby="screening-form-title"
                        data-test="screening-form"
                        @submit.prevent="recordPass"
                    >
                        <h3
                            id="screening-form-title"
                            class="text-sm font-semibold"
                        >
                            Record the phone call
                        </h3>
                        <fieldset
                            v-if="applications.checklist.items.length"
                            class="flex flex-col gap-1"
                        >
                            <legend class="mb-1 text-sm text-muted-foreground">
                                Tick what the candidate showed on the call
                            </legend>
                            <label
                                v-for="(item, i) in applications.checklist
                                    .items"
                                :key="item"
                                class="flex min-h-11 items-center gap-3 text-sm"
                            >
                                <input
                                    v-model="screening.ticked"
                                    type="checkbox"
                                    :value="i"
                                    class="size-5"
                                />
                                {{ item }}
                            </label>
                            <InputError :message="errors.ticked" />
                        </fieldset>
                        <p v-else class="text-sm text-muted-foreground">
                            No call checklist yet. Add the questions you ask in
                            Settings.
                        </p>
                        <fieldset
                            class="flex flex-col gap-1.5"
                            :aria-invalid="errors.rating ? 'true' : undefined"
                            aria-describedby="screening-rating-ends screening-rating-error"
                            data-test="screening-rating"
                        >
                            <legend class="mb-1 text-sm font-medium">
                                How did they come across?
                            </legend>
                            <div
                                class="grid grid-cols-5 overflow-hidden rounded-md border"
                            >
                                <label
                                    v-for="n in 5"
                                    :key="n"
                                    class="flex min-h-11 cursor-pointer items-center justify-center border-l text-sm font-medium first:border-l-0 has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-inset"
                                >
                                    <input
                                        v-model="screening.rating"
                                        type="radio"
                                        name="screening-rating"
                                        :value="String(n)"
                                        class="sr-only"
                                    />
                                    {{ n }}
                                </label>
                            </div>
                            <p
                                id="screening-rating-ends"
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                <span>1 Weak</span><span>5 Strong</span>
                            </p>
                            <InputError
                                id="screening-rating-error"
                                :message="errors.rating"
                            />
                        </fieldset>
                        <div class="flex flex-col gap-1.5">
                            <Label for="screening-note">Note (optional)</Label>
                            <textarea
                                id="screening-note"
                                v-model="screening.note"
                                rows="3"
                                maxlength="500"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                aria-describedby="screening-note-hint screening-note-error"
                            ></textarea>
                            <p
                                id="screening-note-hint"
                                class="text-xs text-muted-foreground"
                            >
                                Only HR sees it. Do not note health details or
                                anything about pay.
                            </p>
                            <InputError
                                id="screening-note-error"
                                :message="errors.note"
                            />
                        </div>
                        <InputError :message="errors.outcome" />
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                class="max-lg:min-h-11"
                                :disabled="screening.processing"
                                data-test="screening-pass"
                                >{{
                                    screening.processing &&
                                    screening.outcome === 'pass'
                                        ? 'Saving…'
                                        : 'Pass: schedule the interview'
                                }}</Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                class="border-destructive/50 text-destructive hover:bg-destructive/10 max-lg:min-h-11"
                                :disabled="screening.processing"
                                data-test="screening-reject"
                                @click="askReject"
                                >Reject…</Button
                            >
                        </div>
                    </form>

                    <section
                        v-if="viewing.screening"
                        class="flex flex-col gap-2 rounded-lg border p-3 text-sm"
                        aria-labelledby="screening-record-title"
                        data-test="screening-record"
                    >
                        <h3 id="screening-record-title" class="font-semibold">
                            Phone screening:
                            {{
                                viewing.screening.outcome === 'pass'
                                    ? 'passed'
                                    : 'rejected'
                            }}, rating {{ viewing.screening.rating }} of 5
                        </h3>
                        <p v-if="viewing.screening.reason">
                            Reason: {{ viewing.screening.reason }}
                        </p>
                        <ul
                            v-if="viewing.screening.items.length"
                            class="flex flex-col gap-1"
                        >
                            <li
                                v-for="item in viewing.screening.items"
                                :key="item.text"
                            >
                                {{ item.ticked ? 'Yes' : 'No' }} ·
                                {{ item.text }}
                            </li>
                        </ul>
                        <p
                            v-if="viewing.screening.note"
                            class="break-words whitespace-pre-line"
                        >
                            {{ viewing.screening.note }}
                        </p>
                        <p class="text-muted-foreground">
                            By {{ viewing.screening.by }},
                            {{ viewing.screening.at }}
                        </p>
                    </section>

                    <div v-if="viewing.next.length" class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold">Move to</h3>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="step in viewing.next"
                                :key="step.value"
                                :variant="
                                    ['not_taken_forward', 'withdrawn'].includes(
                                        step.value,
                                    )
                                        ? 'outline'
                                        : 'default'
                                "
                                class="max-lg:min-h-11"
                                :disabled="moving.processing"
                                :data-test="`application-move-${step.value}`"
                                @click="askMove(step.value)"
                                >{{ step.label }}</Button
                            >
                        </div>
                    </div>

                    <section
                        class="flex flex-col gap-3 border-t pt-4"
                        aria-labelledby="application-details-title"
                    >
                        <h3
                            id="application-details-title"
                            class="text-sm font-semibold"
                        >
                            Application
                        </h3>
                        <dl v-if="!viewing.purged" class="grid gap-3 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Phone</dt>
                                <dd class="break-words">{{ viewing.phone }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Email</dt>
                                <dd class="break-all">{{ viewing.email }}</dd>
                            </div>
                            <div v-for="d in viewing.details" :key="d.label">
                                <dt class="text-muted-foreground">
                                    {{ d.label }}
                                </dt>
                                <dd class="break-words">{{ d.value }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Message</dt>
                                <dd class="break-words whitespace-pre-line">
                                    {{ viewing.message ?? '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Privacy notice
                                </dt>
                                <dd>
                                    Version {{ viewing.privacyNoticeVersion }}
                                </dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-muted-foreground">
                            The applicant's details and CV were deleted after
                            the retention period. The step and dates are kept.
                        </p>
                        <Button
                            v-if="viewing.hasCv"
                            as-child
                            variant="outline"
                            class="w-fit max-lg:min-h-11"
                        >
                            <a
                                :href="`/hr/applications/${viewing.id}/cv`"
                                :aria-label="`Open CV (PDF, ${viewing.cvKb} KB)`"
                                data-test="application-cv"
                                >Open CV ({{ viewing.cvKb }} KB)</a
                            >
                        </Button>
                        <p
                            v-if="viewing.hasCv"
                            class="text-xs text-muted-foreground"
                        >
                            Each open of a CV is recorded.
                        </p>
                    </section>
                </div>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="rejecting.open"
            title="Reject after the phone call?"
            description="This closes the application as Not taken forward. A closed application cannot be moved again."
            confirm-label="Reject"
            destructive
            :processing="screening.processing"
            :error="errors.reject_reason ?? errors.rating ?? errors.outcome"
            @update:open="(open) => (rejecting.open = open)"
            @confirm="confirmReject"
        >
            <fieldset class="flex flex-col gap-1" data-test="reject-reasons">
                <legend class="mb-1 text-sm font-medium">Why?</legend>
                <label
                    v-for="r in applications.options.rejectReasons"
                    :key="r.value"
                    class="flex min-h-11 items-center gap-3 text-sm"
                >
                    <input
                        v-model="rejecting.reason"
                        type="radio"
                        name="reject-reason"
                        :value="r.value"
                        class="size-5"
                    />
                    {{ r.label }}
                </label>
            </fieldset>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="moving.confirm"
            :title="`Move to ${label(moving.to)}?`"
            :description="
                moving.to === 'passed'
                    ? 'This records that the candidate passed the interview and closes the application. The retention period starts from today. Any offer is made outside KPOne.'
                    : 'This closes the application. A closed application cannot be moved again, and the retention period starts from today.'
            "
            :confirm-label="`Move to ${label(moving.to)}`"
            :destructive="moving.to !== 'passed'"
            :processing="moving.processing"
            @update:open="(open) => (moving.confirm = open)"
            @confirm="move"
        />

        <!-- Add or edit a position -->
        <Sheet
            :open="positionForm.open"
            @update:open="(open) => (positionForm.open = open)"
        >
            <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>{{
                        positionForm.position
                            ? 'Edit position'
                            : 'Add a position'
                    }}</SheetTitle>
                    <SheetDescription
                        >Open positions show on the public form. Closing one
                        keeps its applications.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="savePosition"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="flex flex-col gap-1.5">
                        <Label for="position-title">Title</Label>
                        <Input
                            id="position-title"
                            v-model="positionForm.title"
                            maxlength="80"
                            class="max-lg:min-h-11"
                            :aria-invalid="errors.title ? 'true' : undefined"
                            aria-describedby="position-title-error"
                        />
                        <InputError
                            id="position-title-error"
                            :message="errors.title"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label id="position-type-label">Kind of position</Label>
                        <OperationalSelect
                            v-model="positionForm.type"
                            :options="applications.options.types"
                            label="Kind of position"
                            labelledby="position-type-label"
                            trigger-class="max-lg:min-h-11"
                            :invalid="Boolean(errors.position_type)"
                        />
                        <p class="text-xs text-muted-foreground">
                            Decides the extra questions on the form: an intern's
                            dates, a locum's availability, a doctor's MMC number
                            and APC.
                        </p>
                        <p
                            v-if="
                                positionForm.position &&
                                positionForm.position.openApplications > 0 &&
                                positionForm.type !== positionForm.position.type
                            "
                            role="status"
                            class="rounded-md border border-warning/40 bg-warning/10 p-2 text-xs"
                            data-test="position-type-warning"
                        >
                            {{
                                positionForm.position.openApplications === 1
                                    ? '1 open application keeps the answers it was sent with.'
                                    : `${positionForm.position.openApplications} open applications keep the answers they were sent with.`
                            }}
                            Only new applications get the new questions.
                        </p>
                        <InputError :message="errors.position_type" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label id="position-branch-label">Branch</Label>
                        <OperationalSelect
                            v-model="positionForm.branchId"
                            :options="branchOptions"
                            label="Branch"
                            labelledby="position-branch-label"
                            trigger-class="max-lg:min-h-11"
                            :invalid="Boolean(errors.branch_id)"
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <label
                        v-if="positionForm.position"
                        class="flex items-center gap-3 text-sm"
                    >
                        <input
                            v-model="positionForm.isOpen"
                            type="checkbox"
                            class="size-5"
                            data-test="position-open"
                        />
                        Open (shows on the public form)
                    </label>
                    <div>
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="positionForm.processing"
                            data-test="position-save"
                            >{{
                                positionForm.processing ? 'Saving…' : 'Save'
                            }}</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
