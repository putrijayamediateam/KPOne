import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * HR-10b /hr/appraisals page state: your appraisals, cycles and criteria. Every request, lock version and idempotency
 * key lives here, shared by the Classic page (`pages/HR/Appraisals.vue`) and Version 2 (`pages/HR/V2/Appraisals.vue`),
 * so both send identical requests. Moved out of the Classic page unchanged; the Classic template did not change.
 * No ranking and no total score: each criterion is rated on its own.
 */
export type Tab = 'mine' | 'cycles' | 'criteria';
export type Status = 'draft' | 'sent' | 'signed_off';
export type Criterion = { title: string; description: string };
export type Cycle = {
    id: string;
    name: string;
    period: string;
    status: 'open' | 'closed';
    lockVersion: number;
    draft: number;
    sent: number;
    signedOff: number;
};
export type Person = {
    id: string;
    name: string;
    status: Status;
    statusLabel: string;
    commented: boolean;
    refusal: string | null;
};

export type AppraisalsPageProps = {
    appraisals: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        mine: {
            id: string;
            cycle: string;
            period: string;
            status: Status;
            statusLabel: string;
            sentOn: string | null;
            commented: boolean;
        }[];
        criteria: { version: number; items: Criterion[] } | null;
        maxCriteria: number;
        cycles: Cycle[];
        cycle: {
            id: string;
            name: string;
            period: string;
            status: 'open' | 'closed';
            people: Person[];
        } | null;
        workers: { id: string; name: string }[];
        today: string;
    };
};

export function useAppraisalsPage(props: AppraisalsPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () =>
            errors.value.appraisal ??
            errors.value.records ??
            errors.value.cycle ??
            errors.value.criteria,
    );
    const tabLabels: Record<Tab, string> = {
        mine: 'My appraisals',
        cycles: 'Cycles',
        criteria: 'Criteria',
    };
    const tabs = computed(() =>
        props.appraisals.tabs.map((value) => ({
            value,
            label: tabLabels[value],
        })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/appraisals', { tab: value }, { preserveScroll: true });
    const tone = (status: Status) =>
        status === 'signed_off'
            ? 'success'
            : status === 'sent'
              ? 'accent'
              : 'neutral';

    // ---- Criteria ----
    const criteriaForm = reactive({
        items: [] as Criterion[],
        key: '',
        processing: false,
        editing: false,
    });
    const startCriteria = () => {
        Object.assign(criteriaForm, {
            items: (props.appraisals.criteria?.items ?? []).map((c) => ({
                ...c,
            })),
            key: crypto.randomUUID(),
            editing: true,
        });

        if (!criteriaForm.items.length) {
            criteriaForm.items.push({ title: '', description: '' });
        }
    };
    const addCriterion = () =>
        criteriaForm.items.push({ title: '', description: '' });
    const removeCriterion = (i: number) => criteriaForm.items.splice(i, 1);
    const saveCriteria = () => {
        criteriaForm.processing = true;
        router.post(
            '/hr/appraisals/criteria',
            { criteria: criteriaForm.items, idempotency_key: criteriaForm.key },
            {
                preserveScroll: true,
                onSuccess: () => (criteriaForm.editing = false),
                onFinish: () => (criteriaForm.processing = false),
            },
        );
    };

    // ---- Cycles ----
    const cycleForm = reactive({
        open: false,
        name: '',
        start: '',
        end: '',
        workers: [] as string[],
        key: '',
        processing: false,
    });
    const startCycle = () =>
        Object.assign(cycleForm, {
            open: true,
            name: '',
            start: '',
            end: '',
            workers: [],
            key: crypto.randomUUID(),
        });
    const allChosen = computed(
        () =>
            props.appraisals.workers.length > 0 &&
            cycleForm.workers.length === props.appraisals.workers.length,
    );
    const toggleAll = () =>
        (cycleForm.workers = allChosen.value
            ? []
            : props.appraisals.workers.map((w) => w.id));
    const saveCycle = () => {
        cycleForm.processing = true;
        router.post(
            '/hr/appraisals/cycles',
            {
                name: cycleForm.name,
                period_start: cycleForm.start,
                period_end: cycleForm.end,
                worker_ids: cycleForm.workers,
                idempotency_key: cycleForm.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (cycleForm.open = false),
                onFinish: () => (cycleForm.processing = false),
            },
        );
    };
    const openCycle = (id: string) =>
        router.get(
            '/hr/appraisals',
            { tab: 'cycles', cycle: id },
            { preserveScroll: true },
        );
    const closing = ref<Cycle | null>(null);
    const closeKey = ref('');
    const closeBusy = ref(false);
    const askClose = (cycle: Cycle) => {
        closing.value = cycle;
        closeKey.value = crypto.randomUUID();
    };
    const sendClose = () => {
        const cycle = closing.value;

        if (!cycle) {
            return;
        }

        closeBusy.value = true;
        router.post(
            `/hr/appraisals/cycles/${cycle.id}/close`,
            {
                expected_lock_version: cycle.lockVersion,
                idempotency_key: closeKey.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => (closing.value = null),
                onFinish: () => (closeBusy.value = false),
            },
        );
    };
    const textareaClass =
        'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';

    return {
        errors,
        failure,
        tabs,
        selectTab,
        tone,
        criteriaForm,
        startCriteria,
        addCriterion,
        removeCriterion,
        saveCriteria,
        cycleForm,
        startCycle,
        allChosen,
        toggleAll,
        saveCycle,
        openCycle,
        closing,
        closeBusy,
        askClose,
        sendClose,
        textareaClass,
    };
}
