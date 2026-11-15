import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';

/**
 * Version 2 Panel Claims list logic. A verbatim copy of the script of the Classic page
 * (`pages/PanelClaims/Index.vue`, which is untouched): the same filters, the same `GET /panel-claims` query, the same
 * one-Panel bulk-claim and claim-pack rules and limits, the same idempotency keys. Only the Version 2 page uses this
 * file. A test compares it with Classic.
 */
export type PageSet<T> = {
    data: T[];
    total: number;
    currentPage: number;
    lastPage: number;
};
export type Figure = { count: number; amountSen: number };

export type Props = {
    work: {
        branch: { id: number; name: string; today: string };
        summary: Record<
            | 'pending'
            | 'overdue'
            | 'approved'
            | 'received'
            | 'rejected'
            | 'outstanding',
            Figure
        >;
        tabs: Record<string, number>;
        panels: string[];
        filters: {
            tab: string;
            range: string;
            from: string | null;
            to: string | null;
            panel: string;
            claimNo: string;
            amountMin: string;
            amountMax: string;
            invoiceNo: string;
            name: string;
            doctor: string;
            visitType: string;
            visitFrom: string;
            visitTo: string;
            dueFrom: string;
            dueTo: string;
            diagnosis: string;
            treatment: string;
        };
        can: { clinicalFilters: boolean; recordRemittances?: boolean };
        eligible: PageSet<{
            allocationId: string;
            allocationLockVersion: number;
            invoiceLockVersion: number;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            invoiceUrl: string;
            panelName: string;
            panelCode: string;
            amountSen: number;
            invoiceTotalSen: number;
            visitType: string;
            doctorName: string | null;
            visitDate: string;
            visitDateIso: string;
            dueDate: string;
            overdue: boolean;
            remarkNote: string;
        }>;
        claims: PageSet<{
            publicId: string;
            claimNo: string;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            panelName: string;
            panelId: number;
            claimAmountSen: number;
            status: string;
            overdue: boolean;
            createdAt: string | null;
            updatedBy: string | null;
            remarks: string | null;
            showUrl: string;
        }>;
    };
};

export function usePanelClaimsList(props: Props) {
    const rangeOptions = [
        { value: 'all', label: 'All time' },
        { value: 'yesterday', label: 'Yesterday' },
        { value: 'last_7_days', label: 'Last 7 days' },
        { value: 'last_30_days', label: 'Last 30 days' },
        { value: 'last_90_days', label: 'Last 90 days' },
        { value: 'this_week', label: 'This week' },
        { value: 'this_month', label: 'This month' },
        { value: 'this_year', label: 'This year' },
        { value: 'last_week', label: 'Last week' },
        { value: 'last_month', label: 'Last month' },
        { value: 'custom', label: 'Custom' },
    ];
    const tabLabels: [string, string][] = [
        ['all', 'All'],
        ['pending', 'Pending'],
        ['overdue', 'Overdue'],
        ['submitted', 'Submitted'],
        ['approved', 'Approved'],
        ['rejected', 'Rejected'],
        ['received', 'Received'],
    ];
    const claimColumns = [
        { key: 'amount', label: 'Amount' },
        { key: 'claimNo', label: 'Claim no' },
        { key: 'panel', label: 'Panel' },
        { key: 'patient', label: 'Patient name' },
        { key: 'status', label: 'Status' },
        { key: 'date', label: 'Date' },
        { key: 'updatedBy', label: 'Updated by' },
        { key: 'remarks', label: 'Remarks' },
    ] as const;
    const invoiceColumns = [
        { key: 'amount', label: 'Amount' },
        { key: 'invoiceNo', label: 'Invoice no' },
        { key: 'panel', label: 'Panel' },
        { key: 'patient', label: 'Patient name' },
        { key: 'status', label: 'Status' },
        { key: 'visitDate', label: 'Visit date' },
        { key: 'dueDate', label: 'Due date' },
        { key: 'doctor', label: 'Doctor' },
        { key: 'remarks', label: 'Remark note' },
    ] as const;
    type ColumnKey =
        | (typeof claimColumns)[number]['key']
        | (typeof invoiceColumns)[number]['key'];

    const visitTypeOptions = [
        { value: '', label: 'All visit types' },
        { value: 'consultation', label: 'Consultation' },
        { value: 'otc', label: 'OTC' },
    ];
    const draft = reactive({
        panel: props.work.filters.panel,
        claimNo: props.work.filters.claimNo,
        amountMin: props.work.filters.amountMin,
        amountMax: props.work.filters.amountMax,
        invoiceNo: props.work.filters.invoiceNo,
        name: props.work.filters.name,
        doctor: props.work.filters.doctor,
        visitType: props.work.filters.visitType,
        visitFrom: props.work.filters.visitFrom,
        visitTo: props.work.filters.visitTo,
        dueFrom: props.work.filters.dueFrom,
        dueTo: props.work.filters.dueTo,
        diagnosis: props.work.filters.diagnosis,
        treatment: props.work.filters.treatment,
        from: props.work.filters.from ?? '',
        to: props.work.filters.to ?? '',
    });
    const visibleColumns = ref<Record<ColumnKey, boolean>>({
        amount: true,
        claimNo: true,
        invoiceNo: true,
        panel: true,
        patient: true,
        status: true,
        date: true,
        visitDate: true,
        dueDate: true,
        doctor: false,
        updatedBy: true,
        remarks: true,
    });
    const invoiceMode = computed(() =>
        ['pending', 'overdue'].includes(props.work.filters.tab),
    );
    const columnList = computed(() =>
        invoiceMode.value ? invoiceColumns : claimColumns,
    );
    const shownColumnCount = computed(
        () =>
            columnList.value.filter(
                (column) => visibleColumns.value[column.key],
            ).length + 2,
    );
    const tabs = computed(() =>
        tabLabels.map(([value, label]) => ({
            value,
            label,
            count: props.work.tabs[value] ?? 0,
        })),
    );
    const panelOptions = computed(() => [
        { value: '', label: 'All Panels' },
        ...props.work.panels.map((name) => ({ value: name, label: name })),
    ]);
    const filtersActive = computed(() =>
        [
            draft.panel,
            draft.claimNo,
            draft.amountMin,
            draft.amountMax,
            draft.invoiceNo,
            draft.name,
            draft.doctor,
            draft.visitType,
            draft.visitFrom,
            draft.visitTo,
            draft.dueFrom,
            draft.dueTo,
            draft.diagnosis,
            draft.treatment,
        ].some((value) => value !== ''),
    );

    const selected = ref<Record<string, EligibleRow>>({});
    type ClaimRow = Props['work']['claims']['data'][number];
    const packSelected = ref<Record<string, ClaimRow>>({});
    const query = (overrides: Record<string, string | number | null> = {}) => {
        const values: Record<string, string | number | null> = {
            tab: props.work.filters.tab,
            range: props.work.filters.range,
            from: draft.from,
            to: draft.to,
            panel: draft.panel,
            claim_no: draft.claimNo,
            amount_min: draft.amountMin,
            amount_max: draft.amountMax,
            invoice_no: draft.invoiceNo,
            name: draft.name,
            doctor: draft.doctor,
            visit_type: draft.visitType,
            visit_from: draft.visitFrom,
            visit_to: draft.visitTo,
            due_from: draft.dueFrom,
            due_to: draft.dueTo,
            diagnosis: draft.diagnosis,
            treatment: draft.treatment,
            ...overrides,
        };

        return Object.fromEntries(
            Object.entries(values).filter(
                ([key, value]) =>
                    value !== '' &&
                    value !== null &&
                    !(key === 'tab' && value === 'all') &&
                    !(key === 'range' && value === 'all'),
            ),
        );
    };
    // Any change of tab, filter or page starts a fresh bulk selection (see PNL-2 below).
    const go = (overrides: Record<string, string | number | null> = {}) => {
        selected.value = {};
        router.get('/panel-claims', query(overrides), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const resetFilters = () => {
        draft.panel = '';
        draft.claimNo = '';
        draft.amountMin = '';
        draft.amountMax = '';
        draft.invoiceNo = '';
        draft.name = '';
        draft.doctor = '';
        draft.visitType = '';
        draft.visitFrom = '';
        draft.visitTo = '';
        draft.dueFrom = '';
        draft.dueTo = '';
        draft.diagnosis = '';
        draft.treatment = '';
        go({ claims_page: null, allocations_page: null });
    };
    const turnPage = (key: 'allocations_page' | 'claims_page', value: number) =>
        go({ [key]: value });

    const error = ref('');
    // PNL-12: "1 claim" / "2 claims", shared by the Submissions and Payouts cards.
    const countLabel = (count: number, noun: 'claim' | 'invoice') =>
        `${count} ${noun}${count === 1 ? '' : 's'}`;
    const createClaim = (allocationId: string) => {
        router.visit(
            `/panel-claims/allocations/${encodeURIComponent(allocationId)}/create`,
        );
    };

    // PNL-2: bulk claim creation for several invoices of one Panel. Each claim keeps its own idempotency key,
    // so a retry after a refused batch reuses the same keys and never creates a duplicate.
    type EligibleRow = Props['work']['eligible']['data'][number];
    const page = usePage();
    const bulkErrors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    // Every refusal of a batch is shown: per-invoice and date messages, remarks, a changed branch and
    // the first per-item field error.
    const bulkErrorMessages = computed(() => {
        const errors = bulkErrors.value;
        const keys = [
            'claims',
            'submitted_date',
            'remarks',
            'expected_branch_id',
        ];
        const itemKey = Object.keys(errors).find((key) =>
            key.startsWith('claims.'),
        );

        return [...keys, ...(itemKey ? [itemKey] : [])]
            .map((key) => errors[key])
            .filter((message): message is string => Boolean(message));
    });
    const bulkMax = 20;
    const bulkKeys = new Map<string, string>();
    const bulk = reactive({
        submittedDate: props.work.branch.today,
        remarks: '',
        busy: false,
    });
    const selectedRows = computed(() => Object.values(selected.value));
    // The one-Panel rule compares the Panel's fixed code; the name is for display only (it can be renamed).
    const selectedPanelCode = computed(
        () => selectedRows.value[0]?.panelCode ?? null,
    );
    const selectedPanel = computed(
        () => selectedRows.value[0]?.panelName ?? null,
    );
    const pagePanelCodes = computed(() => [
        ...new Set(props.work.eligible.data.map((row) => row.panelCode)),
    ]);
    const selectAllPanelCode = computed(
        () =>
            selectedPanelCode.value ??
            (pagePanelCodes.value.length === 1
                ? pagePanelCodes.value[0]
                : null),
    );
    const selectAllRows = computed(() =>
        props.work.eligible.data.filter(
            (row) => row.panelCode === selectAllPanelCode.value,
        ),
    );
    const selectAllPanel = computed(
        () => selectAllRows.value[0]?.panelName ?? null,
    );
    const atBulkMax = computed(() => selectedRows.value.length >= bulkMax);
    const allSelected = computed(
        () =>
            selectAllRows.value.length > 0 &&
            selectAllRows.value.every(
                (row) => selected.value[row.allocationId],
            ),
    );
    // Fully ticked only when every row on this page is selected; a dash when some of them are.
    const headerState = computed<boolean | 'indeterminate'>(() => {
        const rows = props.work.eligible.data;
        const ticked = rows.filter((row) => selected.value[row.allocationId]);

        if (ticked.length === 0) {
            return false;
        }

        return ticked.length === rows.length ? true : 'indeterminate';
    });
    const selectedTotalSen = computed(() =>
        selectedRows.value.reduce((sum, row) => sum + row.amountSen, 0),
    );
    const minSubmittedDate = computed(() =>
        selectedRows.value.reduce(
            (latest, row) =>
                row.visitDateIso > latest ? row.visitDateIso : latest,
            '',
        ),
    );
    const canSelect = (row: EligibleRow) =>
        selectedPanelCode.value === null ||
        row.panelCode === selectedPanelCode.value;
    const lockedReason = (row: EligibleRow) =>
        canSelect(row)
            ? undefined
            : `Only invoices of ${selectedPanel.value} can be added to this batch`;
    const toggleRow = (row: EligibleRow, value: boolean) => {
        const next = { ...selected.value };

        if (value && canSelect(row) && Object.keys(next).length < bulkMax) {
            next[row.allocationId] = row;
        } else {
            delete next[row.allocationId];
        }

        selected.value = next;
    };
    const toggleAll = (value: boolean) => {
        const next = { ...selected.value };

        for (const row of selectAllRows.value) {
            if (value && Object.keys(next).length < bulkMax) {
                next[row.allocationId] = row;
            } else if (!value) {
                delete next[row.allocationId];
            }
        }

        selected.value = next;
    };
    const clearSelection = () => {
        selected.value = {};
    };

    // PNL-8: tick claims of one Panel (at most 50) and print them as one pack: a cover page, then one page per claim.
    const packMax = 50;
    // The pack selection is kept across pages of the list, so up to 50 claims can be gathered; a new tab,
    // date range or filter starts a fresh selection.
    watch(
        () => JSON.stringify(props.work.filters),
        () => {
            packSelected.value = {};
        },
    );
    const packError = computed(() => bulkErrors.value.claims ?? null);
    const packRows = computed(() => Object.values(packSelected.value));
    const packPanelId = computed(() => packRows.value[0]?.panelId ?? null);
    const packPanel = computed(() => packRows.value[0]?.panelName ?? null);
    const packPagePanels = computed(() => [
        ...new Set(props.work.claims.data.map((row) => row.panelId)),
    ]);
    const packAllPanelId = computed(
        () =>
            packPanelId.value ??
            (packPagePanels.value.length === 1
                ? packPagePanels.value[0]
                : null),
    );
    const packAllRows = computed(() =>
        props.work.claims.data.filter(
            (row) => row.panelId === packAllPanelId.value,
        ),
    );
    const packAllPanel = computed(
        () => packAllRows.value[0]?.panelName ?? null,
    );
    const atPackMax = computed(() => packRows.value.length >= packMax);
    const packAllSelected = computed(
        () =>
            packAllRows.value.length > 0 &&
            packAllRows.value.every((row) => packSelected.value[row.publicId]),
    );
    const packHeaderState = computed<boolean | 'indeterminate'>(() => {
        const rows = props.work.claims.data;
        const ticked = rows.filter((row) => packSelected.value[row.publicId]);

        if (ticked.length === 0) {
            return false;
        }

        return ticked.length === rows.length ? true : 'indeterminate';
    });
    const packTotalSen = computed(() =>
        packRows.value.reduce((sum, row) => sum + row.claimAmountSen, 0),
    );
    const canPack = (row: ClaimRow) =>
        packPanelId.value === null || row.panelId === packPanelId.value;
    const packLockedReason = (row: ClaimRow) =>
        canPack(row)
            ? undefined
            : `Only claims of ${packPanel.value} can be added to this pack`;
    const togglePackRow = (row: ClaimRow, value: boolean) => {
        const next = { ...packSelected.value };

        if (value && canPack(row) && Object.keys(next).length < packMax) {
            next[row.publicId] = row;
        } else {
            delete next[row.publicId];
        }

        packSelected.value = next;
    };
    const togglePackAll = (value: boolean) => {
        const next = { ...packSelected.value };

        for (const row of packAllRows.value) {
            if (value && Object.keys(next).length < packMax) {
                next[row.publicId] = row;
            } else if (!value) {
                delete next[row.publicId];
            }
        }

        packSelected.value = next;
    };
    const clearPack = async () => {
        packSelected.value = {};
        await nextTick();
        // Focus whichever "Select all" is on screen: the table header on wide
        // screens, the row above the cards on phones and tablets. A disabled one
        // (claims of several Panels on the page) cannot take focus, so fall back to
        // the first claim checkbox on screen that can still be ticked.
        const onScreen = (
            element: HTMLElement | null,
        ): element is HTMLElement =>
            element !== null &&
            element.offsetParent !== null &&
            !element.hasAttribute('disabled');
        const selectAll = ['pack-select-all', 'pack-select-all-phone']
            .map((id) => document.getElementById(id))
            .find(onScreen);
        const firstRow = Array.from(
            document.querySelectorAll<HTMLElement>(
                '[aria-label$="for a claim pack"]',
            ),
        ).find(onScreen);
        (selectAll ?? firstRow)?.focus();
    };
    const printPack = () => {
        if (packRows.value.length === 0) {
            return;
        }

        router.get('/panel-claims/pack', {
            claims: packRows.value.map((row) => row.publicId),
        });
    };
    const keyFor = (allocationId: string) => {
        if (!bulkKeys.has(allocationId)) {
            bulkKeys.set(allocationId, crypto.randomUUID());
        }

        return bulkKeys.get(allocationId) as string;
    };
    const createSelectedClaims = () => {
        if (selectedRows.value.length === 0 || bulk.busy) {
            return;
        }

        bulk.busy = true;
        router.post(
            '/panel-claims/bulk',
            {
                expected_branch_id: props.work.branch.id,
                submitted_date: bulk.submittedDate,
                remarks: bulk.remarks,
                ...query({ allocations_page: props.work.eligible.currentPage }),
                claims: selectedRows.value.map((row) => ({
                    allocation: row.allocationId,
                    allocation_lock_version: row.allocationLockVersion,
                    invoice_lock_version: row.invoiceLockVersion,
                    idempotency_key: keyFor(row.allocationId),
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    selected.value = {};
                    bulk.remarks = '';
                },
                onFinish: () => {
                    bulk.busy = false;
                },
            },
        );
    };
    const canMarkSubmitted = (status: string) =>
        status === 'Prepared' || status === 'Panel query';
    const visitTypeLabel = (value: string) =>
        value === 'otc' ? 'OTC' : 'Consultation';

    return {
        allSelected,
        atBulkMax,
        atPackMax,
        bulk,
        bulkErrorMessages,
        bulkErrors,
        bulkKeys,
        bulkMax,
        canMarkSubmitted,
        canPack,
        canSelect,
        claimColumns,
        clearPack,
        clearSelection,
        columnList,
        countLabel,
        createClaim,
        createSelectedClaims,
        draft,
        error,
        filtersActive,
        go,
        headerState,
        invoiceColumns,
        invoiceMode,
        keyFor,
        lockedReason,
        minSubmittedDate,
        packAllPanel,
        packAllPanelId,
        packAllRows,
        packAllSelected,
        packError,
        packHeaderState,
        packLockedReason,
        packMax,
        packPagePanels,
        packPanel,
        packPanelId,
        packRows,
        packSelected,
        packTotalSen,
        page,
        pagePanelCodes,
        panelOptions,
        printPack,
        query,
        rangeOptions,
        resetFilters,
        selectAllPanel,
        selectAllPanelCode,
        selectAllRows,
        selected,
        selectedPanel,
        selectedPanelCode,
        selectedRows,
        selectedTotalSen,
        shownColumnCount,
        tabLabels,
        tabs,
        toggleAll,
        togglePackAll,
        togglePackRow,
        toggleRow,
        turnPage,
        visibleColumns,
        visitTypeLabel,
        visitTypeOptions,
    };
}
