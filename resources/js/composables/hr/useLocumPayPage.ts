import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * HR-15g locum pay statement (owner, 18 Nov 2026). State and actions shared by Classic (`pages/HR/LocumPay.vue`) and
 * Version 2 (`pages/HR/V2/LocumPay.vue`), so both send the same requests, lock versions and idempotency keys.
 * KPOne works out the statement and records a payment made outside it; it pays nothing.
 */
export type Tab = 'mine' | 'statements' | 'rates';
export type Option = { value: string; label: string };
export type Line = {
    shiftId: string;
    date: string;
    dateLabel: string;
    time: string;
    branch: string;
    hours: string;
    basis: string | null;
    amountLabel: string;
    noRate: boolean;
    notWorked: { id?: string; reason: string; lockVersion?: number } | null;
};
export type Correction = {
    id?: string;
    reason: string;
    forMonth: string | null;
    amountLabel: string;
    lockVersion?: number;
};
export type Statement = {
    paid: boolean;
    month: string;
    totalLabel: string;
    lines: Line[];
    corrections: Correction[];
    problems: string[];
    staleMarks: {
        id: string;
        dateLabel: string;
        reason: string;
        lockVersion: number;
    }[];
    payment: {
        paidOnLabel: string;
        reference: string | null;
        paidBy: string | null;
    } | null;
    fingerprint: string | null;
};
export type LocumRow = {
    id: string;
    name: string;
    linked: boolean;
    status:
        | 'paid'
        | 'ready'
        | 'needs_rate'
        | 'needs_check'
        | 'nothing'
        | 'below_zero'
        | 'open';
    statusLabel: string;
    totalLabel: string;
    shifts: number;
};
export type RateRow = {
    id: string;
    kind: string;
    kindLabel: string;
    amountLabel: string;
    fromLabel: string;
    status: 'waiting' | 'confirmed' | 'rejected' | 'withdrawn';
    statusLabel: string;
    rejectReason: string | null;
    enteredBy: string;
    decidedBy: string | null;
    lockVersion: number;
    canDecide: boolean;
    ownEntry: boolean;
    canWithdraw: boolean;
};
export type LocumRates = {
    workerId: string;
    name: string;
    current: {
        kindLabel: string;
        amountLabel: string;
        fromLabel: string;
    } | null;
    waiting: RateRow | null;
    history: RateRow[];
};

export type LocumPayPageProps = {
    locumPay: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        canFinance: boolean;
        month: string;
        monthLabel: string;
        previousMonth: string;
        nextMonth: string | null;
        monthEnded: boolean;
        today: string;
        kinds: Option[];
        reasons: Option[];
        correctionReasons: Option[];
        rejectReasons: Option[];
        mine: {
            name: string;
            statement: Statement;
            rate: { kindLabel: string; amountLabel: string } | null;
        } | null;
        locums: LocumRow[];
        statement:
            | (Statement & {
                  workerId: string;
                  name: string;
                  canMarkPaid: boolean;
                  canEdit: boolean;
              })
            | null;
        rates: LocumRates[];
    };
};

export function useLocumPayPage(props: LocumPayPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () =>
            errors.value.locum_pay ?? errors.value.records ?? errors.value.rate,
    );
    const tabLabels: Record<Tab, string> = {
        mine: 'My pay',
        statements: 'Statements',
        rates: 'Rates',
    };
    const tabs = computed(() =>
        props.locumPay.tabs.map((value) => ({
            value,
            label: tabLabels[value],
        })),
    );
    const visit = (query: Record<string, string | null | undefined>) =>
        router.get(
            '/hr/locum-pay',
            Object.fromEntries(
                Object.entries(query).filter(([, v]) => v != null && v !== ''),
            ),
            { preserveScroll: true },
        );
    const selectTab = (value: string | number) =>
        visit({ tab: String(value), month: props.locumPay.month });
    const showMonth = (month: string | null) =>
        month &&
        visit({
            tab: props.locumPay.tab,
            month,
            locum: props.locumPay.statement?.workerId,
        });
    const openLocum = (id: string) =>
        visit({ tab: 'statements', month: props.locumPay.month, locum: id });
    const closeLocum = () =>
        visit({ tab: 'statements', month: props.locumPay.month });

    const statusTone = (status: LocumRow['status']) =>
        status === 'paid'
            ? 'success'
            : status === 'ready'
              ? 'accent'
              : status === 'needs_rate' ||
                  status === 'below_zero' ||
                  status === 'needs_check'
                ? 'warning'
                : 'neutral';
    const rateTone = (status: RateRow['status']) =>
        status === 'confirmed'
            ? 'success'
            : status === 'waiting'
              ? 'warning'
              : 'neutral';

    const busy = reactive<{ id: string | null }>({ id: null });
    const post = (
        url: string,
        data: Record<string, unknown>,
        done?: () => void,
    ) =>
        router.post(
            url,
            {
                ...data,
                month: props.locumPay.month,
                locum: props.locumPay.statement?.workerId,
            } as never,
            {
                preserveScroll: true,
                onSuccess: () => done?.(),
                onFinish: () => (busy.id = null),
            },
        );

    // ---- Rates ----
    const rateForm = reactive({
        open: false,
        workerId: '',
        name: '',
        kind: 'hour',
        amount: '',
        effective_from: '',
        key: crypto.randomUUID(),
    });
    const startRate = (row: LocumRates) =>
        Object.assign(rateForm, {
            open: true,
            workerId: row.workerId,
            name: row.name,
            kind: 'hour',
            amount: '',
            effective_from: props.locumPay.today,
            key: crypto.randomUUID(),
        });
    const saveRate = () => {
        busy.id = 'rate';
        post(
            `/hr/locum-pay/locums/${rateForm.workerId}/rates`,
            {
                kind: rateForm.kind,
                amount: rateForm.amount,
                effective_from: rateForm.effective_from,
                idempotency_key: rateForm.key,
            },
            () => (rateForm.open = false),
        );
    };
    const decideRate = (
        rate: RateRow,
        action: 'confirm' | 'withdraw',
        done?: () => void,
    ) => {
        busy.id = rate.id;
        post(
            `/hr/locum-pay/rates/${rate.id}/${action}`,
            {
                expected_lock_version: rate.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            done,
        );
    };
    // Confirming a rate asks first, and says the person who entered it never confirms it (Daniel).
    const confirmForm = reactive({
        open: false,
        rate: null as RateRow | null,
        name: '',
    });
    const startConfirm = (rate: RateRow, name: string) =>
        Object.assign(confirmForm, { open: true, rate, name });
    const confirmRate = () =>
        confirmForm.rate &&
        decideRate(
            confirmForm.rate,
            'confirm',
            () => (confirmForm.open = false),
        );
    const rejectForm = reactive({
        open: false,
        rate: null as RateRow | null,
        reason: '',
        key: crypto.randomUUID(),
    });
    const startReject = (rate: RateRow) =>
        Object.assign(rejectForm, {
            open: true,
            rate,
            reason: '',
            key: crypto.randomUUID(),
        });
    const reject = () => {
        if (!rejectForm.rate) {
            return;
        }

        busy.id = rejectForm.rate.id;
        post(
            `/hr/locum-pay/rates/${rejectForm.rate.id}/reject`,
            {
                reason: rejectForm.reason,
                expected_lock_version: rejectForm.rate.lockVersion,
                idempotency_key: rejectForm.key,
            },
            () => (rejectForm.open = false),
        );
    };

    // ---- Not worked ----
    const notWorked = reactive({
        open: false,
        line: null as Line | null,
        reason: '',
        key: crypto.randomUUID(),
    });
    const startNotWorked = (line: Line) =>
        Object.assign(notWorked, {
            open: true,
            line,
            reason: '',
            key: crypto.randomUUID(),
        });
    const saveNotWorked = () => {
        const statement = props.locumPay.statement;

        if (!statement || !notWorked.line) {
            return;
        }

        busy.id = notWorked.line.shiftId;
        post(
            `/hr/locum-pay/locums/${statement.workerId}/not-worked`,
            {
                shift_id: notWorked.line.shiftId,
                reason: notWorked.reason,
                idempotency_key: notWorked.key,
            },
            () => (notWorked.open = false),
        );
    };
    const countAgain = (line: Line) => {
        if (!line.notWorked?.id) {
            return;
        }

        busy.id = line.shiftId;
        post(`/hr/locum-pay/not-worked/${line.notWorked.id}/remove`, {
            expected_lock_version: line.notWorked.lockVersion,
            idempotency_key: crypto.randomUUID(),
        });
    };

    // A mark left on a shift the roster replaced (Suresh, M1).
    const removeMark = (mark: Statement['staleMarks'][number]) => {
        busy.id = mark.id;
        post(`/hr/locum-pay/not-worked/${mark.id}/remove`, {
            expected_lock_version: mark.lockVersion,
            idempotency_key: crypto.randomUUID(),
        });
    };

    // ---- Corrections ----
    const correction = reactive({
        open: false,
        direction: 'add',
        amount: '',
        reason: '',
        for_month: '',
        key: crypto.randomUUID(),
    });
    const startCorrection = () =>
        Object.assign(correction, {
            open: true,
            direction: 'add',
            amount: '',
            reason: '',
            for_month: props.locumPay.previousMonth,
            key: crypto.randomUUID(),
        });
    const saveCorrection = () => {
        const statement = props.locumPay.statement;

        if (!statement) {
            return;
        }

        busy.id = 'correction';
        post(
            `/hr/locum-pay/locums/${statement.workerId}/corrections`,
            {
                direction: correction.direction,
                amount: correction.amount,
                reason: correction.reason,
                for_month:
                    correction.reason === 'earlier_month'
                        ? correction.for_month
                        : null,
                idempotency_key: correction.key,
            },
            () => (correction.open = false),
        );
    };
    // Removing a correction asks first (Daniel).
    const removing = reactive({
        open: false,
        item: null as Correction | null,
    });
    const startRemoveCorrection = (item: Correction) =>
        Object.assign(removing, { open: true, item });
    const removeCorrection = () => {
        const item = removing.item;

        if (!item?.id) {
            return;
        }

        busy.id = item.id;
        post(
            `/hr/locum-pay/corrections/${item.id}/remove`,
            {
                expected_lock_version: item.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            () => (removing.open = false),
        );
    };

    // ---- Mark paid ----
    const paid = reactive({
        open: false,
        paid_on: '',
        reference: '',
        key: crypto.randomUUID(),
    });
    const startPaid = () =>
        Object.assign(paid, {
            open: true,
            paid_on: props.locumPay.today,
            reference: '',
            key: crypto.randomUUID(),
        });
    const savePaid = () => {
        const statement = props.locumPay.statement;

        if (!statement) {
            return;
        }

        busy.id = 'paid';
        post(
            `/hr/locum-pay/locums/${statement.workerId}/paid`,
            {
                paid_on: paid.paid_on,
                reference: paid.reference,
                fingerprint: statement.fingerprint,
                idempotency_key: paid.key,
            },
            () => (paid.open = false),
        );
    };

    return {
        errors,
        failure,
        tabs,
        selectTab,
        showMonth,
        openLocum,
        closeLocum,
        statusTone,
        rateTone,
        busy,
        rateForm,
        startRate,
        saveRate,
        decideRate,
        confirmForm,
        startConfirm,
        confirmRate,
        rejectForm,
        startReject,
        reject,
        notWorked,
        startNotWorked,
        saveNotWorked,
        countAgain,
        removeMark,
        correction,
        startCorrection,
        saveCorrection,
        removing,
        startRemoveCorrection,
        removeCorrection,
        paid,
        startPaid,
        savePaid,
    };
}
