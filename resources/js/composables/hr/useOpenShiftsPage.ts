import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * The Open shifts page's state and actions (HR-15b) for `pages/HR/OpenShifts.vue`: every request, lock version and
 * idempotency key.
 */
export type Tab = 'available' | 'mine' | 'open' | 'closed';
export type Option = { value: string; label: string };
export type Shift = {
    id: string;
    branch: string;
    when: string;
    sessionLabel: string;
    roleLabel: string;
    breakMinutes: number;
    status: 'open' | 'filled' | 'withdrawn';
    lockVersion: number;
};
export type Ask = {
    id: string;
    locum: string;
    askedOn: string;
    status: 'asked' | 'confirmed' | 'declined' | 'withdrawn';
    statusLabel: string;
    own: boolean;
    lockVersion: number;
};
export type ManagedShift = Shift & {
    started: boolean;
    needsPosting: boolean;
    asks: Ask[];
};
export type MyAsk = Shift & {
    requestId: string;
    requestStatus: Ask['status'];
    requestStatusLabel: string;
    requestLockVersion: number;
};

export type OpenShiftsPageProps = {
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
};

export function useOpenShiftsPage(props: OpenShiftsPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.open_shift ?? errors.value.records,
    );
    const tabLabels: Record<Tab, string> = {
        available: 'Open shifts',
        mine: 'My asks',
        open: 'Open',
        closed: 'Filled and withdrawn',
    };
    const tabs = computed(() =>
        props.openShifts.tabs.map((value) => ({
            value,
            label: tabLabels[value],
        })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/open-shifts', { tab: value }, { preserveScroll: true });
    const managed = computed(() =>
        props.openShifts.tab === 'closed'
            ? props.openShifts.closed
            : props.openShifts.open,
    );
    const shiftTone = (status: Shift['status']) =>
        status === 'open'
            ? 'accent'
            : status === 'filled'
              ? 'success'
              : 'neutral';
    const askTone = (status: Ask['status']) =>
        status === 'confirmed'
            ? 'success'
            : status === 'asked'
              ? 'warning'
              : 'neutral';

    const post = (
        url: string,
        data: Record<string, unknown>,
        done?: () => void,
    ) =>
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
    const confirming = reactive<{
        ask: Ask | null;
        shift: ManagedShift | null;
    }>({
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
    const withdrawing = reactive<{ shift: ManagedShift | null }>({
        shift: null,
    });
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

    return {
        errors,
        failure,
        tabs,
        selectTab,
        managed,
        shiftTone,
        askTone,
        busy,
        form,
        startPost,
        missing,
        submit,
        confirming,
        confirm,
        declining,
        decline,
        withdrawing,
        withdraw,
        ask,
        withdrawAsk,
    };
}
