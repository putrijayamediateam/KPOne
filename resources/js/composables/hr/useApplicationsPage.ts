import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import type FileChooseButton from '@/components/catalogue/FileChooseButton.vue';

/**
 * The Applications page's state and actions (HR-13 to HR-17e), shared by Classic (`pages/HR/Applications.vue`) and
 * Version 2 (`pages/HR/V2/Applications.vue`), so both send the same requests with the same lock versions and idempotency
 * keys. Moved out of the Classic page unchanged; the Classic template did not change. Contact details arrive masked and
 * are shown only through the audited Show; nothing is prefetched, cached or put in props.
 */
export type Tab = 'applications' | 'pool' | 'positions' | 'settings';
export type Option = { value: string; label: string };
export type Application = {
    id: string;
    position: string;
    status: string;
    statusLabel: string;
    next: Option[];
    name: string | null;
    /** Masked on the list; Show records each view. */
    phoneMask: string | null;
    emailMask: string | null;
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
    extendDays: number;
    extensionsUsed: number;
    extensionsMax: number;
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
export type PoolMember = {
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
export type InterviewDocumentGroup = {
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
export type NeedsYouRow = {
    id: string;
    status: string;
    name: string;
    position: string;
    why: string;
};
export type Position = {
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

export type ApplicationsPageProps = {
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
};

export function useApplicationsPage(props: ApplicationsPageProps) {
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
        router.get(
            '/hr/applications',
            { tab: value },
            { preserveScroll: true },
        );

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
    const extending = ref(false);
    const extendError = ref<string | undefined>(undefined);
    const extendDeadline = () => {
        if (!viewing.value) {
            return;
        }

        extending.value = true;
        extendError.value = undefined;
        router.post(
            `/hr/applications/${viewing.value.id}/extend-deadline`,
            {
                expected_lock_version: viewing.value.lockVersion,
                idempotency_key: crypto.randomUUID(),
                ...(list.value?.filters ?? {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => (viewing.value = null),
                onError: (errors) =>
                    (extendError.value =
                        errors.extend ?? errors.expected_lock_version),
                onFinish: () => (extending.value = false),
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
        if (
            ['passed', 'failed', 'not_taken_forward', 'withdrawn'].includes(to)
        ) {
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
        props.applications.options.statuses.find((s) => s.value === value)
            ?.label ?? value;

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
    const accountAction = reactive({
        key: crypto.randomUUID(),
        processing: false,
    });
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
    const documentPicker = ref<InstanceType<typeof FileChooseButton> | null>(
        null,
    );
    const documentGroup = computed(() =>
        props.applications.interviewDocuments.find(
            (g) => g.type === documentForm.type,
        ),
    );
    const documentFull = computed(
        () => (documentGroup.value?.documents.length ?? 0) >= DOCS_MAX,
    );
    const chooseDocument = (event: Event) => {
        documentForm.file =
            (event.target as HTMLInputElement).files?.[0] ?? null;
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

    return {
        page,
        errors,
        failure,
        tabs,
        selectTab,
        list,
        statusFilter,
        positionFilter,
        filter,
        pages,
        tone,
        needsYouCount,
        interviewAction,
        interviewPost,
        extending,
        extendError,
        extendDeadline,
        focusOn,
        showAll,
        viewing,
        moving,
        openApplication,
        screening,
        rejecting,
        recordPass,
        askReject,
        confirmReject,
        arrangedOutside,
        moveSteps,
        confirming,
        askMove,
        move,
        label,
        positionForm,
        branchOptions,
        startAdd,
        startEdit,
        savePosition,
        checklist,
        saveChecklist,
        checklistDirty,
        retentionDirty,
        retention,
        saveRetention,
        poolDays,
        poolDaysDirty,
        savePoolDays,
        invitePick,
        inviting,
        inviteProcessing,
        askInvite,
        sendInvite,
        invitationLink,
        linkCopied,
        copyInvitationLink,
        accountAction,
        accountPost,
        DOCS_MAX,
        documentForm,
        documentPicker,
        documentFull,
        chooseDocument,
        uploadDocument,
        removingDocument,
        removeDocumentProcessing,
        removeDocument,
    };
}
