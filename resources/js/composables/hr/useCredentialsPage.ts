import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import type {
    Card,
    CredentialStatus,
    CredentialTypeHead,
    Option,
    RecordView,
    WorkPermitDue,
} from '@/components/hr/credentials/types';
import { dateLabel } from '@/components/hr/records/types';

/**
 * The Credentials page's state and actions (HR-5a), shared by Classic (`pages/HR/Credentials.vue`) and Version 2
 * (`pages/HR/V2/Credentials.vue`), so both send the same requests with the same lock versions and idempotency keys. Moved
 * out of the Classic page unchanged; the Classic template did not change. A credential reference stays masked: it is shown
 * only through the audited Show component, and nothing is prefetched, cached or put in props.
 */
export type Tab = 'my' | 'register' | 'settings';
export type Cell = {
    status: CredentialStatus;
    waiting: boolean;
    required: boolean;
    expiresOn: string | null;
    doneOn: string | null;
} | null;
export type Row = {
    id: string;
    name: string;
    role: string;
    branchId: number | null;
    branch: string | null;
    mmc: 'verified' | 'not_verified' | 'none' | null;
    canEnter: boolean;
    cells: Record<string, Cell>;
};
export type Waiting = CredentialTypeHead & {
    person: string;
    workerId: string;
    recorded: RecordView | null;
    new: RecordView;
    enteredBy: string;
    enteredAt: string;
    decideRefusal: string | null;
};
export type TypeSetting = CredentialTypeHead & {
    isActive: boolean;
    lockVersion: number;
    versions: {
        version: number;
        name: string;
        warnFromDays: number;
        roles: string[];
        by: string;
        at: string;
    }[];
};

export type CredentialsPageProps = {
    credentials: {
        tab: Tab;
        tabs: Tab[];
        today: string;
        canManage: boolean;
        setUp: boolean;
        options: {
            roles: Option[];
            rejectReasons: Option[];
            suggestedWarnDays: number;
        };
        my: {
            hasFile: boolean;
            mmc: { number: string | null; verified: boolean } | null;
            workerId?: string;
            canOpenDocuments?: boolean;
            cards?: Card[];
        } | null;
        register: {
            filters: {
                branch: number | null;
                role: string | null;
                type: string | null;
                status: string | null;
            };
            types: CredentialTypeHead[];
            rows: Row[];
            total: number;
            summary: {
                expiring: number;
                expired: number;
                not_recorded: number;
                waiting: number;
            };
            waiting: Waiting[];
            permits: WorkPermitDue[];
            branches: { id: number; name: string }[];
        } | null;
        settings: { types: TypeSetting[] } | null;
    };
};

export function useCredentialsPage(props: CredentialsPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const tabLabels: Record<Tab, string> = {
        my: 'My credentials',
        register: 'Register',
        settings: 'Credential types',
    };
    const tabs = computed(() =>
        props.credentials.tabs.map((value) => ({
            value,
            label:
                value === 'register' &&
                props.credentials.register &&
                props.credentials.register.summary.waiting > 0
                    ? `Register (${props.credentials.register.summary.waiting} waiting)`
                    : tabLabels[value],
        })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/credentials', { tab: value }, { preserveScroll: true });
    const roleLabel = (role: string) =>
        props.credentials.options.roles.find((r) => r.value === role)?.label ??
        role;
    const roleList = (roles: string[]) =>
        roles.length ? roles.map(roleLabel).join(', ') : 'No role needs it';

    // ---- Add or renew (own, or for someone from the register) ----
    const sheet = reactive({
        open: false,
        type: null as CredentialTypeHead | null,
        url: '',
        renewing: false,
        person: null as string | null,
    });
    const openOwn = (card: Card) =>
        Object.assign(sheet, {
            open: true,
            type: card,
            url: '/hr/credentials/my',
            renewing: card.inForce !== null,
            person: null,
        });

    // ---- Register ----
    const register = computed(() => props.credentials.register);
    const filters = reactive({ branch: '', role: '', type: '', status: '' });
    watch(
        () => props.credentials.register?.filters,
        (f) => {
            filters.branch = f?.branch ? String(f.branch) : '';
            filters.role = f?.role ?? '';
            filters.type = f?.type ?? '';
            filters.status = f?.status ?? '';
        },
        { immediate: true },
    );
    const applyFilters = () =>
        router.get(
            '/hr/credentials',
            {
                tab: 'register',
                branch: filters.branch || undefined,
                role: filters.role || undefined,
                type: filters.type || undefined,
                status: filters.status || undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    const clearFilters = () => {
        Object.assign(filters, { branch: '', role: '', type: '', status: '' });
        applyFilters();
    };
    const filtered = computed(() =>
        Object.values(register.value?.filters ?? {}).some((v) => v),
    );
    const branchOptions = computed(() => [
        { value: '', label: 'All branches' },
        ...(register.value?.branches ?? []).map((b) => ({
            value: String(b.id),
            label: b.name,
        })),
    ]);
    const roleOptions = computed(() => [
        { value: '', label: 'All roles' },
        ...props.credentials.options.roles,
    ]);
    const typeOptions = computed(() => [
        { value: '', label: 'All credentials' },
        ...(register.value?.types ?? []).map((t) => ({
            value: t.typeId,
            label: t.name,
        })),
    ]);
    const statusOptions = [
        { value: '', label: 'Any status' },
        { value: 'expiring', label: 'Expiring' },
        { value: 'expired', label: 'Expired' },
        { value: 'not_recorded', label: 'Not recorded' },
        { value: 'waiting', label: 'Waiting for check' },
    ];
    // "3 expiring · 1 expired · 2 not recorded · 1 waiting for check"
    const summaryLine = computed(() => {
        const s = register.value?.summary;

        if (!s) {
            return '';
        }

        const parts = [
            s.expiring ? `${s.expiring} expiring` : '',
            s.expired ? `${s.expired} expired` : '',
            s.not_recorded ? `${s.not_recorded} not recorded` : '',
            s.waiting ? `${s.waiting} waiting for check` : '',
        ].filter((p) => p);

        return parts.length
            ? parts.join(' · ')
            : 'Everything recorded is valid.';
    });
    const cellRecord = (cell: NonNullable<Cell>): RecordView | null =>
        cell.expiresOn || cell.doneOn
            ? ({
                  expiresOn: cell.expiresOn,
                  issuedOn: cell.doneOn,
              } as RecordView)
            : null;
    // Below 1024 px: only what needs attention, then "All others valid".
    const attention = (row: Row) =>
        (register.value?.types ?? [])
            .map((t) => ({ type: t, cell: row.cells[t.typeId] }))
            .filter(
                (
                    x,
                ): x is { type: CredentialTypeHead; cell: NonNullable<Cell> } =>
                    !!x.cell &&
                    (x.cell.waiting ||
                        [
                            'expiring',
                            'expired',
                            'not_recorded',
                            'not_done',
                        ].includes(x.cell.status.code)),
            );

    const enter = reactive({
        open: false,
        row: null as Row | null,
        typeId: '',
    });
    const startEnter = (row: Row) =>
        Object.assign(enter, { open: true, row, typeId: '' });
    const continueEnter = () => {
        const type = register.value?.types.find(
            (t) => t.typeId === enter.typeId,
        );

        if (!type || !enter.row) {
            return;
        }

        Object.assign(sheet, {
            open: true,
            type,
            url: `/hr/credentials/workers/${enter.row.id}`,
            renewing:
                !!enter.row.cells[type.typeId]?.status &&
                !['not_recorded', 'not_required'].includes(
                    enter.row.cells[type.typeId]?.status.code ?? '',
                ),
            person: enter.row.name,
        });
        enter.open = false;
    };
    const enterTypeOptions = computed(() =>
        (register.value?.types ?? []).map((t) => ({
            value: t.typeId,
            label: t.name,
        })),
    );

    const decision = reactive({
        item: null as Waiting | null,
        kind: 'accept' as 'accept' | 'reject',
        reason: '',
        key: '',
        processing: false,
    });
    const askDecision = (item: Waiting, kind: 'accept' | 'reject') =>
        Object.assign(decision, {
            item,
            kind,
            reason: '',
            key: crypto.randomUUID(),
        });
    const sendDecision = () => {
        const item = decision.item;

        if (!item) {
            return;
        }

        decision.processing = true;
        router.post(
            `/hr/credentials/records/${item.new.id}/${decision.kind}`,
            {
                reason: decision.kind === 'reject' ? decision.reason : null,
                expected_lock_version: item.new.lockVersion,
                idempotency_key: decision.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (decision.item = null),
                onFinish: () => (decision.processing = false),
            },
        );
    };
    const recordSummary = (r: RecordView | null, type: CredentialTypeHead) => {
        if (!r) {
            return 'Nothing recorded';
        }

        if (type.healthItem) {
            return r.healthDone
                ? `Done · ${dateLabel(r.issuedOn)}`
                : 'Not done';
        }

        return [
            `Issued ${dateLabel(r.issuedOn)}`,
            r.expiresOn ? `expires ${dateLabel(r.expiresOn)}` : 'no expiry',
            r.issuingBody,
            r.referenceLast4 ? `reference •••• ${r.referenceLast4}` : null,
        ]
            .filter((p) => p)
            .join(' · ');
    };

    // ---- Credential types ----
    const newType = reactive({
        code: '',
        name: '',
        expires: 'yes',
        health: 'no',
        permit: '',
        warn: '',
        roles: [] as string[],
        processing: false,
    });
    watch(
        () => props.credentials.options.suggestedWarnDays,
        (d) => (newType.warn = String(d)),
        { immediate: true },
    );
    const yesNo = [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
    ];
    // HR-21: marking a type as a work permit is permanent, so it is asked once more before saving.
    const permitConfirm = ref(false);
    const isPermit = () =>
        newType.health !== 'yes' &&
        newType.expires === 'yes' &&
        newType.permit === 'yes';
    // Daniel (HR-21): the permanent Work permit answer starts unpicked, so it is always a choice.
    const permitMissing = computed(() =>
        newType.health !== 'yes' &&
        newType.expires === 'yes' &&
        newType.permit === ''
            ? 'Pick Yes or No for Work permit to continue.'
            : '',
    );
    const addType = () => {
        if (permitMissing.value !== '') {
            return;
        }

        if (isPermit() && !permitConfirm.value) {
            permitConfirm.value = true;

            return;
        }

        newType.processing = true;
        router.post(
            '/hr/credentials/types',
            {
                code: newType.code,
                name: newType.name,
                expires:
                    newType.health === 'yes'
                        ? false
                        : newType.expires === 'yes',
                health_item: newType.health === 'yes',
                work_permit: isPermit(),
                warn_from_days: newType.warn,
                roles: newType.roles,
                idempotency_key: crypto.randomUUID(),
            },
            {
                preserveScroll: true,
                onSuccess: () =>
                    Object.assign(newType, {
                        code: '',
                        name: '',
                        roles: [],
                        expires: 'yes',
                        health: 'no',
                        permit: '',
                        warn: String(
                            props.credentials.options.suggestedWarnDays,
                        ),
                    }),
                onFinish: () => {
                    newType.processing = false;
                    permitConfirm.value = false;
                },
            },
        );
    };
    const edit = reactive({
        open: false,
        type: null as TypeSetting | null,
        name: '',
        warn: '',
        roles: [] as string[],
        key: '',
        processing: false,
    });
    const startEdit = (t: TypeSetting) =>
        Object.assign(edit, {
            open: true,
            type: t,
            name: t.name,
            warn: String(t.warnFromDays),
            roles: [...t.roles],
            key: crypto.randomUUID(),
        });
    const saveEdit = () => {
        const t = edit.type;

        if (!t) {
            return;
        }

        edit.processing = true;
        router.post(
            '/hr/credentials/types',
            {
                type_id: t.typeId,
                name: edit.name,
                warn_from_days: edit.warn,
                roles: edit.roles,
                expected_lock_version: t.lockVersion,
                idempotency_key: edit.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (edit.open = false),
                onFinish: () => (edit.processing = false),
            },
        );
    };
    const archiving = ref<TypeSetting | null>(null);
    const archiveBusy = ref(false);
    const archiveType = () => {
        const t = archiving.value;

        if (!t) {
            return;
        }

        archiveBusy.value = true;
        router.post(
            `/hr/credentials/types/${t.typeId}/archive`,
            {
                expected_lock_version: t.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            {
                preserveScroll: true,
                onSuccess: () => (archiving.value = null),
                onFinish: () => (archiveBusy.value = false),
            },
        );
    };

    return {
        errors,
        tabs,
        selectTab,
        roleList,
        sheet,
        openOwn,
        register,
        filters,
        applyFilters,
        clearFilters,
        filtered,
        branchOptions,
        roleOptions,
        typeOptions,
        statusOptions,
        summaryLine,
        cellRecord,
        attention,
        enter,
        startEnter,
        continueEnter,
        enterTypeOptions,
        decision,
        askDecision,
        sendDecision,
        recordSummary,
        newType,
        yesNo,
        addType,
        permitConfirm,
        permitMissing,
        edit,
        startEdit,
        saveEdit,
        archiving,
        archiveBusy,
        archiveType,
    };
}
