<script setup lang="ts">
// HR-13 /hr/applications (Director, HR Manager): applications from the public form, the open positions and the
// retention. HR-PLAN 15.2, with HR-17a's position types, form answers, phone screening and call checklist (HR-PLAN 19). Every CV open is recorded by the server before any byte is sent.
import { Head, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, TriangleAlert } from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
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

type Tab = 'applications' | 'pool' | 'positions' | 'settings';
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
    // HR-17b: the fixed messages the candidate (or the hiring mailbox) got, and when the daily check closes it.
    messages: {
        template: string;
        label: string;
        recipient: 'candidate' | 'mailbox';
        status: string;
        statusLabel: string;
        subject: string;
        body: string;
        atLabel: string;
    }[];
    autoRejectOn: string | null;
    autoRejectSoon: boolean;
    // HR-17c: the candidate's three times at Scheduling, or the confirmed one at Interview.
    interview: {
        round: number;
        branch: string | null;
        picks: {
            start: string;
            label: string;
            taken: boolean;
            past: boolean;
        }[];
        confirmed: string | null;
        // HR-17d: which interview documents the candidate has downloaded.
        documents?: { title: string; downloadedAt: string | null }[];
    } | null;
    // HR-17e: the talent pool and the staff-account request.
    poolUntil?: string | null;
    poolConsent?: boolean;
    accountRequest?: {
        id: string;
        status: 'open' | 'done' | 'cancelled';
        statusLabel: string;
        at: string;
        lockVersion: number;
    } | null;
};
type PoolMember = {
    id: string;
    name: string;
    position: string;
    type: string;
    result: string;
    resultLabel: string;
    closedOn: string;
    until: string;
    lockVersion: number;
    invitedTo: string[];
    positions: Option[];
};
type InterviewDocumentGroup = {
    type: string;
    label: string;
    documents: {
        id: string;
        title: string;
        size: string;
        uploadedOn: string;
        uploadedBy: string;
        candidates: number;
        lockVersion: number;
    }[];
};
type NeedsYouRow = {
    id: string;
    status: string;
    name: string;
    position: string;
    why: string;
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
            cancelReasons: Option[];
        };
        positions: Position[];
        applications: {
            filters: { status: string; position: string; focus: string };
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
        interviewDocuments: InterviewDocumentGroup[];
        pool: {
            version: number;
            days: number | null;
            pending: { days: number | null; from: string } | null;
            members: PoolMember[];
        };
        needsYou: {
            picks: { count: number; rows: NeedsYouRow[] };
            today: { count: number; rows: NeedsYouRow[] };
            closing: { count: number; rows: NeedsYouRow[] };
            new: { count: number; rows: NeedsYouRow[] };
            noWorkingDays: boolean;
        };
        email: { enabled: boolean; mailbox: boolean };
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
    pool: 'Talent pool',
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
            ...(changes.focus ? { focus: changes.focus } : {}),
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

// HR-17b "Needs you": a row opens just that application.
const needsYouCount = computed(
    () =>
        props.applications.needsYou.picks.count +
        props.applications.needsYou.today.count +
        props.applications.needsYou.closing.count +
        props.applications.needsYou.new.count,
);
// HR-17c: interview actions. Each is one POST with the row's lock version and a fresh key.
const interviewAction = reactive({
    processing: false,
    cancelOpen: false,
    reason: '',
});
const interviewPost = (path: string, data: Record<string, string> = {}) => {
    if (!viewing.value) {
        return;
    }

    interviewAction.processing = true;
    router.post(
        `/hr/applications/${viewing.value.id}/interview/${path}`,
        {
            ...data,
            expected_lock_version: viewing.value.lockVersion,
            idempotency_key: crypto.randomUUID(),
            ...(list.value?.filters ?? {}),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                viewing.value = null;
                interviewAction.cancelOpen = false;
                confirming.value = null;
            },
            onFinish: () => (interviewAction.processing = false),
        },
    );
};
const focusOn = (row: NeedsYouRow) =>
    router.get(
        '/hr/applications',
        { tab: 'applications', status: row.status, focus: row.id },
        { preserveScroll: true },
    );
const showAll = () => filter({ status: 'open' });

const viewing = ref<Application | null>(null);
const moving = reactive({
    to: '',
    key: '',
    processing: false,
    confirm: false,
});
// Opens the application "Needs you" pointed at, on first load and after an Inertia visit to the same page.
watch(
    () => list.value?.filters.focus,
    (focus) => {
        const focused = focus ? list.value?.rows[0] : null;

        if (focused) {
            openApplication(focused);
        }
    },
    { flush: 'post' },
);
onMounted(() => {
    const focused = list.value?.filters.focus ? list.value.rows[0] : null;

    if (focused) {
        openApplication(focused);
    }
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
// HR-17c (Daniel): at Scheduling, "Interview" means an interview arranged outside KPOne, and it is hidden once the
// candidate has picked times, so Confirm is the one path.
const arrangedOutside = (to: string) =>
    viewing.value?.status === 'scheduling' && to === 'interview';
const moveSteps = computed(() =>
    (viewing.value?.next ?? []).filter(
        (step) =>
            !(
                arrangedOutside(step.value) &&
                (viewing.value?.interview?.picks.length ?? 0) > 0
            ),
    ),
);
// Confirming tells the candidate, so it asks first (Daniel, HR-17c).
const confirming = ref<{ start: string; label: string } | null>(null);

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

// HR-17e: the talent pool period, invitations and the staff-account request (HR-PLAN 19.8).
const poolDays = reactive({
    days: props.applications.pool.days?.toString() ?? '',
    key: crypto.randomUUID(),
    processing: false,
});
const poolDaysDirty = computed(
    () =>
        poolDays.days.trim() !==
        (props.applications.pool.days?.toString() ?? ''),
);
const savePoolDays = () => {
    poolDays.processing = true;
    router.post(
        '/hr/applications/pool-days',
        {
            pool_days:
                poolDays.days.trim() === '' ? null : poolDays.days.trim(),
            expected_version: props.applications.pool.version,
            idempotency_key: poolDays.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (poolDays.key = crypto.randomUUID()),
            onFinish: () => (poolDays.processing = false),
        },
    );
};
const invitePick = reactive<Record<string, string>>({});
const inviting = ref<{ member: PoolMember; position: Option } | null>(null);
const inviteKey = ref(crypto.randomUUID());
const inviteProcessing = ref(false);
const askInvite = (member: PoolMember) => {
    const position = member.positions.find(
        (p) => p.value === invitePick[member.id],
    );

    if (position) {
        inviting.value = { member, position };
    }
};
const sendInvite = () => {
    const target = inviting.value;

    if (!target) {
        return;
    }

    inviteProcessing.value = true;
    router.post(
        `/hr/applications/${target.member.id}/invite`,
        {
            position: target.position.value,
            expected_lock_version: target.member.lockVersion,
            idempotency_key: inviteKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                inviting.value = null;
                inviteKey.value = crypto.randomUUID();
            },
            onFinish: () => (inviteProcessing.value = false),
        },
    );
};
const invitationLink = computed(
    () =>
        (page as unknown as { flash?: { invitationLink?: string } }).flash
            ?.invitationLink ?? null,
);
const linkCopied = ref<'copied' | 'select' | null>(null);
const copyInvitationLink = async (event: Event) => {
    try {
        await navigator.clipboard.writeText(invitationLink.value ?? '');
        linkCopied.value = 'copied';
    } catch {
        const input = (event.currentTarget as HTMLElement)
            .closest('[data-test="pool-invitation-link"]')
            ?.querySelector('input');
        input?.select();
        linkCopied.value = 'select';
    }
};
const accountAction = reactive({ key: crypto.randomUUID(), processing: false });
const accountPost = (path: string, lockVersion: number) => {
    accountAction.processing = true;
    router.post(
        path,
        {
            expected_lock_version: lockVersion,
            idempotency_key: accountAction.key,
            ...(list.value?.filters ?? {}),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                accountAction.key = crypto.randomUUID();
                viewing.value = null;
            },
            onFinish: () => (accountAction.processing = false),
        },
    );
};

// HR-17d: interview documents per position type (HR-PLAN 19.7).
const DOCS_MAX = 10;
const documentForm = reactive({
    type: props.applications.options.types[0]?.value ?? 'ca',
    title: '',
    file: null as File | null,
    key: crypto.randomUUID(),
    processing: false,
});
const documentPicker = ref<InstanceType<typeof FileChooseButton> | null>(null);
const documentGroup = computed(() =>
    props.applications.interviewDocuments.find(
        (g) => g.type === documentForm.type,
    ),
);
const documentFull = computed(
    () => (documentGroup.value?.documents.length ?? 0) >= DOCS_MAX,
);
const chooseDocument = (event: Event) => {
    documentForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};
const uploadDocument = () => {
    documentForm.processing = true;
    router.post(
        '/hr/applications/interview-documents',
        {
            position_type: documentForm.type,
            title: documentForm.title,
            file: documentForm.file,
            idempotency_key: documentForm.key,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                Object.assign(documentForm, {
                    title: '',
                    file: null,
                    key: crypto.randomUUID(),
                });
                documentPicker.value?.clear();
            },
            onFinish: () => (documentForm.processing = false),
        },
    );
};
const removingDocument = ref<{
    id: string;
    title: string;
    lockVersion: number;
} | null>(null);
const removeDocumentKey = ref(crypto.randomUUID());
const removeDocumentProcessing = ref(false);
const removeDocument = () => {
    const doc = removingDocument.value;

    if (!doc) {
        return;
    }

    removeDocumentProcessing.value = true;
    router.post(
        `/hr/applications/interview-documents/${doc.id}/remove`,
        {
            expected_lock_version: doc.lockVersion,
            idempotency_key: removeDocumentKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                removingDocument.value = null;
                removeDocumentKey.value = crypto.randomUUID();
            },
            onFinish: () => (removeDocumentProcessing.value = false),
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
            <span
                class="block text-muted-foreground"
                data-test="hiring-email-state"
            >
                <template v-if="applications.email.enabled">
                    Hiring email is on: candidates get each message by email as
                    well as on their private page.<template
                        v-if="!applications.email.mailbox"
                    >
                        No hiring mailbox is set, so reminders show here
                        only.</template
                    >
                </template>
                <template v-else>
                    Hiring email is off: candidates see the clinic's messages on
                    their private page only.
                </template>
            </span>
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
            <section
                v-if="needsYouCount > 0 || applications.needsYou.noWorkingDays"
                aria-labelledby="needs-you-title"
                class="flex flex-col gap-3 rounded-xl border bg-card p-3"
                data-test="needs-you"
            >
                <h3 id="needs-you-title" class="font-semibold">
                    Needs you
                    <span
                        v-if="needsYouCount"
                        class="font-normal text-muted-foreground"
                        >({{ needsYouCount }})</span
                    >
                </h3>
                <p
                    v-if="applications.needsYou.noWorkingDays"
                    class="rounded-lg border border-warning/40 bg-warning/10 p-2 text-sm"
                    data-test="needs-you-no-working-days"
                >
                    A branch has no working days set in KPOne HR leave settings,
                    so its applications are never closed automatically. Set the
                    working days to start the 3-day count.
                </p>
                <div
                    v-for="group in [
                        {
                            key: 'today',
                            title: 'Interviews today',
                            data: applications.needsYou.today,
                        },
                        {
                            key: 'picks',
                            title: 'Times to confirm',
                            data: applications.needsYou.picks,
                        },
                    ].filter((g) => g.data.count)"
                    :key="group.key"
                    class="flex flex-col gap-1.5"
                    :data-test="`needs-you-${group.key}`"
                >
                    <h4 class="text-sm font-medium">
                        {{ group.title }} ({{ group.data.count }})
                    </h4>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="row in group.data.rows"
                            :key="row.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium break-words">
                                    {{ row.name }} · {{ row.position }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ row.why }}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :aria-label="`Open the application from ${row.name}`"
                                @click="focusOn(row)"
                                >Open</Button
                            >
                        </li>
                    </ul>
                </div>
                <!-- The urgent ones in full; new applications as one line, since the list below shows them (Daniel, HR-17b). -->
                <div
                    v-if="applications.needsYou.closing.count"
                    class="flex flex-col gap-1.5"
                    data-test="needs-you-closing"
                >
                    <h4 class="text-sm font-medium">
                        Closing at the start of the next working day ({{
                            applications.needsYou.closing.count
                        }})
                    </h4>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="row in applications.needsYou.closing.rows"
                            :key="row.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium break-words">
                                    {{ row.name }} · {{ row.position }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ row.why }}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :aria-label="`Open the application from ${row.name}`"
                                @click="focusOn(row)"
                                >Open</Button
                            >
                        </li>
                    </ul>
                    <p
                        v-if="
                            applications.needsYou.closing.count >
                            applications.needsYou.closing.rows.length
                        "
                        class="text-sm text-muted-foreground"
                    >
                        And
                        {{
                            applications.needsYou.closing.count -
                            applications.needsYou.closing.rows.length
                        }}
                        more in the list below.
                    </p>
                </div>
                <p
                    v-if="applications.needsYou.new.count"
                    class="flex flex-wrap items-center gap-x-2 text-sm"
                    data-test="needs-you-new"
                >
                    {{ applications.needsYou.new.count }} new
                    {{
                        applications.needsYou.new.count === 1
                            ? 'application'
                            : 'applications'
                    }}
                    <span aria-hidden="true">·</span>
                    <Button
                        variant="link"
                        class="h-auto p-0 underline underline-offset-4 max-lg:min-h-11"
                        data-test="needs-you-new-show"
                        @click="filter({ status: 'new' })"
                        >Show</Button
                    >
                </p>
            </section>
            <p
                v-if="list.filters.focus"
                class="flex flex-wrap items-center gap-2 text-sm"
                data-test="applications-focus"
            >
                Showing one application.
                <Button
                    variant="link"
                    class="h-auto p-0 underline underline-offset-4 max-lg:min-h-11"
                    @click="showAll"
                    >Show all open applications</Button
                >
            </p>
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

        <!-- Talent pool (HR-17e) -->
        <section
            v-if="applications.tab === 'pool'"
            aria-labelledby="pool-title"
            class="flex flex-col gap-3"
            data-test="talent-pool"
        >
            <div class="flex flex-col gap-1">
                <h2 id="pool-title" class="text-base font-semibold">
                    Talent pool
                </h2>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    Candidates who ticked the optional talent-pool box and whose
                    application closed as Passed or Failed, kept for the period
                    set in Settings. You can invite them to an open position of
                    the same type; that starts a new application at phone
                    screening with the same answers and CV. They can leave the
                    pool on their private page at any time.
                </p>
            </div>
            <div
                v-if="invitationLink"
                class="flex max-w-3xl flex-col gap-2 rounded-xl border bg-card p-3 text-sm"
                data-test="pool-invitation-link"
            >
                <p>
                    <strong
                        >The candidate's new private link, shown once. Give it
                        only to the candidate, and do not keep it.</strong
                    >
                    {{
                        applications.email.enabled
                            ? 'It was also emailed to them.'
                            : 'Hiring email is off, so pass it on yourself, by phone or WhatsApp.'
                    }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        :model-value="invitationLink"
                        readonly
                        aria-label="New private link"
                        class="min-w-0 flex-1 max-lg:min-h-11"
                    />
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="copyInvitationLink"
                        >Copy</Button
                    >
                </div>
                <p
                    v-if="linkCopied"
                    role="status"
                    class="text-muted-foreground"
                >
                    {{
                        linkCopied === 'copied'
                            ? 'Copied.'
                            : 'Copy did not work here. The link is selected: copy it by hand.'
                    }}
                </p>
            </div>
            <ErrorState
                v-if="errors.position || errors.application"
                :message="errors.position ?? errors.application ?? ''"
            />
            <EmptyState
                v-if="applications.pool.days === null"
                title="No talent pool period set"
                description="Until a period is set in Settings, nobody is kept in the pool beyond the usual retention."
            />
            <EmptyState
                v-else-if="applications.pool.members.length === 0"
                title="Nobody in the talent pool"
                description="A candidate appears here once their application closes as Passed or Failed, if they ticked the box."
            />
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="member in applications.pool.members"
                    :key="member.id"
                    class="flex flex-col gap-3 rounded-xl border bg-card p-3 text-sm lg:flex-row lg:items-center lg:justify-between"
                    data-test="pool-member"
                >
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="font-medium break-words">{{
                            member.name
                        }}</span>
                        <span class="text-muted-foreground"
                            >{{ member.position }} · {{ member.resultLabel }}
                            {{ member.closedOn }} · in the pool until
                            {{ member.until }}</span
                        >
                        <span
                            v-if="member.invitedTo.length"
                            class="text-muted-foreground"
                            >Invited to {{ member.invitedTo.join(', ') }}</span
                        >
                    </div>
                    <div
                        v-if="member.positions.length"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <span :id="`pool-pick-${member.id}`" class="sr-only"
                            >Position to invite {{ member.name }} to</span
                        >
                        <OperationalSelect
                            :model-value="invitePick[member.id] ?? ''"
                            :options="member.positions"
                            label="Open position"
                            :labelledby="`pool-pick-${member.id}`"
                            placeholder="Choose a position"
                            trigger-class="min-w-48 max-lg:min-h-11"
                            @update:model-value="
                                (v) => (invitePick[member.id] = String(v))
                            "
                        />
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="!invitePick[member.id]"
                            data-test="pool-invite"
                            @click="askInvite(member)"
                            >Invite</Button
                        >
                        <span
                            v-if="!invitePick[member.id]"
                            class="text-sm text-muted-foreground"
                            data-test="pool-invite-why"
                            >Choose a position first.</span
                        >
                    </div>
                    <p v-else class="text-muted-foreground">
                        No open position of this type.
                    </p>
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

        <!-- Settings: the talent pool period (HR-17e) -->
        <section
            v-if="applications.tab === 'settings'"
            aria-labelledby="pool-days-title"
            class="flex max-w-xl flex-col gap-3 rounded-xl border bg-card p-4"
            data-test="pool-settings"
        >
            <h2 id="pool-days-title" class="text-base font-semibold">
                How long the talent pool keeps a candidate
            </h2>
            <p class="text-sm text-muted-foreground">
                Days after a Passed or Failed application closes that a
                candidate who ticked the talent-pool box is kept, and can be
                invited to a new position. KPOne ships no number. Leave it empty
                to keep nobody in the pool beyond the usual retention.
            </p>
            <p class="text-sm" data-test="pool-days-in-force">
                In force:
                <strong>{{
                    applications.pool.days === null
                        ? 'Not set (no pool)'
                        : `${applications.pool.days} days`
                }}</strong>
            </p>
            <p v-if="applications.pool.pending" class="text-sm">
                From {{ applications.pool.pending.from }}:
                {{
                    applications.pool.pending.days === null
                        ? 'no pool'
                        : `${applications.pool.pending.days} days`
                }}. A first or shorter period waits
                {{ applications.options.graceDays }} days, so a mistake can be
                undone.
            </p>
            <div class="flex flex-col gap-1.5">
                <Label for="pool-days">Days after closing</Label>
                <Input
                    id="pool-days"
                    v-model="poolDays.days"
                    inputmode="numeric"
                    class="max-w-40 max-lg:min-h-11"
                    :aria-invalid="errors.pool_days ? 'true' : undefined"
                    aria-describedby="pool-days-error"
                />
                <InputError id="pool-days-error" :message="errors.pool_days" />
            </div>
            <div>
                <Button
                    class="max-lg:min-h-11"
                    :variant="poolDaysDirty ? 'default' : 'outline'"
                    :disabled="!poolDaysDirty || poolDays.processing"
                    data-test="pool-days-save"
                    @click="savePoolDays"
                    >{{ poolDays.processing ? 'Saving…' : 'Save' }}</Button
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

        <!-- Settings: interview documents (HR-17d) -->
        <section
            v-if="applications.tab === 'settings'"
            aria-labelledby="interview-docs-title"
            class="flex max-w-xl flex-col gap-4 rounded-xl border bg-card p-4"
            data-test="interview-documents"
        >
            <div class="flex flex-col gap-1">
                <h2 id="interview-docs-title" class="text-base font-semibold">
                    Interview documents
                </h2>
                <p class="text-sm text-muted-foreground">
                    PDFs a candidate can download from their private page once
                    their interview is confirmed, for their position type only.
                    Up to {{ DOCS_MAX }} per type, 10 MB each. Each download is
                    recorded. Do not upload a document that contains a person's
                    private details.
                </p>
            </div>
            <div
                v-for="group in applications.interviewDocuments"
                :key="group.type"
                class="flex flex-col gap-2"
                :data-test="`interview-documents-${group.type}`"
            >
                <h3 class="text-sm font-semibold">
                    {{ group.label }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ group.documents.length }} of {{ DOCS_MAX }}</span
                    >
                </h3>
                <p
                    v-if="group.documents.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    None yet.
                </p>
                <ul v-else class="flex flex-col gap-2">
                    <li
                        v-for="doc in group.documents"
                        :key="doc.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2 text-sm"
                    >
                        <div class="flex min-w-0 flex-col">
                            <a
                                :href="`/hr/applications/interview-documents/${doc.id}`"
                                class="inline-flex min-h-11 items-center font-medium break-words underline underline-offset-4 lg:min-h-0"
                                >{{ doc.title }}</a
                            >
                            <span class="text-xs text-muted-foreground"
                                >PDF · {{ doc.size }} · added
                                {{ doc.uploadedOn }} by {{ doc.uploadedBy }} ·
                                downloaded by {{ doc.candidates }}
                                {{
                                    doc.candidates === 1
                                        ? 'candidate'
                                        : 'candidates'
                                }}</span
                            >
                        </div>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Remove ${doc.title}`"
                            data-test="interview-document-remove"
                            @click="
                                removingDocument = {
                                    id: doc.id,
                                    title: doc.title,
                                    lockVersion: doc.lockVersion,
                                }
                            "
                            >Remove</Button
                        >
                    </li>
                </ul>
            </div>
            <form
                class="flex flex-col gap-3 border-t pt-4"
                data-test="interview-document-add"
                @submit.prevent="uploadDocument"
            >
                <h3 class="text-sm font-semibold">Add a document</h3>
                <div class="flex flex-col gap-1.5">
                    <Label id="interview-doc-type-label">Position type</Label>
                    <OperationalSelect
                        v-model="documentForm.type"
                        :options="applications.options.types"
                        label="Position type"
                        labelledby="interview-doc-type-label"
                        trigger-class="max-lg:min-h-11"
                    />
                    <InputError :message="errors.position_type" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="interview-doc-title">Title</Label>
                    <Input
                        id="interview-doc-title"
                        v-model="documentForm.title"
                        maxlength="80"
                        class="max-lg:min-h-11"
                        placeholder="For example: Clinic induction booklet"
                        :aria-invalid="errors.title ? 'true' : undefined"
                        aria-describedby="interview-doc-title-error"
                    />
                    <InputError
                        id="interview-doc-title-error"
                        :message="errors.title"
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="interview-doc-file">PDF</Label>
                    <FileChooseButton
                        id="interview-doc-file"
                        ref="documentPicker"
                        accept="application/pdf,.pdf"
                        :label="
                            documentForm.file
                                ? 'Choose another PDF'
                                : 'Choose PDF'
                        "
                        :status="
                            documentForm.file
                                ? documentForm.file.name
                                : 'No PDF chosen'
                        "
                        status-id="interview-doc-file-status"
                        :disabled="documentForm.processing"
                        aria-describedby="interview-doc-file-error"
                        @change="chooseDocument"
                    />
                    <InputError
                        id="interview-doc-file-error"
                        :message="errors.file"
                    />
                </div>
                <p
                    v-if="documentFull"
                    class="text-sm text-muted-foreground"
                    data-test="interview-document-full"
                >
                    This position type has {{ DOCS_MAX }} documents. Remove one
                    to add another.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="
                            documentForm.processing ||
                            documentFull ||
                            !documentForm.file ||
                            documentForm.title.trim().length < 3
                        "
                        data-test="interview-document-upload"
                        >{{
                            documentForm.processing ? 'Adding…' : 'Add document'
                        }}</Button
                    >
                    <span
                        v-if="
                            !documentFull &&
                            (!documentForm.file ||
                                documentForm.title.trim().length < 3)
                        "
                        class="text-sm text-muted-foreground"
                        data-test="interview-document-why"
                        >Choose a PDF and give it a title first.</span
                    >
                </div>
            </form>
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
                    <p
                        v-if="viewing.autoRejectOn"
                        class="flex items-start gap-2 text-sm"
                        :class="
                            viewing.autoRejectSoon
                                ? 'rounded-lg border border-warning/40 bg-warning/10 p-2 text-foreground'
                                : 'text-muted-foreground'
                        "
                        :role="viewing.autoRejectSoon ? 'status' : undefined"
                        data-test="application-auto-reject"
                    >
                        <TriangleAlert
                            v-if="viewing.autoRejectSoon"
                            class="mt-0.5 size-4 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <span
                            >Without a decision, KPOne closes this as
                            Auto-rejected on {{ viewing.autoRejectOn }} and
                            tells the candidate.</span
                        >
                    </p>

                    <!-- HR-17c: the candidate's times at Scheduling, the confirmed interview at Interview. -->
                    <section
                        v-if="viewing.interview"
                        class="flex flex-col gap-3 rounded-lg border p-3"
                        aria-labelledby="interview-title"
                        data-test="application-interview"
                    >
                        <h3 id="interview-title" class="font-semibold">
                            Interview
                            <span
                                v-if="viewing.interview.branch"
                                class="font-normal text-muted-foreground"
                                >· {{ viewing.interview.branch }}</span
                            >
                        </h3>
                        <template v-if="viewing.interview.confirmed">
                            <p class="text-sm">
                                Confirmed for
                                <strong>{{
                                    viewing.interview.confirmed
                                }}</strong
                                >.
                            </p>
                            <Button
                                variant="outline"
                                class="w-fit max-lg:min-h-11"
                                :disabled="interviewAction.processing"
                                data-test="interview-cancel"
                                @click="
                                    Object.assign(interviewAction, {
                                        cancelOpen: true,
                                        reason: '',
                                    })
                                "
                                >Cancel and ask for new times</Button
                            >
                            <div
                                class="flex flex-col gap-1"
                                data-test="interview-downloads"
                            >
                                <h4 class="text-sm font-medium">
                                    Interview documents
                                </h4>
                                <p
                                    v-if="!viewing.interview.documents?.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    None for this position type. Add them in
                                    Settings.
                                </p>
                                <ul v-else class="flex flex-col gap-1 text-sm">
                                    <li
                                        v-for="(doc, i) in viewing.interview
                                            .documents"
                                        :key="i"
                                    >
                                        {{ doc.title }} ·
                                        <span
                                            :class="
                                                doc.downloadedAt
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            "
                                            >{{
                                                doc.downloadedAt
                                                    ? `downloaded ${doc.downloadedAt}`
                                                    : 'not downloaded yet'
                                            }}</span
                                        >
                                    </li>
                                </ul>
                            </div>
                        </template>
                        <template v-else-if="viewing.interview.picks.length">
                            <p class="text-sm text-muted-foreground">
                                The candidate's three times. Confirm one; the
                                candidate is told.
                            </p>
                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="pick in viewing.interview.picks"
                                    :key="pick.start"
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2 text-sm"
                                >
                                    <span>
                                        {{ pick.label }}
                                        <span
                                            v-if="pick.taken || pick.past"
                                            class="text-muted-foreground"
                                            >·
                                            {{
                                                pick.taken
                                                    ? 'already taken'
                                                    : 'passed'
                                            }}</span
                                        >
                                    </span>
                                    <Button
                                        variant="outline"
                                        class="max-lg:min-h-11"
                                        :disabled="
                                            pick.taken ||
                                            pick.past ||
                                            interviewAction.processing
                                        "
                                        :aria-label="`Confirm ${pick.label}`"
                                        data-test="interview-confirm"
                                        @click="
                                            confirming = {
                                                start: pick.start,
                                                label: pick.label,
                                            }
                                        "
                                        >Confirm</Button
                                    >
                                </li>
                            </ul>
                            <Button
                                variant="outline"
                                class="w-fit max-lg:min-h-11"
                                :disabled="interviewAction.processing"
                                data-test="interview-ask-again"
                                @click="interviewPost('ask-again')"
                                >None suit: ask for new times</Button
                            >
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            Waiting for the candidate to choose three times on
                            their private page.
                        </p>
                        <InputError :message="errors.start" />
                    </section>

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

                    <section
                        v-if="['passed', 'failed'].includes(viewing.status)"
                        class="flex flex-col gap-2 rounded-xl border p-3"
                        aria-labelledby="after-result-title"
                        data-test="application-after-result"
                    >
                        <h3
                            id="after-result-title"
                            class="text-sm font-semibold"
                        >
                            After the result
                        </h3>
                        <p class="text-sm" data-test="application-pool-state">
                            {{
                                viewing.poolUntil
                                    ? `In the talent pool until ${viewing.poolUntil}.`
                                    : viewing.poolConsent
                                      ? 'Ticked the talent pool, but no pool period is set or it has ended.'
                                      : 'Not in the talent pool.'
                            }}
                        </p>
                        <template v-if="viewing.status === 'passed'">
                            <p
                                v-if="viewing.accountRequest"
                                class="text-sm"
                                data-test="application-account-request"
                            >
                                Staff account:
                                {{ viewing.accountRequest.statusLabel }} ·
                                {{ viewing.accountRequest.at }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground">
                                No offer is made in KPOne. When they accept, ask
                                the account administrator for a staff account.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-if="
                                        viewing.accountRequest?.status !==
                                            'open' &&
                                        viewing.accountRequest?.status !==
                                            'done' &&
                                        !viewing.purged
                                    "
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="accountAction.processing"
                                    data-test="application-account-ask"
                                    @click="
                                        accountPost(
                                            `/hr/applications/${viewing.id}/account-request`,
                                            viewing.lockVersion,
                                        )
                                    "
                                    >Ask for a staff account</Button
                                >
                                <Button
                                    v-if="
                                        viewing.accountRequest?.status ===
                                        'open'
                                    "
                                    variant="outline"
                                    class="max-lg:min-h-11"
                                    :disabled="accountAction.processing"
                                    data-test="application-account-cancel"
                                    @click="
                                        accountPost(
                                            `/hr/applications/account-requests/${viewing.accountRequest.id}/cancel`,
                                            viewing.accountRequest.lockVersion,
                                        )
                                    "
                                    >Cancel the request</Button
                                >
                            </div>
                        </template>
                    </section>

                    <div v-if="viewing.next.length" class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold">Move to</h3>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="step in moveSteps"
                                :key="step.value"
                                :variant="
                                    [
                                        'not_taken_forward',
                                        'withdrawn',
                                        'passed',
                                        'failed',
                                    ].includes(step.value) ||
                                    arrangedOutside(step.value)
                                        ? 'outline'
                                        : 'default'
                                "
                                class="max-lg:min-h-11"
                                :disabled="moving.processing"
                                :data-test="`application-move-${step.value}`"
                                @click="askMove(step.value)"
                                >{{
                                    arrangedOutside(step.value)
                                        ? 'Arranged outside KPOne'
                                        : step.label
                                }}</Button
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

                    <section
                        class="flex flex-col gap-3 border-t pt-4"
                        aria-labelledby="application-messages-title"
                        data-test="application-messages"
                    >
                        <h3
                            id="application-messages-title"
                            class="text-sm font-semibold"
                        >
                            Messages
                        </h3>
                        <p
                            v-if="!viewing.messages.length"
                            class="text-sm text-muted-foreground"
                        >
                            No messages yet.
                        </p>
                        <ul v-else class="flex flex-col gap-2">
                            <li
                                v-for="(m, i) in viewing.messages"
                                :key="i"
                                class="rounded-lg border p-3 text-sm"
                            >
                                <details class="group">
                                    <summary
                                        class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between gap-x-3 gap-y-1 [&::-webkit-details-marker]:hidden"
                                    >
                                        <span
                                            class="flex items-center gap-1 font-medium"
                                        >
                                            <ChevronRight
                                                class="size-4 shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                                                aria-hidden="true"
                                            />
                                            {{ m.label }}
                                            <span
                                                class="font-normal text-muted-foreground"
                                                >·
                                                {{
                                                    m.recipient === 'mailbox'
                                                        ? 'to the hiring mailbox'
                                                        : 'to the candidate'
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            :class="
                                                m.status === 'failed'
                                                    ? 'font-medium text-destructive'
                                                    : 'text-muted-foreground'
                                            "
                                            >{{ m.atLabel }} ·
                                            {{ m.statusLabel }}</span
                                        >
                                    </summary>
                                    <p class="mt-2 font-medium break-words">
                                        {{ m.subject }}
                                    </p>
                                    <p
                                        class="mt-1 break-words whitespace-pre-line text-muted-foreground"
                                    >
                                        {{ m.body }}
                                    </p>
                                </details>
                            </li>
                        </ul>
                    </section>
                </div>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="inviting !== null"
            :title="`Invite ${inviting?.member.name ?? ''} to ${inviting?.position.label ?? ''}?`"
            description="A new application starts at phone screening with the same answers and CV, and the candidate gets a new private link. Call them soon: like any application at phone screening, the daily check closes it as Auto-rejected after 3 working days without a decision."
            confirm-label="Invite"
            :processing="inviteProcessing"
            @update:open="(open) => !open && (inviting = null)"
            @confirm="sendInvite"
        />

        <OperationalConfirmDialog
            :open="removingDocument !== null"
            :title="`Remove ${removingDocument?.title ?? ''}?`"
            description="Candidates can no longer download it, and its file is deleted. The record that it existed and who downloaded it is kept."
            confirm-label="Remove document"
            cancel-label="Keep document"
            destructive
            :processing="removeDocumentProcessing"
            :error="errors.document"
            @update:open="(open) => !open && (removingDocument = null)"
            @confirm="removeDocument"
        />

        <OperationalConfirmDialog
            :open="confirming !== null"
            :title="`Confirm ${confirming?.label ?? ''}?`"
            description="The candidate is told the date, time and branch."
            confirm-label="Confirm interview"
            :processing="interviewAction.processing"
            :error="errors.start"
            data-test="interview-confirm-dialog"
            @update:open="(open) => !open && (confirming = null)"
            @confirm="
                confirming &&
                interviewPost('confirm', { start: confirming.start })
            "
        />

        <OperationalConfirmDialog
            :open="interviewAction.cancelOpen"
            title="Cancel this interview?"
            description="The slot is freed, the application goes back to Scheduling and the candidate is asked for three new times."
            confirm-label="Cancel interview"
            cancel-label="Keep interview"
            destructive
            :processing="interviewAction.processing"
            :error="errors.reason"
            @update:open="(open) => (interviewAction.cancelOpen = open)"
            @confirm="
                interviewPost('cancel', { reason: interviewAction.reason })
            "
        >
            <fieldset
                class="flex flex-col gap-1"
                data-test="interview-cancel-reasons"
            >
                <legend class="mb-1 text-sm font-medium">Why?</legend>
                <label
                    v-for="r in applications.options.cancelReasons"
                    :key="r.value"
                    class="flex min-h-11 items-center gap-3 text-sm"
                >
                    <input
                        v-model="interviewAction.reason"
                        type="radio"
                        name="interview-cancel-reason"
                        :value="r.value"
                        class="size-5"
                    />
                    {{ r.label }}
                </label>
            </fieldset>
        </OperationalConfirmDialog>

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
