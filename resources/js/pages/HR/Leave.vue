<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarCheck, CalendarX, Trash2 } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type Tab = 'my' | 'approvals' | 'calendar' | 'settings';
type Option = { value: string; label: string };
type Status = 'pending' | 'approved' | 'rejected' | 'cancelled';
type RequestRow = {
    publicId: string;
    dates: string;
    startDate: string;
    endDate: string;
    type: string;
    days: string;
    status: Status;
    statusLabel: string;
    lockVersion: number;
};
type EvidenceFile = {
    publicId: string;
    kind: 'jpeg' | 'png' | 'pdf';
    label: string;
    size: string;
    addedBy: string;
    onBehalf: boolean;
    addedOn: string;
    url: string;
    lockVersion: number;
    canRemove: boolean;
};
type Evidence = {
    acceptsFiles: boolean;
    retentionDays: number | null;
    canAdd: boolean;
    files: EvidenceFile[];
} | null;
type MyRequest = RequestRow & {
    reason: string | null;
    canCancel: boolean;
    evidence: Evidence;
};
type PendingRow = RequestRow & {
    person: string;
    role: string;
    evidenceNeeded: boolean;
    evidenceReference: string | null;
    reason: string | null;
    leftAfter: string | null;
    warnings: string[];
    evidence: Evidence;
};
type ApprovedRow = RequestRow & {
    person: string;
    role: string;
    evidence: Evidence;
};
type LeaveTypeOption = {
    id: number;
    name: string;
    allowHalfDays: boolean;
    evidenceNeeded: boolean;
    usesBalance: boolean;
    acceptsFiles: boolean;
    retentionDays: number | null;
};
type Balance = {
    typeId: number;
    name: string;
    counted: boolean;
    entitled: string;
    taken: string;
    booked: string;
    left: string;
};
type TypeRow = {
    id: number;
    code: string;
    name: string;
    isPaid: boolean;
    usesBalance: boolean;
    allowHalfDays: boolean;
    evidenceNeeded: boolean;
    allowNegative: boolean;
    carryMaxDays: string;
    carryExpiresOn: string;
    isActive: boolean;
    acceptsEvidenceFiles: boolean;
    evidenceRetentionDays: string;
    lockVersion: number;
};
type Entry = {
    publicId: string;
    kind: 'opening' | 'adjustment';
    person: string;
    type: string;
    year: number;
    days: string;
    reason: string | null;
    note: string | null;
    status: 'pending' | 'confirmed' | 'rejected';
    enteredBy: string;
    canDecide: boolean;
    lockVersion: number;
};
type Settings = {
    types: TypeRow[];
    roles: Option[];
    entitlements: {
        leaveTypeId: number;
        role: string | null;
        userId: number | null;
        person: string | null;
        days: string;
    }[];
    grantPreview: { people: number; lines: number };
    people: { id: number; label: string }[];
    workingDays: {
        branchId: number;
        branch: string;
        weekdays: number[];
        lockVersion: number | null;
    }[];
    holidays: { id: number; date: string; label: string; name: string }[];
    entries: Entry[];
    adjustmentReasons: Option[];
};
type Calendar = {
    month: string;
    monthLabel: string;
    previous: string;
    next: string;
    workingDaysSet: boolean;
    days: {
        date: string;
        day: number;
        weekday: string;
        label: string;
        holiday: string | null;
        working: boolean;
    }[];
    rows: { name: string; cells: Record<string, 'full' | 'half'> }[];
};

const props = defineProps<{
    leave: {
        tab: Tab;
        tabs: Tab[];
        today: string;
        year: number;
        setUp: boolean;
        approvalCount: number;
        branches: { id: number; name: string }[];
        branchId: number | null;
        my: {
            balances: Balance[];
            types: LeaveTypeOption[];
            requests: MyRequest[];
        } | null;
        approvals: { pending: PendingRow[]; approved: ApprovedRow[] } | null;
        calendar: Calendar | null;
        settings: Settings | null;
        rejectReasons: Option[];
        cancelReasons: Option[];
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const tabLabels: Record<Tab, string> = {
    my: 'My leave',
    approvals: 'Approvals',
    calendar: 'Team calendar',
    settings: 'Settings',
};
const tabs = computed(() =>
    props.leave.tabs.map((value) => ({
        value,
        label:
            value === 'approvals' && props.leave.approvalCount > 0
                ? `Approvals (${props.leave.approvalCount})`
                : tabLabels[value],
    })),
);
const statusTone: Record<Status, 'warning' | 'success' | 'danger' | 'neutral'> =
    {
        pending: 'warning',
        approved: 'success',
        rejected: 'neutral',
        cancelled: 'neutral',
    };

const visit = (params: Record<string, string | number | undefined>) =>
    router.get(
        '/hr/leave',
        {
            tab: props.leave.tab,
            branch: props.leave.branchId ?? undefined,
            year: props.leave.year,
            ...params,
        },
        { preserveScroll: true },
    );
const branchOptions = computed(() =>
    props.leave.branches.map((b) => ({ value: b.id, label: b.name })),
);
const yearOptions = computed(() => {
    const now = Number(props.leave.today.slice(0, 4));

    return [now - 1, now, now + 1].map((y) => ({
        value: y,
        label: String(y),
    }));
});
const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

// ---- Tabs (DD-30 pattern from the Queue board, #296) ----
const tabStrip = useTemplateRef<HTMLElement>('tabStrip');
const tabNav = () =>
    tabStrip.value?.querySelector<HTMLElement>('[role="tablist"]') ?? null;
const moreTabsRight = ref(false);
const updateTabFade = () => {
    const nav = tabNav();

    moreTabsRight.value =
        nav !== null &&
        nav.scrollWidth > nav.clientWidth + 1 &&
        nav.scrollLeft + nav.clientWidth < nav.scrollWidth - 1;
};
/** Bring a part-hidden chosen tab fully into view, sideways only. */
const revealSelectedTab = async () => {
    await nextTick();
    const nav = tabNav();
    const tab = nav?.querySelector<HTMLElement>('[aria-selected="true"]');

    if (!nav || !tab) {
        return;
    }

    const navBox = nav.getBoundingClientRect();
    const tabBox = tab.getBoundingClientRect();

    if (tabBox.left < navBox.left) {
        nav.scrollLeft -= navBox.left - tabBox.left;
    } else if (tabBox.right > navBox.right) {
        nav.scrollLeft += tabBox.right - navBox.right;
    }
};
const selectLeaveTab = (tab: string) => visit({ tab });
let tabResizeObserver: ResizeObserver | undefined;
onMounted(() => {
    const nav = tabNav();

    nav?.addEventListener('scroll', updateTabFade, { passive: true });

    if (nav && typeof ResizeObserver !== 'undefined') {
        tabResizeObserver = new ResizeObserver(updateTabFade);
        tabResizeObserver.observe(nav);
    }

    void revealSelectedTab().then(updateTabFade);
});
onBeforeUnmount(() => {
    tabNav()?.removeEventListener('scroll', updateTabFade);
    tabResizeObserver?.disconnect();
});

// ---- Apply ----
const applyOpen = ref(false);
const applyKey = ref('');
const applyForm = useForm({
    leave_type_id: '' as string | number,
    start_date: '',
    end_date: '',
    start_part: 'full',
    end_part: 'full',
    reason: '',
    evidence_reference: '',
    evidence_files: [] as File[],
});
const chosenType = computed(() =>
    props.leave.my?.types.find(
        (t) => String(t.id) === String(applyForm.leave_type_id),
    ),
);
const singleDay = computed(
    () =>
        applyForm.start_date !== '' &&
        applyForm.start_date === applyForm.end_date,
);
const firstDayOptions = computed(() =>
    singleDay.value
        ? [
              { value: 'full', label: 'None (whole day)' },
              { value: 'am', label: 'Morning only' },
              { value: 'pm', label: 'Afternoon only' },
          ]
        : [
              { value: 'full', label: 'None (whole day)' },
              { value: 'pm', label: 'Start in the afternoon' },
          ],
);
const lastDayOptions = [
    { value: 'full', label: 'None (whole day)' },
    { value: 'am', label: 'End at midday' },
];
const preview = reactive({ text: '', error: '' });
let previewTimer: ReturnType<typeof setTimeout> | undefined;
// The day-count check shares the session with the form. If it ran while the form was being sent, its saved session
// could overwrite the validation errors of the send, and the sheet would close with nothing shown. So a send first
// cancels a waiting check and waits for one already running.
let previewInFlight: Promise<void> | null = null;
const runPreview = () => {
    if (applyForm.processing) {
        return Promise.resolve();
    }

    previewInFlight = fetchPreview().finally(() => (previewInFlight = null));

    return previewInFlight;
};
const fetchPreview = async () => {
    preview.text = '';
    preview.error = '';

    if (
        !applyForm.leave_type_id ||
        !applyForm.start_date ||
        !applyForm.end_date
    ) {
        return;
    }

    const response = await fetch('/hr/leave/preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
            leave_type_id: applyForm.leave_type_id,
            start_date: applyForm.start_date,
            end_date: applyForm.end_date,
            start_part: applyForm.start_part,
            end_part: singleDay.value ? 'full' : applyForm.end_part,
        }),
    });
    const data = (await response.json().catch(() => ({}))) as {
        days?: string;
        skipped?: string[];
        leftAfter?: string | null;
        shortfall?: string | null;
        errors?: Record<string, string>;
    };

    if (!response.ok) {
        preview.error = Object.values(data.errors ?? {})[0] ?? '';

        return;
    }

    if (data.shortfall) {
        preview.error = data.shortfall;

        return;
    }

    const skipped =
        (data.skipped ?? []).length > 0
            ? ` (${(data.skipped ?? []).join(', ')} not counted)`
            : '';
    const left =
        data.leftAfter !== null && data.leftAfter !== undefined
            ? ` ${data.leftAfter} left after this.`
            : '';
    preview.text = `${data.days} working ${data.days === '1' ? 'day' : 'days'}${skipped}.${left}`;
};
watch(
    () => [
        applyForm.leave_type_id,
        applyForm.start_date,
        applyForm.end_date,
        applyForm.start_part,
        applyForm.end_part,
    ],
    () => {
        // A changed field makes the last server message stale; the live line speaks for the new dates.
        applyForm.clearErrors(
            'start_date',
            'end_date',
            'start_part',
            'end_part',
        );
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runPreview, 300);
    },
);
watch(singleDay, (single) => {
    if (!single && applyForm.start_part === 'am') {
        applyForm.start_part = 'full';
    }
});
const openApply = () => {
    applyKey.value = crypto.randomUUID();
    applyForm.reset();
    applyForm.clearErrors();
    preview.text = '';
    preview.error = '';
    applyOpen.value = true;
};
// HR-2b (Daniel): a labelled button opens the file picker; chosen files are listed with a Remove link.
const applyFileInput = ref<HTMLInputElement | null>(null);
const sizeLabel = (bytes: number) =>
    bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
const chooseApplyFiles = (event: Event) => {
    const input = event.target as HTMLInputElement;
    applyForm.evidence_files = [
        ...applyForm.evidence_files,
        ...Array.from(input.files ?? []),
    ].slice(0, 3);
    input.value = '';
};
const dropApplyFile = (index: number) => {
    applyForm.evidence_files = applyForm.evidence_files.filter(
        (_, i) => i !== index,
    );
};
const applyFileError = computed(
    () =>
        applyForm.errors.evidence_files ??
        (applyForm.errors as Record<string, string | undefined>)[
            'evidence_files.0'
        ],
);
const sendApply = async () => {
    clearTimeout(previewTimer);
    await previewInFlight?.catch(() => undefined);
    applyForm
        .transform((data) => ({
            ...data,
            end_part: singleDay.value ? 'full' : data.end_part,
            idempotency_key: applyKey.value,
        }))
        .post('/hr/leave/requests', {
            preserveScroll: true,
            forceFormData: applyForm.evidence_files.length > 0,
            onSuccess: () => (applyOpen.value = false),
        });
};

// ---- Evidence panel (HR-2b) ----
type EvidenceRow = RequestRow & { evidence: Evidence; person?: string };
const evidence = reactive({
    open: false,
    row: null as EvidenceRow | null,
    uploading: false,
    error: '',
    key: '',
});
const evidenceRow = computed(() => {
    const id = evidence.row?.publicId;

    if (!id) {
        return null;
    }

    const all: EvidenceRow[] = [
        ...(props.leave.my?.requests ?? []),
        ...(props.leave.approvals?.pending ?? []),
        ...(props.leave.approvals?.approved ?? []),
    ];

    return all.find((r) => r.publicId === id) ?? evidence.row;
});
const evidenceFileInput = ref<HTMLInputElement | null>(null);
const openEvidence = (row: EvidenceRow) => {
    evidence.row = row;
    evidence.error = '';
    evidence.open = true;
};
const fileCountText = (files: EvidenceFile[]) =>
    files.length === 0
        ? 'No file yet'
        : `${files.length} ${files.length === 1 ? 'file' : 'files'}`;
const photoAlt = (file: EvidenceFile, files: EvidenceFile[]) => {
    const photos = files.filter((f) => f.kind !== 'pdf');

    return `Photo ${photos.indexOf(file) + 1} of ${photos.length}, added by ${file.addedBy} on ${file.addedOn}`;
};
const uploadEvidence = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const row = evidenceRow.value;
    input.value = '';

    if (!file || !row) {
        return;
    }

    evidence.key = crypto.randomUUID();
    router.post(
        `/hr/leave/requests/${row.publicId}/evidence`,
        { file, idempotency_key: evidence.key, tab: props.leave.tab },
        {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => {
                evidence.uploading = true;
                evidence.error = '';
            },
            onFinish: () => (evidence.uploading = false),
            onError: (e: Record<string, string>) =>
                (evidence.error = e.file ?? e.leave ?? 'Try again.'),
        },
    );
};
const removal = reactive({
    open: false,
    file: null as EvidenceFile | null,
    reason: '',
    key: '',
    processing: false,
    error: '',
});
const removalNeedsReason = computed(
    () =>
        evidenceRow.value !== null &&
        !props.leave.my?.requests.some(
            (r) => r.publicId === evidenceRow.value?.publicId,
        ),
);
const openRemoval = (file: EvidenceFile) => {
    removal.file = file;
    removal.reason = '';
    removal.error = '';
    removal.key = crypto.randomUUID();
    removal.open = true;
};
const confirmRemoval = () => {
    const file = removal.file;

    if (!file) {
        return;
    }

    if (removalNeedsReason.value && removal.reason === '') {
        removal.error = 'Choose a reason.';

        return;
    }

    router.post(
        `/hr/leave/evidence/${file.publicId}/remove`,
        {
            expected_lock_version: file.lockVersion,
            reason: removal.reason || undefined,
            idempotency_key: removal.key,
            tab: props.leave.tab,
        },
        {
            preserveScroll: true,
            onStart: () => (removal.processing = true),
            onFinish: () => (removal.processing = false),
            onSuccess: () => (removal.open = false),
            onError: (e: Record<string, string>) =>
                (removal.error = e.leave ?? e.reason ?? 'Try again.'),
        },
    );
};
const removalReasons = [
    { value: 'wrong_file', label: 'Wrong file' },
    { value: 'wrong_person', label: 'Wrong person' },
    { value: 'not_readable', label: 'Not readable' },
];

// ---- Request actions (cancel, approve, reject) ----
type ActionKind = 'cancel' | 'approve' | 'reject' | 'manager-cancel';
const action = reactive({
    open: false,
    kind: 'cancel' as ActionKind,
    row: null as RequestRow | PendingRow | null,
    evidenceNeeded: false,
    evidenceSeen: false,
    reason: '',
    key: '',
    processing: false,
    error: '',
});
const actionText = computed(() => {
    const copies = {
        cancel: {
            title: 'Cancel this leave request?',
            description:
                'The request is cancelled. Any days it used come back.',
            confirm: 'Cancel request',
            busy: 'Cancelling…',
        },
        approve: {
            title: 'Approve this leave?',
            description:
                'The days are taken from the balance. Shifts already on the roster are not changed.',
            confirm: 'Approve leave',
            busy: 'Approving…',
        },
        reject: {
            title: 'Reject this leave request?',
            description: 'The staff member sees the reason you choose.',
            confirm: 'Reject',
            busy: 'Rejecting…',
        },
        'manager-cancel': {
            title: 'Cancel approved leave?',
            description: 'The days come back to the balance.',
            confirm: 'Cancel approved leave',
            busy: 'Cancelling…',
        },
    } as const;

    return copies[action.kind];
});
const actionDisabled = computed(
    () =>
        (action.kind === 'approve' &&
            action.evidenceNeeded &&
            !action.evidenceSeen) ||
        ((action.kind === 'reject' || action.kind === 'manager-cancel') &&
            action.reason === ''),
);
const openAction = (kind: ActionKind, row: RequestRow | PendingRow) => {
    action.kind = kind;
    action.row = row;
    action.evidenceNeeded = 'evidenceNeeded' in row && row.evidenceNeeded;
    action.evidenceSeen = false;
    action.reason = '';
    action.error = '';
    action.key = crypto.randomUUID();
    action.open = true;
};
const confirmAction = () => {
    const row = action.row;

    if (!row) {
        return;
    }

    // The shared dialog has no disabled state, so the confirm explains what is missing.
    if (actionDisabled.value) {
        action.error =
            action.kind === 'approve'
                ? 'Tick "Evidence seen" after checking the paper copy.'
                : 'Choose a reason.';

        return;
    }

    const path = action.kind === 'manager-cancel' ? 'cancel' : action.kind;
    router.post(
        `/hr/leave/requests/${row.publicId}/${path}`,
        {
            expected_lock_version: row.lockVersion,
            idempotency_key: action.key,
            evidence_seen:
                action.kind === 'approve' ? action.evidenceSeen : undefined,
            reason: action.reason || undefined,
            tab: props.leave.tab,
        },
        {
            preserveScroll: true,
            onStart: () => (action.processing = true),
            onFinish: () => (action.processing = false),
            onSuccess: () => (action.open = false),
            onError: (e: Record<string, string>) =>
                (action.error =
                    e.leave ?? e.reason ?? e.evidence_seen ?? 'Try again.'),
        },
    );
};

// ---- Settings: leave types ----
const typeOpen = ref(false);
const typeForm = useForm({
    leave_type_id: null as number | null,
    expected_lock_version: null as number | null,
    code: '',
    name: '',
    is_paid: true,
    uses_balance: true,
    allow_half_days: true,
    evidence_needed: false,
    allow_negative: false,
    carry_max_days: '',
    carry_expires_on: '',
    is_active: true,
    accepts_evidence_files: false,
    evidence_retention_days: '',
});
const openType = (row?: TypeRow) => {
    typeForm.clearErrors();
    typeForm.leave_type_id = row?.id ?? null;
    typeForm.expected_lock_version = row?.lockVersion ?? null;
    typeForm.code = row?.code ?? '';
    typeForm.name = row?.name ?? '';
    typeForm.is_paid = row?.isPaid ?? true;
    typeForm.uses_balance = row?.usesBalance ?? true;
    typeForm.allow_half_days = row?.allowHalfDays ?? true;
    typeForm.evidence_needed = row?.evidenceNeeded ?? false;
    typeForm.allow_negative = row?.allowNegative ?? false;
    typeForm.carry_max_days = row?.carryMaxDays ?? '';
    typeForm.carry_expires_on = row?.carryExpiresOn ?? '';
    typeForm.is_active = row?.isActive ?? true;
    typeForm.accepts_evidence_files = row?.acceptsEvidenceFiles ?? false;
    typeForm.evidence_retention_days = row?.evidenceRetentionDays ?? '';
    savedRetentionDays.value = Number(row?.evidenceRetentionDays ?? 0);
    typeOpen.value = true;
};
// PDPA 15 (owner, 2026-11-11: "cap at 7 years"): leave records go 7 years after their leave year, so files cannot be kept longer.
const MAX_EVIDENCE_DAYS = 2555;
const savedRetentionDays = ref(0);
const saveType = () =>
    typeForm.put('/hr/leave/types', {
        preserveScroll: true,
        onSuccess: () => (typeOpen.value = false),
    });
const archiveType = (row: TypeRow) =>
    router.put(
        '/hr/leave/types',
        {
            leave_type_id: row.id,
            expected_lock_version: row.lockVersion,
            name: row.name,
            is_paid: row.isPaid,
            uses_balance: row.usesBalance,
            allow_half_days: row.allowHalfDays,
            evidence_needed: row.evidenceNeeded,
            allow_negative: row.allowNegative,
            carry_max_days: row.carryMaxDays,
            carry_expires_on: row.carryExpiresOn,
            is_active: !row.isActive,
            accepts_evidence_files: row.acceptsEvidenceFiles,
            evidence_retention_days: row.evidenceRetentionDays,
        },
        { preserveScroll: true },
    );
const flags: { key: keyof typeof typeForm & string; label: string }[] = [
    { key: 'is_paid', label: 'Paid' },
    { key: 'uses_balance', label: 'Counts against a balance' },
    { key: 'allow_half_days', label: 'Half days allowed' },
    { key: 'evidence_needed', label: 'Evidence needed' },
    { key: 'allow_negative', label: 'May go below zero' },
];
const setFlag = (key: string, value: boolean) => {
    (typeForm as unknown as Record<string, boolean>)[key] = value;
};
const flagValue = (key: string) =>
    (typeForm as unknown as Record<string, boolean>)[key];
const yesNo = (value: boolean) => (value ? 'Yes' : 'No');

// ---- Settings: entitlements ----
const balanceTypes = computed(
    () => props.leave.settings?.types.filter((t) => t.usesBalance) ?? [],
);
const entitlementValues = reactive<Record<string, string>>({});
const resetEntitlements = () => {
    for (const key of Object.keys(entitlementValues)) {
        delete entitlementValues[key];
    }

    for (const row of props.leave.settings?.entitlements ?? []) {
        if (row.role) {
            entitlementValues[`${row.leaveTypeId}:${row.role}`] = row.days;
        }
    }
};
watch(() => props.leave.settings?.entitlements, resetEntitlements, {
    immediate: true,
});
const personEntitlements = computed(
    () => props.leave.settings?.entitlements.filter((e) => e.userId) ?? [],
);
const entitlementSaving = ref(false);
const saveEntitlements = () => {
    const rows = Object.entries(entitlementValues)
        .filter(([, days]) => days !== '' && days !== undefined)
        .map(([key, days]) => {
            const [typeId, role] = key.split(':');

            return { leave_type_id: Number(typeId), role, days };
        });
    router.put(
        '/hr/leave/entitlements',
        { year: props.leave.year, rows },
        {
            preserveScroll: true,
            onStart: () => (entitlementSaving.value = true),
            onFinish: () => (entitlementSaving.value = false),
        },
    );
};
const personForm = useForm({
    user_id: '' as string | number,
    leave_type_id: '' as string | number,
    days: '',
});
const savePerson = () =>
    personForm
        .transform((data) => ({
            year: props.leave.year,
            rows: [{ ...data, role: null }],
        }))
        .put('/hr/leave/entitlements', {
            preserveScroll: true,
            onSuccess: () => personForm.reset(),
        });

// Year-end actions share one dialog.
type YearAction = 'grant' | 'carry-forward' | 'expire';
const yearAction = reactive({
    open: false,
    kind: 'grant' as YearAction,
    key: '',
    processing: false,
    error: '',
});
const yearText = computed(() => {
    const y = props.leave.year;
    const preview = props.leave.settings?.grantPreview;

    return {
        grant: {
            title: `Give the ${y} entitlement?`,
            description:
                preview && preview.lines > 0
                    ? `This adds ${preview.lines} balance ${preview.lines === 1 ? 'line' : 'lines'} for ${preview.people} ${preview.people === 1 ? 'person' : 'people'}. Pressing it again later adds only what changed.`
                    : 'Everyone already has this year’s entitlement. Nothing will change.',
            confirm: 'Give entitlement',
        },
        'carry-forward': {
            title: `Carry forward from ${y}?`,
            description: `Unused days, up to each type’s limit, move to ${y + 1}. This runs once per type.`,
            confirm: 'Carry forward',
        },
        expire: {
            title: `Expire carried days for ${y}?`,
            description:
                'Carried days not used before each type’s expiry date are removed.',
            confirm: 'Expire carried days',
        },
    }[yearAction.kind];
});
const openYear = (kind: YearAction) => {
    yearAction.kind = kind;
    yearAction.key = crypto.randomUUID();
    yearAction.error = '';
    yearAction.open = true;
};
const confirmYear = () =>
    router.post(
        `/hr/leave/${yearAction.kind}`,
        { year: props.leave.year, idempotency_key: yearAction.key },
        {
            preserveScroll: true,
            onStart: () => (yearAction.processing = true),
            onFinish: () => (yearAction.processing = false),
            onSuccess: () => (yearAction.open = false),
            onError: (e: Record<string, string>) =>
                (yearAction.error = e.year ?? e.leave ?? 'Try again.'),
        },
    );

// ---- Settings: working days and holidays ----
const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];
const workingRow = computed(() =>
    props.leave.settings?.workingDays.find(
        (w) => w.branchId === props.leave.branchId,
    ),
);
const workingDays = ref<number[]>([]);
watch(
    workingRow,
    (row) => {
        workingDays.value = [...(row?.weekdays ?? [])];
    },
    { immediate: true },
);
const toggleDay = (day: number, on: boolean) => {
    workingDays.value = on
        ? [...new Set([...workingDays.value, day])].sort()
        : workingDays.value.filter((d) => d !== day);
};
const saveWorkingDays = () =>
    router.put(
        '/hr/leave/working-days',
        {
            branch_id: props.leave.branchId,
            year: props.leave.year,
            weekdays: workingDays.value,
            expected_lock_version: workingRow.value?.lockVersion ?? null,
        },
        { preserveScroll: true },
    );
const holidayForm = useForm({ holiday_date: '', name: '' });
const addHoliday = () =>
    holidayForm
        .transform((data) => ({
            ...data,
            branch_id: props.leave.branchId,
            year: props.leave.year,
        }))
        .post('/hr/leave/holidays', {
            preserveScroll: true,
            onSuccess: () => holidayForm.reset(),
        });
const removeHoliday = (id: number) =>
    router.delete(`/hr/leave/holidays/${id}`, {
        data: { branch_id: props.leave.branchId, year: props.leave.year },
        preserveScroll: true,
    });

// ---- Settings: openings and adjustments ----
const entryKey = ref(crypto.randomUUID());
const entryForm = useForm({
    kind: 'opening' as 'opening' | 'adjustment',
    user_id: '' as string | number,
    leave_type_id: '' as string | number,
    days: '',
    reason_code: '',
    note: '',
});
const saveEntry = () =>
    entryForm
        .transform((data) => ({
            ...data,
            year: props.leave.year,
            idempotency_key: entryKey.value,
        }))
        .post(
            `/hr/leave/${entryForm.kind === 'opening' ? 'openings' : 'adjustments'}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    entryForm.reset();
                    entryKey.value = crypto.randomUUID();
                },
            },
        );
const decideEntry = (entry: Entry, decision: 'confirm' | 'reject') =>
    router.post(
        `/hr/leave/${entry.kind === 'opening' ? 'openings' : 'adjustments'}/${entry.publicId}/${decision}`,
        {
            expected_lock_version: entry.lockVersion,
            year: props.leave.year,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );
const entryTone = {
    pending: 'warning',
    confirmed: 'success',
    rejected: 'danger',
} as const;
const entryLabel = {
    pending: 'Waiting for a check',
    confirmed: 'Confirmed',
    rejected: 'Rejected',
} as const;
const peopleOptions = computed(() =>
    (props.leave.settings?.people ?? []).map((p) => ({
        value: p.id,
        label: p.label,
    })),
);
const balanceTypeOptions = computed(() =>
    balanceTypes.value.map((t) => ({ value: t.id, label: t.name })),
);
const typeName = (id: number) =>
    props.leave.settings?.types.find((t) => t.id === id)?.name ?? 'Leave';

// ---- Calendar ----
const calendar = computed(() => props.leave.calendar);
const branchName = computed(
    () =>
        props.leave.branches.find((b) => b.id === props.leave.branchId)?.name ??
        '',
);
const cellName = (name: string, label: string, cell: 'full' | 'half') =>
    `${name}, ${label}, ${cell === 'half' ? 'half day' : 'on leave'}`;
const calendarDays = computed(() =>
    (calendar.value?.days ?? [])
        .map((day) => ({
            ...day,
            people: (calendar.value?.rows ?? [])
                .filter((row) => row.cells[day.date])
                .map((row) =>
                    row.cells[day.date] === 'half'
                        ? `${row.name} (half day)`
                        : row.name,
                ),
        }))
        .filter((day) => day.people.length > 0 || day.holiday),
);
</script>

<template>
    <Head title="Leave" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <PageHeader
            eyebrow="KPOne HR"
            title="Leave"
            description="Apply for leave and see your balance. Someone else approves each request."
        />

        <!-- DD-30 pattern (#296): on phones the strip scrolls, fades while more tabs are hidden, and has 44 px tabs. -->
        <div v-if="tabs.length > 1" ref="tabStrip" class="min-w-0">
            <OperationalTabs
                :model-value="leave.tab"
                :tabs="tabs"
                label="Leave sections"
                class="min-w-0 max-sm:[&>button]:h-11 max-sm:[&>button]:px-2.5"
                :class="
                    moreTabsRight
                        ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                        : ''
                "
                @update:model-value="selectLeaveTab"
            />
        </div>

        <ErrorState v-if="errors.leave" :message="errors.leave" />

        <!-- My leave -->
        <section
            v-if="leave.tab === 'my' && leave.my"
            aria-labelledby="my-leave-title"
            class="flex flex-col gap-4"
        >
            <h2 id="my-leave-title" class="sr-only">My leave</h2>
            <EmptyState
                v-if="!leave.setUp"
                title="Leave is not set up yet."
                description="Ask HR to add the leave types for the clinic."
            />
            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold">
                        Balance for {{ leave.today.slice(0, 4) }}
                    </h3>
                    <Button
                        class="min-h-11 sm:min-h-9"
                        data-test="apply-leave"
                        @click="openApply"
                        >Apply for leave</Button
                    >
                </div>
                <ul
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    data-test="balances"
                >
                    <li
                        v-for="b in leave.my.balances"
                        :key="b.typeId"
                        class="rounded-xl border bg-card p-4"
                    >
                        <h4 class="text-sm font-semibold">{{ b.name }}</h4>
                        <p
                            v-if="!b.counted"
                            class="mt-2 text-sm text-muted-foreground"
                        >
                            Not counted
                        </p>
                        <dl
                            v-else
                            class="mt-2 grid grid-cols-4 gap-2 text-sm tabular-nums"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Entitled
                                </dt>
                                <dd class="font-medium">{{ b.entitled }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Taken
                                </dt>
                                <dd class="font-medium">{{ b.taken }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Booked
                                </dt>
                                <dd class="font-medium">{{ b.booked }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Left
                                </dt>
                                <dd class="font-semibold">{{ b.left }}</dd>
                            </div>
                        </dl>
                    </li>
                </ul>

                <h3 class="text-base font-semibold">My requests</h3>
                <EmptyState
                    v-if="leave.my.requests.length === 0"
                    title="No leave requests yet."
                    description="Apply for leave to see it here."
                />
                <template v-else>
                    <div class="hidden lg:block">
                        <OperationalTable
                            label="My leave requests"
                            :columns="6"
                            min-width="760px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Dates</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Days</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Evidence</th>
                                    <th scope="col">
                                        <span class="sr-only">Action</span>
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="r in leave.my.requests"
                                    :key="r.publicId"
                                >
                                    <td>{{ r.dates }}</td>
                                    <td>{{ r.type }}</td>
                                    <td class="tabular-nums">{{ r.days }}</td>
                                    <td>
                                        <StatusBadge
                                            :status="`leave_${r.status}`"
                                            :label="r.statusLabel"
                                            :tone="statusTone[r.status]"
                                        />
                                    </td>
                                    <td>
                                        <p
                                            v-if="r.evidence"
                                            class="flex flex-wrap items-center gap-2 text-sm"
                                            data-test="evidence-cell"
                                        >
                                            <span class="text-muted-foreground"
                                                >Evidence:
                                                {{
                                                    fileCountText(
                                                        r.evidence.files,
                                                    )
                                                }}</span
                                            >
                                            <Button
                                                v-if="
                                                    r.evidence.files.length > 0
                                                "
                                                size="sm"
                                                variant="outline"
                                                class="min-h-11 sm:min-h-8"
                                                @click="openEvidence(r)"
                                                >View<span class="sr-only">
                                                    files for {{ r.type }},
                                                    {{ r.dates }}</span
                                                ></Button
                                            >
                                            <Button
                                                v-if="r.evidence.canAdd"
                                                size="sm"
                                                variant="outline"
                                                class="min-h-11 sm:min-h-8"
                                                @click="openEvidence(r)"
                                                >Add file<span class="sr-only">
                                                    to {{ r.type }},
                                                    {{ r.dates }}</span
                                                ></Button
                                            >
                                        </p>
                                    </td>
                                    <td class="text-right">
                                        <Button
                                            v-if="r.canCancel"
                                            size="sm"
                                            variant="outline"
                                            @click="openAction('cancel', r)"
                                            >Cancel request</Button
                                        >
                                    </td>
                                </tr>
                            </template>
                        </OperationalTable>
                    </div>
                    <ul class="flex flex-col gap-3 lg:hidden">
                        <li
                            v-for="r in leave.my.requests"
                            :key="r.publicId"
                            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="text-sm font-medium">{{ r.dates }}</p>
                                <StatusBadge
                                    :status="`leave_${r.status}`"
                                    :label="r.statusLabel"
                                    :tone="statusTone[r.status]"
                                />
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ r.type }} · {{ r.days }}
                                {{ r.days === '1' ? 'day' : 'days' }}
                            </p>
                            <p
                                v-if="r.evidence"
                                class="flex flex-wrap items-center gap-2 text-sm"
                                data-test="evidence-line"
                            >
                                <span class="text-muted-foreground"
                                    >Evidence:
                                    {{ fileCountText(r.evidence.files) }}</span
                                >
                                <Button
                                    v-if="r.evidence.files.length > 0"
                                    size="sm"
                                    variant="outline"
                                    class="min-h-11 sm:min-h-8"
                                    @click="openEvidence(r)"
                                    >View<span class="sr-only">
                                        files for {{ r.type }},
                                        {{ r.dates }}</span
                                    ></Button
                                >
                                <Button
                                    v-if="r.evidence.canAdd"
                                    size="sm"
                                    variant="outline"
                                    class="min-h-11 sm:min-h-8"
                                    @click="openEvidence(r)"
                                    >Add file<span class="sr-only">
                                        to {{ r.type }}, {{ r.dates }}</span
                                    ></Button
                                >
                            </p>
                            <Button
                                v-if="r.canCancel"
                                variant="outline"
                                class="min-h-11 w-full sm:w-fit"
                                @click="openAction('cancel', r)"
                                >Cancel request</Button
                            >
                        </li>
                    </ul>
                </template>
            </template>
        </section>

        <!-- Approvals -->
        <section
            v-if="leave.tab === 'approvals' && leave.approvals"
            aria-labelledby="approvals-title"
            class="flex flex-col gap-4"
        >
            <h2 id="approvals-title" class="text-base font-semibold">
                Waiting for you
            </h2>
            <EmptyState
                v-if="leave.approvals.pending.length === 0"
                title="Nothing waiting for you."
                description="Requests you can decide appear here. Your own requests go to someone else."
            />
            <ul v-else class="flex flex-col gap-3" data-test="pending-list">
                <li
                    v-for="r in leave.approvals.pending"
                    :key="r.publicId"
                    class="grid gap-3 rounded-xl border bg-card p-4 lg:grid-cols-[14rem_1fr_auto] lg:items-start"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ r.person }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ r.role }}
                        </p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1 text-sm">
                        <p>
                            <span class="font-medium">{{ r.type }}</span> ·
                            {{ r.dates }} ·
                            <span class="tabular-nums"
                                >{{ r.days }}
                                {{ r.days === '1' ? 'day' : 'days' }}</span
                            >
                        </p>
                        <p
                            v-if="r.leftAfter !== null"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            Left after this: {{ r.leftAfter }}
                        </p>
                        <p
                            v-if="r.reason"
                            class="text-xs break-words text-muted-foreground"
                        >
                            Reason: {{ r.reason }}
                        </p>
                        <p
                            v-if="r.evidenceReference"
                            class="text-xs break-words text-muted-foreground"
                        >
                            Evidence reference: {{ r.evidenceReference }}
                        </p>
                        <p
                            v-if="r.evidence"
                            class="flex flex-wrap items-center gap-2 text-sm"
                            data-test="evidence-line"
                        >
                            <span class="text-muted-foreground"
                                >Evidence:
                                {{ fileCountText(r.evidence.files) }}</span
                            >
                            <Button
                                v-if="r.evidence.files.length > 0"
                                size="sm"
                                variant="outline"
                                class="min-h-11 sm:min-h-8"
                                @click="openEvidence(r)"
                                >View<span class="sr-only">
                                    files for {{ r.type }}, {{ r.dates }}</span
                                ></Button
                            >
                            <Button
                                v-if="r.evidence.canAdd"
                                size="sm"
                                variant="outline"
                                class="min-h-11 sm:min-h-8"
                                @click="openEvidence(r)"
                                >Add file<span class="sr-only">
                                    to {{ r.type }}, {{ r.dates }}</span
                                ></Button
                            >
                        </p>
                        <ul
                            v-if="r.warnings.length > 0"
                            class="mt-1 flex flex-col gap-1"
                        >
                            <li
                                v-for="w in r.warnings"
                                :key="w"
                                class="border-l-4 border-l-warning pl-2 text-xs"
                            >
                                {{ w }}
                            </li>
                        </ul>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="openAction('approve', r)"
                            >Approve</Button
                        >
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="openAction('reject', r)"
                            >Reject</Button
                        >
                    </div>
                </li>
            </ul>

            <h2 class="text-base font-semibold">Approved leave</h2>
            <p
                v-if="leave.approvals.approved.length === 0"
                class="text-sm text-muted-foreground"
            >
                No approved leave in the last 30 days or ahead.
            </p>
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="r in leave.approvals.approved"
                    :key="r.publicId"
                    class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                >
                    <span class="min-w-0"
                        ><span class="font-medium">{{ r.person }}</span> ·
                        {{ r.type }} · {{ r.dates }} · {{ r.days }}
                        {{ r.days === '1' ? 'day' : 'days' }}</span
                    >
                    <span class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="r.evidence"
                            variant="outline"
                            size="sm"
                            class="min-h-11 sm:min-h-8"
                            @click="openEvidence(r)"
                            >Evidence ({{ r.evidence.files.length }})<span
                                class="sr-only"
                            >
                                for {{ r.person }}, {{ r.type }},
                                {{ r.dates }}</span
                            ></Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-h-11 sm:min-h-8"
                            @click="openAction('manager-cancel', r)"
                            >Cancel approved leave</Button
                        >
                    </span>
                </li>
            </ul>
        </section>

        <!-- Team calendar -->
        <section
            v-if="leave.tab === 'calendar' && calendar"
            aria-labelledby="calendar-title"
            class="flex flex-col gap-3"
        >
            <h2 id="calendar-title" class="sr-only">Team calendar</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="calendar-branch">Branch</Label>
                    <OperationalSelect
                        id="calendar-branch"
                        :model-value="leave.branchId ?? ''"
                        :options="branchOptions"
                        label="Branch"
                        @update:model-value="
                            (v: string | number) =>
                                visit({ branch: v, month: calendar?.month })
                        "
                    />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="min-h-11 sm:min-h-8"
                        @click="visit({ month: calendar.previous })"
                        >Previous month</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        class="min-h-11 sm:min-h-8"
                        @click="visit({ month: calendar.next })"
                        >Next month</Button
                    >
                    <span class="text-sm font-medium" data-test="month-label">{{
                        calendar.monthLabel
                    }}</span>
                </div>
            </div>
            <p
                v-if="!calendar.workingDaysSet"
                class="text-sm text-muted-foreground"
            >
                Working days are not set up for this branch yet.
            </p>
            <div class="hidden lg:block">
                <OperationalTable
                    :label="`Leave for ${branchName}, ${calendar.monthLabel}`"
                    :columns="calendar.days.length + 1"
                    :empty="calendar.rows.length === 0"
                    empty-message="Nobody is on approved leave this month."
                    min-width="1200px"
                >
                    <template #head>
                        <tr>
                            <th
                                scope="col"
                                class="sticky left-0 z-10 w-40 bg-card"
                            >
                                Staff
                            </th>
                            <th
                                v-for="day in calendar.days"
                                :key="day.date"
                                scope="col"
                                class="px-1 text-center text-xs"
                                :class="[
                                    day.holiday ? 'bg-muted' : '',
                                    !day.working ? 'text-muted-foreground' : '',
                                ]"
                                :title="day.holiday ?? undefined"
                            >
                                <span class="block">{{ day.weekday[0] }}</span>
                                <span class="block tabular-nums">{{
                                    day.day
                                }}</span>
                                <span v-if="day.holiday" class="sr-only">{{
                                    day.holiday
                                }}</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in calendar.rows" :key="row.name">
                            <th
                                scope="row"
                                class="sticky left-0 z-10 bg-card font-medium"
                            >
                                {{ row.name }}
                            </th>
                            <td
                                v-for="day in calendar.days"
                                :key="day.date"
                                class="px-1 text-center text-xs"
                                :class="day.holiday ? 'bg-muted' : ''"
                            >
                                <span
                                    v-if="row.cells[day.date]"
                                    class="inline-block rounded border px-1"
                                    :title="
                                        cellName(
                                            row.name,
                                            day.label,
                                            row.cells[day.date],
                                        )
                                    "
                                    ><span aria-hidden="true">{{
                                        row.cells[day.date] === 'half'
                                            ? '½'
                                            : '•'
                                    }}</span
                                    ><span class="sr-only">{{
                                        cellName(
                                            row.name,
                                            day.label,
                                            row.cells[day.date],
                                        )
                                    }}</span></span
                                >
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
                <p class="mt-2 text-xs text-muted-foreground">
                    • On leave · ½ Half day · shaded columns are public
                    holidays.
                </p>
            </div>
            <ul class="flex flex-col gap-2 lg:hidden" data-test="calendar-days">
                <li
                    v-if="calendarDays.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nobody is on approved leave this month.
                </li>
                <li
                    v-for="day in calendarDays"
                    :key="day.date"
                    class="rounded-xl border bg-card p-3 text-sm"
                >
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ day.label }}</span>
                        <Badge v-if="day.holiday" variant="outline"
                            >Public holiday</Badge
                        >
                    </p>
                    <p v-if="day.holiday" class="text-xs text-muted-foreground">
                        {{ day.holiday }}
                    </p>
                    <p v-if="day.people.length > 0" class="mt-1">
                        {{ day.people.join(', ') }}
                    </p>
                </li>
            </ul>
        </section>

        <!-- Settings -->
        <section
            v-if="leave.tab === 'settings' && leave.settings"
            aria-labelledby="settings-title"
            class="flex flex-col gap-4"
        >
            <h2 id="settings-title" class="sr-only">Leave settings</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid w-full gap-2 sm:w-40">
                    <Label for="settings-year">Year</Label>
                    <OperationalSelect
                        id="settings-year"
                        :model-value="leave.year"
                        :options="yearOptions"
                        label="Year"
                        @update:model-value="
                            (v: string | number) => visit({ year: v })
                        "
                    />
                </div>
                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="settings-branch">Branch</Label>
                    <OperationalSelect
                        id="settings-branch"
                        :model-value="leave.branchId ?? ''"
                        :options="branchOptions"
                        label="Branch"
                        @update:model-value="
                            (v: string | number) => visit({ branch: v })
                        "
                    />
                </div>
            </div>

            <!-- 1. Leave types -->
            <div class="rounded-xl border bg-card p-4" data-test="types-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold">Leave types</h3>
                        <p class="text-xs text-muted-foreground">
                            KPOne ships none. Add the types the clinic uses.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        class="min-h-11 sm:min-h-9"
                        @click="openType()"
                        >Add leave type</Button
                    >
                </div>
                <p
                    v-if="leave.settings.types.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    No leave types yet.
                </p>
                <ul v-else class="mt-3 flex flex-col divide-y">
                    <li
                        v-for="t in leave.settings.types"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ t.name }}
                                <span class="text-xs text-muted-foreground"
                                    >· {{ t.code }}</span
                                >
                                <Badge
                                    v-if="!t.isActive"
                                    variant="outline"
                                    class="ml-1"
                                    >Archived</Badge
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Paid {{ yesNo(t.isPaid) }} · Balance
                                {{ yesNo(t.usesBalance) }} · Half days
                                {{ yesNo(t.allowHalfDays) }} · Evidence
                                {{ yesNo(t.evidenceNeeded) }}
                                <template v-if="t.carryMaxDays">
                                    · Carry up to {{ t.carryMaxDays }}</template
                                >
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                class="min-h-11 sm:min-h-8"
                                @click="openType(t)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="min-h-11 sm:min-h-8"
                                @click="archiveType(t)"
                                >{{
                                    t.isActive ? 'Archive' : 'Restore'
                                }}</Button
                            >
                        </div>
                    </li>
                </ul>
            </div>

            <!-- 2. Entitlements -->
            <div
                class="rounded-xl border bg-card p-4"
                data-test="entitlements-card"
            >
                <h3 class="text-base font-semibold">
                    Entitlements for {{ leave.year }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    Days per year by role, in steps of 0.5. A person row wins
                    over the role. Nothing reaches a balance until you give the
                    entitlement.
                </p>
                <ErrorState
                    v-if="errors.entitlements"
                    :message="errors.entitlements"
                    class="mt-2"
                />
                <p
                    v-if="balanceTypes.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    Add a leave type that counts against a balance first.
                </p>
                <template v-else>
                    <div class="mt-3 hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[560px] text-sm">
                            <caption class="sr-only">
                                Days per year by role and leave type
                            </caption>
                            <thead>
                                <tr
                                    class="text-left text-xs text-muted-foreground"
                                >
                                    <th scope="col" class="py-1 pr-2">Role</th>
                                    <th
                                        v-for="t in balanceTypes"
                                        :key="t.id"
                                        scope="col"
                                        class="py-1 pr-2"
                                    >
                                        {{ t.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="role in leave.settings.roles"
                                    :key="role.value"
                                >
                                    <th
                                        scope="row"
                                        class="py-1 pr-2 text-left font-normal"
                                    >
                                        {{ role.label }}
                                    </th>
                                    <td
                                        v-for="t in balanceTypes"
                                        :key="t.id"
                                        class="py-1 pr-2"
                                    >
                                        <Input
                                            v-model="
                                                entitlementValues[
                                                    `${t.id}:${role.value}`
                                                ]
                                            "
                                            inputmode="decimal"
                                            class="h-11 w-20 tabular-nums sm:h-9"
                                            :aria-label="`${t.name} days for ${role.label}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Below 768 px: one card per role, one labelled field per leave type (Daniel). -->
                    <ul
                        class="mt-3 flex flex-col gap-3 md:hidden"
                        data-test="entitlement-cards"
                    >
                        <li
                            v-for="role in leave.settings.roles"
                            :key="role.value"
                            class="rounded-lg border p-3"
                        >
                            <h4 class="text-sm font-medium">
                                {{ role.label }}
                            </h4>
                            <div class="mt-2 grid grid-cols-2 gap-3">
                                <div
                                    v-for="t in balanceTypes"
                                    :key="t.id"
                                    class="grid gap-1"
                                >
                                    <Label
                                        :for="`ent-${t.id}-${role.value}`"
                                        class="text-xs"
                                        ><span class="sr-only"
                                            >{{ role.label }}, </span
                                        >{{ t.name }} days</Label
                                    >
                                    <Input
                                        :id="`ent-${t.id}-${role.value}`"
                                        v-model="
                                            entitlementValues[
                                                `${t.id}:${role.value}`
                                            ]
                                        "
                                        inputmode="decimal"
                                        class="h-11 tabular-nums"
                                    />
                                </div>
                            </div>
                        </li>
                    </ul>
                    <InputError
                        v-for="(message, key) in errors"
                        v-show="String(key).startsWith('rows.')"
                        :key="key"
                        :message="message"
                    />
                    <div
                        class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap"
                    >
                        <Button
                            class="min-h-11 sm:min-h-9"
                            :disabled="entitlementSaving"
                            @click="saveEntitlements"
                            >Save entitlements</Button
                        >
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            data-test="grant"
                            @click="openYear('grant')"
                            >Give this year's entitlement</Button
                        >
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="openYear('carry-forward')"
                            >Carry forward from {{ leave.year }}</Button
                        >
                        <Button
                            variant="ghost"
                            class="min-h-11 sm:min-h-9"
                            @click="openYear('expire')"
                            >Expire carried days</Button
                        >
                    </div>
                    <InputError :message="errors.year" />

                    <h4 class="mt-4 text-sm font-semibold">
                        Set for one person
                    </h4>
                    <ul
                        v-if="personEntitlements.length > 0"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        <li
                            v-for="e in personEntitlements"
                            :key="`${e.leaveTypeId}:${e.userId}`"
                        >
                            {{ e.person }} · {{ typeName(e.leaveTypeId) }} ·
                            {{ e.days }}
                        </li>
                    </ul>
                    <form
                        class="mt-2 grid gap-3 sm:grid-cols-[1fr_1fr_6rem_auto] sm:items-end"
                        @submit.prevent="savePerson"
                    >
                        <div class="grid gap-2">
                            <Label for="person-entitlement-user">Person</Label>
                            <OperationalSelect
                                id="person-entitlement-user"
                                v-model="personForm.user_id"
                                :options="peopleOptions"
                                label="Person"
                                placeholder="Choose a person"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="person-entitlement-type"
                                >Leave type</Label
                            >
                            <OperationalSelect
                                id="person-entitlement-type"
                                v-model="personForm.leave_type_id"
                                :options="balanceTypeOptions"
                                label="Leave type"
                                placeholder="Choose a type"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="person-entitlement-days">Days</Label>
                            <Input
                                id="person-entitlement-days"
                                v-model="personForm.days"
                                inputmode="decimal"
                                class="h-11 sm:h-9"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            :disabled="personForm.processing"
                            >Save for person</Button
                        >
                    </form>
                </template>
            </div>

            <!-- 3. Working days -->
            <div
                class="rounded-xl border bg-card p-4"
                data-test="working-days-card"
            >
                <h3 class="text-base font-semibold">
                    Working days ·
                    {{
                        leave.branches.find((b) => b.id === leave.branchId)
                            ?.name
                    }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    Leave counts only these weekdays. Requests already sent keep
                    their day count.
                </p>
                <fieldset class="mt-3">
                    <legend class="sr-only">Working weekdays</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <div
                            v-for="d in weekdays"
                            :key="d.value"
                            class="flex min-h-11 items-center gap-2 sm:min-h-9"
                        >
                            <Checkbox
                                :id="`weekday-${d.value}`"
                                :model-value="workingDays.includes(d.value)"
                                @update:model-value="
                                    (v) => toggleDay(d.value, v === true)
                                "
                            />
                            <Label :for="`weekday-${d.value}`">{{
                                d.label
                            }}</Label>
                        </div>
                    </div>
                </fieldset>
                <InputError :message="errors.weekdays" />
                <Button
                    class="mt-3 min-h-11 sm:min-h-9"
                    @click="saveWorkingDays"
                    >Save working days</Button
                >
            </div>

            <!-- 4. Public holidays -->
            <div
                class="rounded-xl border bg-card p-4"
                data-test="holidays-card"
            >
                <h3 class="text-base font-semibold">
                    Public holidays {{ leave.year }} ·
                    {{
                        leave.branches.find((b) => b.id === leave.branchId)
                            ?.name
                    }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    KPOne ships no holiday list. Add each one for this branch.
                </p>
                <InputError :message="errors.holiday" />
                <p
                    v-if="leave.settings.holidays.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    No public holidays for {{ leave.year }} yet.
                </p>
                <ul v-else class="mt-3 flex flex-col divide-y">
                    <li
                        v-for="h in leave.settings.holidays"
                        :key="h.id"
                        class="flex items-center justify-between gap-2 py-2 text-sm"
                    >
                        <span
                            ><span class="tabular-nums">{{ h.label }}</span> ·
                            {{ h.name }}</span
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            class="min-h-11 sm:min-h-8"
                            :aria-label="`Remove ${h.name}, ${h.label}`"
                            @click="removeHoliday(h.id)"
                            >Remove</Button
                        >
                    </li>
                </ul>
                <form
                    class="mt-3 grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end"
                    @submit.prevent="addHoliday"
                >
                    <div class="grid gap-2">
                        <Label for="holiday-date">Date</Label>
                        <DateField
                            id="holiday-date"
                            v-model="holidayForm.holiday_date"
                        />
                        <InputError
                            :message="holidayForm.errors.holiday_date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="holiday-name">Name</Label>
                        <Input
                            id="holiday-name"
                            v-model="holidayForm.name"
                            maxlength="80"
                            class="h-11 sm:h-9"
                        />
                        <InputError :message="holidayForm.errors.name" />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="min-h-11 sm:min-h-9"
                        :disabled="holidayForm.processing"
                        >Add holiday</Button
                    >
                </form>
            </div>

            <!-- 5. Opening balances and adjustments -->
            <div class="rounded-xl border bg-card p-4" data-test="entries-card">
                <h3 class="text-base font-semibold">
                    Opening balances and adjustments
                </h3>
                <p class="text-xs text-muted-foreground">
                    Another manager checks each one before it counts.
                </p>
                <form
                    class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    @submit.prevent="saveEntry"
                >
                    <div class="grid gap-2">
                        <Label for="entry-kind">Entry</Label>
                        <OperationalSelect
                            id="entry-kind"
                            v-model="entryForm.kind"
                            :options="[
                                { value: 'opening', label: 'Opening balance' },
                                { value: 'adjustment', label: 'Adjustment' },
                            ]"
                            label="Entry"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="entry-person">Person *</Label>
                        <OperationalSelect
                            id="entry-person"
                            v-model="entryForm.user_id"
                            :options="peopleOptions"
                            label="Person"
                            placeholder="Choose a person"
                            :invalid="!!entryForm.errors.user_id"
                        />
                        <InputError :message="entryForm.errors.user_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="entry-type">Leave type *</Label>
                        <OperationalSelect
                            id="entry-type"
                            v-model="entryForm.leave_type_id"
                            :options="balanceTypeOptions"
                            label="Leave type"
                            placeholder="Choose a type"
                            :invalid="!!entryForm.errors.leave_type_id"
                        />
                        <InputError :message="entryForm.errors.leave_type_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="entry-days">Days *</Label>
                        <Input
                            id="entry-days"
                            v-model="entryForm.days"
                            inputmode="decimal"
                            class="h-11 sm:h-9"
                            aria-describedby="entry-days-help"
                        />
                        <p
                            id="entry-days-help"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                entryForm.kind === 'opening'
                                    ? 'Days left on the day the clinic starts using KPOne leave.'
                                    : 'Use a minus sign to remove days, for example -1.'
                            }}
                        </p>
                        <InputError :message="entryForm.errors.days" />
                    </div>
                    <template v-if="entryForm.kind === 'adjustment'">
                        <div class="grid gap-2">
                            <Label for="entry-reason">Reason *</Label>
                            <OperationalSelect
                                id="entry-reason"
                                v-model="entryForm.reason_code"
                                :options="leave.settings.adjustmentReasons"
                                label="Reason"
                                placeholder="Choose a reason"
                                :invalid="!!entryForm.errors.reason_code"
                            />
                            <InputError
                                :message="entryForm.errors.reason_code"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="entry-note">Note</Label>
                            <Textarea
                                id="entry-note"
                                v-model="entryForm.note"
                                maxlength="200"
                                rows="2"
                            />
                            <InputError :message="entryForm.errors.note" />
                        </div>
                    </template>
                    <div class="flex items-end">
                        <Button
                            type="submit"
                            class="min-h-11 w-full sm:min-h-9 sm:w-fit"
                            :disabled="entryForm.processing"
                            >Send for a check</Button
                        >
                    </div>
                </form>
                <p
                    v-if="leave.settings.entries.length === 0"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    No opening balances or adjustments for {{ leave.year }}.
                </p>
                <ul
                    v-else
                    class="mt-3 flex flex-col divide-y"
                    data-test="entries"
                >
                    <li
                        v-for="e in leave.settings.entries"
                        :key="e.publicId"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p>
                                <span class="font-medium">{{ e.person }}</span>
                                · {{ e.type }} {{ e.year }} ·
                                <span class="tabular-nums">{{ e.days }}</span>
                                ·
                                {{
                                    e.kind === 'opening'
                                        ? 'Opening balance'
                                        : e.reason
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Entered by {{ e.enteredBy
                                }}<template v-if="e.note">
                                    · {{ e.note }}</template
                                >
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                :status="`leave_check_${e.status}`"
                                :label="entryLabel[e.status]"
                                :tone="entryTone[e.status]"
                            />
                            <template v-if="e.canDecide">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="min-h-11 sm:min-h-8"
                                    @click="decideEntry(e, 'confirm')"
                                    >Confirm</Button
                                >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="min-h-11 sm:min-h-8"
                                    @click="decideEntry(e, 'reject')"
                                    >Reject</Button
                                >
                            </template>
                            <span
                                v-else-if="e.status === 'pending'"
                                class="text-xs text-muted-foreground"
                                >Another manager checks this.</span
                            >
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Apply sheet -->
        <Sheet v-model:open="applyOpen">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>Apply for leave</SheetTitle>
                    <SheetDescription
                        >Someone else approves your request.</SheetDescription
                    >
                </SheetHeader>
                <form class="grid gap-4 px-4 pb-4" @submit.prevent="sendApply">
                    <div class="grid gap-2">
                        <Label for="apply-type">Leave type *</Label>
                        <OperationalSelect
                            id="apply-type"
                            v-model="applyForm.leave_type_id"
                            :options="
                                (leave.my?.types ?? []).map((t) => ({
                                    value: t.id,
                                    label: t.name,
                                }))
                            "
                            label="Leave type"
                            placeholder="Choose a leave type"
                            :invalid="!!applyForm.errors.leave_type_id"
                        />
                        <InputError :message="applyForm.errors.leave_type_id" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 sm:items-start">
                        <div class="grid gap-2">
                            <Label for="apply-start">Start date *</Label>
                            <DateField
                                id="apply-start"
                                v-model="applyForm.start_date"
                                :aria-invalid="
                                    applyForm.errors.start_date
                                        ? 'true'
                                        : undefined
                                "
                                @change="
                                    applyForm.end_date ||
                                    (applyForm.end_date = applyForm.start_date)
                                "
                            />
                            <InputError
                                :message="applyForm.errors.start_date"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="apply-end">End date *</Label>
                            <DateField
                                id="apply-end"
                                v-model="applyForm.end_date"
                                :min="applyForm.start_date || undefined"
                                :aria-invalid="
                                    applyForm.errors.end_date
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <InputError :message="applyForm.errors.end_date" />
                        </div>
                    </div>
                    <template v-if="chosenType?.allowHalfDays">
                        <div class="grid gap-2">
                            <Label for="apply-first">{{
                                singleDay
                                    ? 'Half day'
                                    : 'Half day on the first day'
                            }}</Label>
                            <OperationalSelect
                                id="apply-first"
                                v-model="applyForm.start_part"
                                :options="firstDayOptions"
                                label="Half day on the first day"
                            />
                            <InputError
                                :message="applyForm.errors.start_part"
                            />
                        </div>
                        <div v-if="!singleDay" class="grid gap-2">
                            <Label for="apply-last"
                                >Half day on the last day</Label
                            >
                            <OperationalSelect
                                id="apply-last"
                                v-model="applyForm.end_part"
                                :options="lastDayOptions"
                                label="Half day on the last day"
                            />
                            <InputError :message="applyForm.errors.end_part" />
                        </div>
                    </template>
                    <p
                        v-if="preview.text"
                        class="text-sm font-medium"
                        role="status"
                        aria-live="polite"
                        data-test="apply-summary"
                    >
                        {{ preview.text }}
                    </p>
                    <p
                        v-else-if="
                            preview.error &&
                            preview.error !== applyForm.errors.end_date
                        "
                        class="text-sm text-destructive"
                        role="status"
                        aria-live="polite"
                    >
                        {{ preview.error }}
                    </p>
                    <div class="grid gap-2">
                        <Label for="apply-reason">Reason</Label>
                        <Textarea
                            id="apply-reason"
                            v-model="applyForm.reason"
                            maxlength="300"
                            rows="3"
                            aria-describedby="apply-reason-help"
                        />
                        <p
                            id="apply-reason-help"
                            class="text-xs text-muted-foreground"
                        >
                            Do not write medical details here.
                        </p>
                        <InputError :message="applyForm.errors.reason" />
                    </div>
                    <div v-if="chosenType?.evidenceNeeded" class="grid gap-2">
                        <Label for="apply-evidence">Evidence reference</Label>
                        <Input
                            id="apply-evidence"
                            v-model="applyForm.evidence_reference"
                            maxlength="100"
                            class="h-11 sm:h-9"
                            aria-describedby="apply-evidence-help"
                        />
                        <p
                            id="apply-evidence-help"
                            class="text-xs text-muted-foreground"
                        >
                            For example, the MC serial number. HR checks the
                            paper copy.
                        </p>
                        <InputError
                            :message="applyForm.errors.evidence_reference"
                        />
                    </div>
                    <div
                        v-if="chosenType?.acceptsFiles"
                        class="grid gap-2"
                        data-test="apply-files"
                    >
                        <span class="text-sm font-medium" id="apply-files-label"
                            >Photo or PDF (optional)</span
                        >
                        <input
                            ref="applyFileInput"
                            type="file"
                            class="sr-only"
                            accept="image/jpeg,image/png,application/pdf"
                            multiple
                            tabindex="-1"
                            aria-hidden="true"
                            @change="chooseApplyFiles"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 w-full sm:min-h-9 sm:w-fit"
                            aria-describedby="apply-files-help"
                            :disabled="applyForm.evidence_files.length >= 3"
                            @click="applyFileInput?.click()"
                            >Add a photo or PDF</Button
                        >
                        <ul
                            v-if="applyForm.evidence_files.length > 0"
                            class="flex flex-col gap-1 text-sm"
                        >
                            <li
                                v-for="(f, i) in applyForm.evidence_files"
                                :key="`${f.name}-${i}`"
                                class="flex min-w-0 items-center justify-between gap-2"
                            >
                                <span class="min-w-0 truncate"
                                    >{{ f.name }} ·
                                    {{ sizeLabel(f.size) }}</span
                                >
                                <Button
                                    type="button"
                                    variant="link"
                                    class="min-h-11 min-w-11 shrink-0 px-3 sm:min-h-8"
                                    :aria-label="`Remove ${f.name}`"
                                    @click="dropApplyFile(i)"
                                    >Remove</Button
                                >
                            </li>
                        </ul>
                        <p
                            id="apply-files-help"
                            class="text-xs text-muted-foreground"
                        >
                            JPG, PNG or PDF, up to 5 MB. Take a clear photo of
                            the whole MC.<template
                                v-if="chosenType.retentionDays"
                            >
                                Files are kept for
                                {{ chosenType.retentionDays }} days after your
                                leave.</template
                            >
                        </p>
                        <InputError :message="applyFileError" />
                    </div>
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="applyOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 sm:min-h-9"
                            :disabled="applyForm.processing"
                            >{{
                                applyForm.processing
                                    ? applyForm.evidence_files.length > 0
                                        ? 'Uploading…'
                                        : 'Sending…'
                                    : 'Send request'
                            }}</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Evidence panel (HR-2b) -->
        <Sheet v-model:open="evidence.open">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>Evidence</SheetTitle>
                    <SheetDescription v-if="evidenceRow"
                        >{{
                            evidenceRow.person
                                ? `${evidenceRow.person} · `
                                : ''
                        }}{{ evidenceRow.type }} ·
                        {{ evidenceRow.dates }}</SheetDescription
                    >
                </SheetHeader>
                <div
                    v-if="evidenceRow?.evidence"
                    class="grid gap-4 px-4 pb-4"
                    data-test="evidence-panel"
                >
                    <EmptyState
                        v-if="evidenceRow.evidence.files.length === 0"
                        title="No files yet."
                        description="Add a photo or PDF of the MC."
                    />
                    <ul v-else class="flex flex-col gap-3">
                        <li
                            v-for="f in evidenceRow.evidence.files"
                            :key="f.publicId"
                            class="grid gap-2 rounded-xl border bg-card p-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="text-sm font-medium">
                                    {{ f.label }} · {{ f.size }}
                                </p>
                                <Button
                                    v-if="f.canRemove"
                                    variant="ghost"
                                    size="sm"
                                    class="min-h-11 min-w-11 px-3 sm:min-h-8"
                                    :aria-label="`Remove ${f.label}`"
                                    @click="openRemoval(f)"
                                    >Remove</Button
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Added by {{ f.addedBy }} on {{ f.addedOn
                                }}<template v-if="f.onBehalf">
                                    (paper copy scanned by HR)</template
                                >
                            </p>
                            <div
                                v-if="f.kind !== 'pdf'"
                                class="overflow-hidden rounded-lg border bg-muted p-2"
                            >
                                <img
                                    :src="f.url"
                                    :alt="
                                        photoAlt(f, evidenceRow.evidence.files)
                                    "
                                    class="mx-auto block h-auto max-w-full"
                                    loading="lazy"
                                />
                            </div>
                            <Button
                                v-else
                                as="a"
                                :href="f.url"
                                variant="outline"
                                class="min-h-11 w-full sm:min-h-9 sm:w-fit"
                                >Download PDF<span class="sr-only">
                                    {{ f.label }}</span
                                ></Button
                            >
                        </li>
                    </ul>
                    <div v-if="evidenceRow.evidence.canAdd" class="grid gap-2">
                        <input
                            ref="evidenceFileInput"
                            type="file"
                            class="sr-only"
                            accept="image/jpeg,image/png,application/pdf"
                            tabindex="-1"
                            aria-hidden="true"
                            @change="uploadEvidence"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 w-full sm:min-h-9 sm:w-fit"
                            aria-describedby="evidence-help"
                            :disabled="evidence.uploading"
                            @click="evidenceFileInput?.click()"
                            >{{
                                evidence.uploading
                                    ? 'Uploading…'
                                    : 'Add a photo or PDF'
                            }}</Button
                        >
                        <p
                            id="evidence-help"
                            class="text-xs text-muted-foreground"
                        >
                            JPG, PNG or PDF, up to 5 MB.<template
                                v-if="evidenceRow.evidence.retentionDays"
                            >
                                Files are kept for
                                {{ evidenceRow.evidence.retentionDays }} days
                                after the leave.</template
                            >
                        </p>
                        <InputError :message="evidence.error" />
                    </div>
                </div>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            v-model:open="removal.open"
            title="Remove this file?"
            description="The file is deleted. A record that it was added and removed stays."
            confirm-label="Remove file"
            processing-label="Removing…"
            :processing="removal.processing"
            :error="removal.error"
            destructive
            @confirm="confirmRemoval"
        >
            <template #icon>
                <Trash2 class="size-5" />
            </template>
            <div v-if="removalNeedsReason" class="grid gap-2">
                <Label for="removal-reason">Reason *</Label>
                <OperationalSelect
                    id="removal-reason"
                    v-model="removal.reason"
                    :options="removalReasons"
                    label="Reason"
                    placeholder="Choose a reason"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Leave type sheet -->
        <Sheet v-model:open="typeOpen">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-md"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        typeForm.leave_type_id
                            ? 'Edit leave type'
                            : 'Add leave type'
                    }}</SheetTitle>
                    <SheetDescription
                        >A type in use keeps its balance and half-day
                        settings.</SheetDescription
                    >
                </SheetHeader>
                <form class="grid gap-4 px-4 pb-4" @submit.prevent="saveType">
                    <div class="grid gap-2">
                        <Label for="type-code">Code *</Label>
                        <Input
                            id="type-code"
                            v-model="typeForm.code"
                            maxlength="20"
                            class="h-11 uppercase sm:h-9"
                            :disabled="!!typeForm.leave_type_id"
                            aria-describedby="type-code-help"
                        />
                        <p
                            id="type-code-help"
                            class="text-xs text-muted-foreground"
                        >
                            For example AL. It cannot change later.
                        </p>
                        <InputError :message="typeForm.errors.code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="type-name">Name *</Label>
                        <Input
                            id="type-name"
                            v-model="typeForm.name"
                            maxlength="60"
                            class="h-11 sm:h-9"
                        />
                        <InputError :message="typeForm.errors.name" />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">Rules</legend>
                        <div
                            v-for="f in flags"
                            :key="f.key"
                            class="flex min-h-11 items-center gap-2 sm:min-h-9"
                        >
                            <Checkbox
                                :id="`type-${f.key}`"
                                :model-value="flagValue(f.key)"
                                :disabled="
                                    f.key === 'allow_negative' &&
                                    !typeForm.uses_balance
                                "
                                @update:model-value="
                                    (v) => setFlag(f.key, v === true)
                                "
                            />
                            <Label :for="`type-${f.key}`">{{ f.label }}</Label>
                        </div>
                        <InputError :message="typeForm.errors.uses_balance" />
                    </fieldset>
                    <fieldset class="grid gap-2" data-test="type-evidence">
                        <legend class="mb-1 text-sm font-medium">
                            Evidence files
                        </legend>
                        <div
                            class="flex min-h-11 items-center gap-2 sm:min-h-9"
                        >
                            <Checkbox
                                id="type-accepts-files"
                                :model-value="typeForm.accepts_evidence_files"
                                @update:model-value="
                                    (v) =>
                                        (typeForm.accepts_evidence_files =
                                            v === true)
                                "
                            />
                            <Label for="type-accepts-files"
                                >Accept evidence files</Label
                            >
                        </div>
                        <div
                            v-if="typeForm.accepts_evidence_files"
                            class="grid gap-2"
                        >
                            <Label for="type-retention"
                                >Keep files for (days after the leave ends)
                                *</Label
                            >
                            <Input
                                id="type-retention"
                                v-model="typeForm.evidence_retention_days"
                                inputmode="numeric"
                                class="h-11 sm:h-9"
                                aria-describedby="type-retention-help"
                            />
                            <p
                                id="type-retention-help"
                                class="text-xs text-muted-foreground"
                            >
                                Files are deleted after this. Requests and their
                                history stay. At most 2,555 days (7 years). When
                                you lower the days, files already older than
                                that are deleted the next night.
                            </p>
                            <p
                                v-if="savedRetentionDays > MAX_EVIDENCE_DAYS"
                                class="text-xs text-muted-foreground"
                                data-test="type-retention-over-cap"
                            >
                                This type was saved with
                                {{ savedRetentionDays.toLocaleString('en-MY') }}
                                days. Leave records are deleted 7 years after
                                their leave year, and the files go with them, so
                                the 7 years win. Enter 2,555 or fewer to save a
                                change.
                            </p>
                            <InputError
                                :message="
                                    typeForm.errors.evidence_retention_days
                                "
                            />
                        </div>
                    </fieldset>
                    <template v-if="typeForm.uses_balance">
                        <div class="grid gap-2">
                            <Label for="type-carry"
                                >Carry forward up to (days)</Label
                            >
                            <Input
                                id="type-carry"
                                v-model="typeForm.carry_max_days"
                                inputmode="decimal"
                                class="h-11 sm:h-9"
                                aria-describedby="type-carry-help"
                            />
                            <p
                                id="type-carry-help"
                                class="text-xs text-muted-foreground"
                            >
                                Leave empty for no carry-forward.
                            </p>
                            <InputError
                                :message="typeForm.errors.carry_max_days"
                            />
                        </div>
                        <div v-if="typeForm.carry_max_days" class="grid gap-2">
                            <Label for="type-expiry"
                                >Carried days expire on (MM-DD)</Label
                            >
                            <Input
                                id="type-expiry"
                                v-model="typeForm.carry_expires_on"
                                placeholder="03-31"
                                maxlength="5"
                                class="h-11 sm:h-9"
                            />
                            <InputError
                                :message="typeForm.errors.carry_expires_on"
                            />
                        </div>
                    </template>
                    <div
                        class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            @click="typeOpen = false"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11 sm:min-h-9"
                            :disabled="typeForm.processing"
                            >Save leave type</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            v-model:open="action.open"
            :title="actionText.title"
            :description="actionText.description"
            :confirm-label="actionText.confirm"
            :processing-label="actionText.busy"
            :processing="action.processing"
            :destructive="action.kind !== 'approve'"
            :error="action.error"
            @confirm="confirmAction"
        >
            <template #icon>
                <CalendarCheck
                    v-if="action.kind === 'approve'"
                    class="size-5"
                />
                <CalendarX v-else class="size-5" />
            </template>
            <div
                v-if="action.kind === 'approve' && action.evidenceNeeded"
                class="flex min-h-11 items-center gap-2"
            >
                <Checkbox
                    id="evidence-seen"
                    :model-value="action.evidenceSeen"
                    @update:model-value="
                        (v) => (action.evidenceSeen = v === true)
                    "
                />
                <Label for="evidence-seen">Evidence seen</Label>
            </div>
            <div
                v-if="
                    action.kind === 'reject' || action.kind === 'manager-cancel'
                "
                class="grid gap-2"
            >
                <Label for="action-reason">Reason *</Label>
                <OperationalSelect
                    id="action-reason"
                    v-model="action.reason"
                    :options="
                        action.kind === 'reject'
                            ? leave.rejectReasons
                            : leave.cancelReasons
                    "
                    label="Reason"
                    placeholder="Choose a reason"
                />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            v-model:open="yearAction.open"
            :title="yearText.title"
            :description="yearText.description"
            :confirm-label="yearText.confirm"
            processing-label="Working…"
            :processing="yearAction.processing"
            :error="yearAction.error"
            @confirm="confirmYear"
        >
            <template #icon>
                <CalendarCheck class="size-5" />
            </template>
        </OperationalConfirmDialog>
    </div>
</template>
