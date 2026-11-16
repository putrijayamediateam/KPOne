import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * HR-10c /hr/discipline page state: the list of restricted cases and opening one. Every request and idempotency key
 * lives here, shared by the Classic page (`pages/HR/Discipline.vue`) and Version 2 (`pages/HR/V2/Discipline.vue`), so
 * both send identical requests. Moved out of the Classic page unchanged; the Classic template did not change. The list
 * carries no allegation or note; those are shown only on the case page, after the view is recorded.
 */
export type Tab = 'open' | 'closed';
export type CaseRow = {
    id: string;
    person: string;
    incidentOn: string;
    openedOn: string | null;
    status: Tab;
    outcome: string | null;
    closedOn: string | null;
    entries: number;
};

export type DisciplinePageProps = {
    discipline: {
        tab: Tab;
        counts: Record<Tab, number>;
        cases: CaseRow[];
        workers: { id: string; name: string }[];
        maxAllegation: number;
        today: string;
    };
};

export function useDisciplinePage(props: DisciplinePageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(() => errors.value.case ?? errors.value.records);
    const tabs = computed(() => [
        { value: 'open', label: `Open (${props.discipline.counts.open})` },
        {
            value: 'closed',
            label: `Closed (${props.discipline.counts.closed})`,
        },
    ]);
    const selectTab = (value: string | number) =>
        router.get('/hr/discipline', { tab: value }, { preserveScroll: true });

    const form = reactive({
        open: false,
        worker: '',
        incidentOn: '',
        allegation: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const startCase = () => {
        form.worker = '';
        // The date of an allegation is a fact the person states, so it starts empty (owner decision, 2026-11-16).
        form.incidentOn = '';
        form.allegation = '';
        form.key = crypto.randomUUID();
        form.open = true;
    };
    const submit = () => {
        form.processing = true;
        router.post(
            '/hr/discipline',
            {
                worker_id: form.worker,
                incident_on: form.incidentOn,
                allegation: form.allegation,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (form.open = false),
                onFinish: () => (form.processing = false),
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
        form,
        startCase,
        submit,
        textareaClass,
    };
}
