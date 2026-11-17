import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import type { Option, RunStatus } from '@/components/hr/payroll/types';
import { rmText } from '@/components/hr/payroll/types';
import { myr } from '@/types/billing';

export type Totals = {
    earnings: number;
    deduction: number;
    refund: number;
    employee: number;
    employer: number;
    mtd: number;
    net: number;
};
export type Line = {
    kind: string;
    section:
        'earnings' | 'deduction' | 'refund' | 'employee' | 'employer' | 'mtd';
    amountSen: number;
    explanation: string;
};
export type RunPerson = {
    id: string;
    name: string;
    totals: Totals;
    mtdSen: number | null;
    needsMtd: boolean;
    errors: string[];
    warnings: string[];
    lines: Line[];
    manual: { id: string }[];
};
export type Step = 'approve' | 'return' | 'lock';
// G2a and G2b: overtime, unpaid leave and worked hours changed after the regular run was locked (never paid or
// taken automatically). No money: `change` says which way and by how much, in days or hours.
type LateKind = 'overtime' | 'leave_cancelled' | 'leave_approved' | 'hours';
export type { LateKind };
export type LateItem = {
    kind: LateKind;
    kindLabel: string;
    change: string;
    id: string;
    name: string;
    date: string;
    duration: string;
    approvedAt: string;
    state: 'waiting' | 'here' | 'elsewhere';
    itemId: string | null;
};

/**
 * HR-7b one payroll run page state and requests (send, approve, return, lock, MTD, manual lines, late items, start an
 * adjustment). Shared by the Classic page (`pages/HR/PayrollRun.vue`) and V2, so both send the same requests. Moved unchanged.
 */
export type PayrollRunPageProps = {
    run: {
        id: string;
        kind: 'regular' | 'adjustment';
        status: RunStatus;
        month: string;
        group: string;
        payDay: number | null;
        lockVersion: number;
        preparedBy: string;
        sentBy: string | null;
        approvedBy: string | null;
        lockedAt: string | null;
        people: RunPerson[];
        total: Totals;
        errorCount: number;
        late: LateItem[];
        // Suresh m1: hourly people whose paid hours this locked run did not record (prepared before G2b).
        lateHoursUnknown: number;
        lateAdjustment: {
            id: string | null;
            draft: boolean;
            payGroupId: string;
            month: string;
        } | null;
        canEdit: boolean;
        sendRefusal: string | null;
        draftRefusal: string | null;
        steps: Record<Step, { refusal: string | null }>;
        options: {
            itemTypes: Option[];
            manualReasons: Option[];
            returnReasons: Option[];
        };
    };
};

export function usePayrollRunPage(props: PayrollRunPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.payroll ?? errors.value.records,
    );
    const busy = ref(false);
    const post = (
        step: string,
        data: Record<string, unknown>,
        done?: () => void,
    ) => {
        busy.value = true;
        router.post(
            `/hr/payroll/runs/${props.run.id}/${step}`,
            {
                ...data,
                expected_lock_version: props.run.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            {
                preserveScroll: true,
                onSuccess: () => done?.(),
                onFinish: () => (busy.value = false),
            },
        );
    };
    const sections: { key: Line['section']; label: string }[] = [
        { key: 'earnings', label: 'Earnings' },
        { key: 'deduction', label: 'Deductions' },
        { key: 'refund', label: 'Refunds' },
        { key: 'employee', label: 'Employee contributions' },
        { key: 'mtd', label: 'MTD (PCB)' },
        { key: 'employer', label: 'Employer contributions (information)' },
    ];

    // HR-7d: an employer-only scheme (the HRD levy) has no employee part, so its zero employee line is not shown (Daniel).
    const shownLines = (lines: Line[], section: Line['section']) =>
        lines.filter(
            (l) =>
                l.section === section &&
                !(
                    l.amountSen === 0 &&
                    l.explanation.startsWith('HRD levy employee')
                ),
        );
    // HR-7d: which MTD figure counts, said next to the figure (Daniel).
    const mtdNote = (lines: Line[]): string | null => {
        const line = lines.find((l) => l.section === 'mtd');

        if (!line) {
            return null;
        }

        if (line.explanation.includes('(worked out:')) {
            return "The typed figure counts for this run. KPOne's worked-out figure is in brackets for comparison. Check both against LHDN's published schedule.";
        }

        return line.explanation.startsWith('MTD (PCB) · worked out')
            ? "Worked out by KPOne. Check it against LHDN's published schedule before real use."
            : null;
    };

    // ---- one person's lines ----
    const panel = reactive({ person: null as RunPerson | null, mtd: '' });
    const openPerson = (p: RunPerson) =>
        Object.assign(panel, { person: p, mtd: rmText(p.mtdSen) });
    const current = computed(
        () =>
            props.run.people.find((p) => p.id === panel.person?.id) ??
            panel.person,
    );
    const saveMtd = () =>
        current.value &&
        post('mtd', { worker_id: current.value.id, mtd: panel.mtd });
    const manual = reactive({ type: '', amount: '', reason: '' });
    const addManual = () =>
        current.value &&
        post(
            'manual',
            {
                worker_id: current.value.id,
                item_type_id: manual.type,
                amount: manual.amount,
                reason_code: manual.reason,
            },
            () => Object.assign(manual, { type: '', amount: '', reason: '' }),
        );
    const manualLines = computed(() =>
        (current.value?.lines ?? []).filter((l) => l.kind === 'manual'),
    );
    const removeManual = (index: number) => {
        const line = current.value?.manual[index];

        if (!line) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/payroll/runs/${props.run.id}/manual/${line.id}/remove`,
            {
                expected_lock_version: props.run.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            { preserveScroll: true, onFinish: () => (busy.value = false) },
        );
    };

    // ---- steps ----
    const asking = ref<'send' | Step | null>(null);
    const returnReason = ref('');
    const addLate = (item: LateItem) =>
        item.kind === 'overtime'
            ? post(`late/${item.id}`, {})
            : post(`late/${item.id}`, { kind: item.kind });
    const removeLate = (item: LateItem) =>
        item.itemId &&
        (item.kind === 'overtime'
            ? post(`late/${item.itemId}/remove`, {})
            : post(`late/${item.itemId}/remove`, { kind: item.kind }));
    // G2b: the card keeps its overtime words while it holds only overtime.
    const lateOnlyOvertime = computed(() =>
        props.run.late.every((item) => item.kind === 'overtime'),
    );
    // A locked regular run with late overtime and no open adjustment run: start one from here (Daniel, point 4).
    const startAdjustment = () => {
        const target = props.run.lateAdjustment;

        if (!target) {
            return;
        }

        busy.value = true;
        router.post(
            '/hr/payroll/runs',
            {
                pay_group_id: target.payGroupId,
                month: target.month,
                kind: 'adjustment',
                idempotency_key: crypto.randomUUID(),
            },
            { onFinish: () => (busy.value = false) },
        );
    };

    const confirm = () => {
        const step = asking.value;

        if (!step) {
            return;
        }

        post(
            step,
            step === 'return' ? { reason: returnReason.value } : {},
            () => (asking.value = null),
        );
    };
    const dialog = computed(() => {
        switch (asking.value) {
            case 'send':
                return {
                    title: 'Send this run for approval?',
                    description: `${props.run.group} · ${props.run.month}. Sending freezes it: nothing in it changes unless it is returned.`,
                    label: 'Send for approval',
                };
            case 'approve':
                return {
                    title: 'Approve this run?',
                    description: `${props.run.group} · ${props.run.month} · net ${myr(props.run.total.net)}. You then lock it to finish.`,
                    label: 'Approve',
                };
            case 'return':
                return {
                    title: 'Return this run to draft?',
                    description:
                        'The preparer can change it and send it again. The reason is kept.',
                    label: 'Return to draft',
                };
            case 'lock':
                return {
                    title: 'Lock this run?',
                    description:
                        'A locked run never changes; a correction is an adjustment run. Staff claims in this run are recorded as paid through payroll.',
                    label: 'Lock run',
                };
            default:
                return { title: '', description: '', label: '' };
        }
    });
    const statusTone = computed(() =>
        props.run.status === 'approved' || props.run.status === 'locked'
            ? 'success'
            : 'neutral',
    );

    return {
        page,
        errors,
        failure,
        busy,
        post,
        sections,
        shownLines,
        mtdNote,
        panel,
        openPerson,
        current,
        saveMtd,
        manual,
        addManual,
        manualLines,
        removeManual,
        asking,
        returnReason,
        addLate,
        removeLate,
        lateOnlyOvertime,
        startAdjustment,
        confirm,
        dialog,
        statusTone,
    };
}
