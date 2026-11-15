import { router, useForm, usePage } from '@inertiajs/vue3';
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

/**
 * The Leave page's state and actions, shared by Classic (`pages/HR/Leave.vue`) and Version 2 (`pages/HR/V2/Leave.vue`),
 * so both send the same requests with the same lock versions and idempotency keys. Moved out of the Classic page
 * unchanged; the Classic template did not change. The `tabStrip` template ref is read here through `useTemplateRef`, so
 * a page that shows the tab strip names its wrapper `ref="tabStrip"`.
 */
export type Tab = 'my' | 'approvals' | 'calendar' | 'settings';
export type Option = { value: string; label: string };
export type Status = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type RequestRow = {
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
export type EvidenceFile = {
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
export type Evidence = {
    acceptsFiles: boolean;
    retentionDays: number | null;
    canAdd: boolean;
    files: EvidenceFile[];
} | null;
export type MyRequest = RequestRow & {
    reason: string | null;
    canCancel: boolean;
    evidence: Evidence;
};
export type PendingRow = RequestRow & {
    person: string;
    role: string;
    evidenceNeeded: boolean;
    evidenceReference: string | null;
    reason: string | null;
    leftAfter: string | null;
    warnings: string[];
    evidence: Evidence;
};
export type ApprovedRow = RequestRow & {
    person: string;
    role: string;
    evidence: Evidence;
};
export type LeaveTypeOption = {
    id: number;
    name: string;
    allowHalfDays: boolean;
    evidenceNeeded: boolean;
    usesBalance: boolean;
    acceptsFiles: boolean;
    retentionDays: number | null;
};
export type Balance = {
    typeId: number;
    name: string;
    counted: boolean;
    entitled: string;
    taken: string;
    booked: string;
    left: string;
};
export type TypeRow = {
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
    setByDefaults: boolean;
    lockVersion: number;
};
export type Entry = {
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
export type Settings = {
    types: TypeRow[];
    roles: Option[];
    entitlements: {
        leaveTypeId: number;
        role: string | null;
        userId: number | null;
        person: string | null;
        days: string;
        setByDefaults: boolean;
    }[];
    grantPreview: { people: number; lines: number };
    defaults: {
        year: number;
        typesToAdd: string[];
        typesKept: string[];
        rows: number;
        base: number;
        middle: number;
        long: number;
        noRecord: number;
        crossing: number;
        prorated: number;
        label: string;
    };
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
export type Calendar = {
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

export type LeavePageProps = {
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
};

export function useLeavePage(props: LeavePageProps) {
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
    const statusTone: Record<
        Status,
        'warning' | 'success' | 'danger' | 'neutral'
    > = {
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
    // `counting` (V2, Daniel): true from a change of the dates or type until the server answers, so the working-days
    // line says "Counting…" and never shows a number for the old dates. Classic does not read it.
    const preview = reactive({ text: '', error: '', counting: false });
    let previewTimer: ReturnType<typeof setTimeout> | undefined;
    // The day-count check shares the session with the form. If it ran while the form was being sent, its saved session
    // could overwrite the validation errors of the send, and the sheet would close with nothing shown. So a send first
    // cancels a waiting check and waits for one already running.
    let previewInFlight: Promise<void> | null = null;
    const runPreview = () => {
        if (applyForm.processing) {
            return Promise.resolve();
        }

        previewInFlight = fetchPreview().finally(() => {
            previewInFlight = null;
            preview.counting = false;
        });

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
            preview.counting = Boolean(
                applyForm.leave_type_id &&
                applyForm.start_date &&
                applyForm.end_date,
            );
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
        preview.counting = false;
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
        // A check still waiting was cancelled above: its old number is not for these dates (V2 "Counting…").
        if (preview.counting) {
            preview.counting = false;
            preview.text = '';
        }

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
    type YearAction = 'grant' | 'carry-forward' | 'expire' | 'defaults';
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
            // HR-DEFAULTS: the owner's draft defaults (Employment Act 1955 minimums); it only adds, never replaces.
            defaults: {
                title: `Use KPOne defaults for ${y}?`,
                description: defaultsDescription.value,
                confirm: 'Add these defaults',
            },
            expire: {
                title: `Expire carried days for ${y}?`,
                description:
                    'Carried days not used before each type’s expiry date are removed.',
                confirm: 'Expire carried days',
            },
        }[yearAction.kind];
    });
    const plural = (n: number, one: string, many: string) =>
        `${n} ${n === 1 ? one : many}`;
    const defaultsDescription = computed(() => {
        const d = props.leave.settings?.defaults;

        if (!d) {
            return '';
        }

        if (d.typesToAdd.length === 0 && d.rows === 0) {
            return 'The KPOne defaults are already in place. Nothing will change.';
        }

        return `This adds ${plural(d.typesToAdd.length, 'leave type', 'leave types')} and ${plural(d.rows, 'entitlement row', 'entitlement rows')}. Types and figures you already entered stay as they are. Nothing reaches a balance until you give the entitlement.`;
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
            props.leave.branches.find((b) => b.id === props.leave.branchId)
                ?.name ?? '',
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

    return {
        errors,
        tabLabels,
        tabs,
        statusTone,
        visit,
        branchOptions,
        yearOptions,
        tabStrip,
        moreTabsRight,
        selectLeaveTab,
        applyOpen,
        applyKey,
        applyForm,
        chosenType,
        singleDay,
        firstDayOptions,
        lastDayOptions,
        preview,
        openApply,
        applyFileInput,
        sizeLabel,
        chooseApplyFiles,
        dropApplyFile,
        applyFileError,
        sendApply,
        evidence,
        evidenceRow,
        evidenceFileInput,
        openEvidence,
        fileCountText,
        photoAlt,
        uploadEvidence,
        removal,
        removalNeedsReason,
        openRemoval,
        confirmRemoval,
        removalReasons,
        action,
        actionText,
        actionDisabled,
        openAction,
        confirmAction,
        typeOpen,
        typeForm,
        openType,
        MAX_EVIDENCE_DAYS,
        savedRetentionDays,
        saveType,
        archiveType,
        flags,
        setFlag,
        flagValue,
        yesNo,
        balanceTypes,
        entitlementValues,
        resetEntitlements,
        personEntitlements,
        entitlementSaving,
        saveEntitlements,
        personForm,
        savePerson,
        yearAction,
        yearText,
        plural,
        defaultsDescription,
        openYear,
        confirmYear,
        weekdays,
        workingRow,
        workingDays,
        toggleDay,
        saveWorkingDays,
        holidayForm,
        addHoliday,
        removeHoliday,
        entryKey,
        entryForm,
        saveEntry,
        decideEntry,
        entryTone,
        entryLabel,
        peopleOptions,
        balanceTypeOptions,
        typeName,
        calendar,
        branchName,
        cellName,
        calendarDays,
    };
}
