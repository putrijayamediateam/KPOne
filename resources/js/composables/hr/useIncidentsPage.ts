import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * HR-10d /hr/incidents page state: report an incident, and the lists of reports. Every request and idempotency key of
 * `pages/HR/Incidents.vue` lives here. The lists carry no description or note text; a report's text is only on the
 * report page.
 */
export type Tab = 'mine' | 'open' | 'clinical' | 'closed';
export type Row = {
    id: string;
    category: string;
    categoryLabel: string;
    branch: string;
    occurredAt: string | null;
    status: 'reported' | 'acknowledged' | 'closed';
    statusLabel: string;
    needsClinicalReview: boolean;
    directorOnly: boolean;
    reporter: string | null;
    own: boolean;
};

export type IncidentsPageProps = {
    incidents: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        canReport: boolean;
        counts: Record<Tab, number>;
        mine: Row[];
        reports: Row[];
        branches: { id: string; name: string }[];
        categories: { value: string; label: string }[];
        maxText: number;
        maxPhotos: number;
        today: string;
    };
};

export function useIncidentsPage(props: IncidentsPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.incident ?? errors.value.records,
    );
    const tabLabels: Record<Tab, string> = {
        mine: 'My reports',
        open: 'Open',
        clinical: 'Clinical review',
        closed: 'Closed',
    };
    const tabs = computed(() =>
        props.incidents.tabs.map((value) => ({
            value,
            label: `${tabLabels[value]} (${props.incidents.counts[value]})`,
        })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/incidents', { tab: value }, { preserveScroll: true });
    const rows = computed(() =>
        props.incidents.tab === 'mine'
            ? props.incidents.mine
            : props.incidents.reports,
    );
    const tone = (status: Row['status']) =>
        status === 'reported'
            ? 'warning'
            : status === 'acknowledged'
              ? 'accent'
              : 'neutral';

    // ---- Report ----
    const chooser = ref<{ clear: () => void } | null>(null);
    const form = reactive({
        open: false,
        branch: '',
        occurredAt: '',
        category: '',
        directorOnly: false,
        description: '',
        photos: [] as File[],
        key: crypto.randomUUID(),
        processing: false,
    });
    const startReport = () => {
        Object.assign(form, {
            open: true,
            branch: props.incidents.branches[0]?.id ?? '',
            occurredAt: '',
            category: '',
            directorOnly: false,
            description: '',
            photos: [],
            key: crypto.randomUUID(),
        });
    };
    const addPhoto = (event: Event) => {
        const file = (event.target as HTMLInputElement).files?.[0];

        if (file && form.photos.length < props.incidents.maxPhotos) {
            form.photos.push(file);
        }

        chooser.value?.clear();
    };
    const missing = computed(() =>
        !form.category
            ? 'Choose what kind of incident it was.'
            : !form.occurredAt
              ? 'Choose the date and time it happened.'
              : form.description.trim().length < 10
                ? 'Describe what happened first.'
                : null,
    );
    const submit = () => {
        form.processing = true;
        router.post(
            '/hr/incidents',
            {
                branch_id: form.branch,
                occurred_on: form.occurredAt.slice(0, 10),
                occurred_time: form.occurredAt.slice(11, 16),
                category: form.category,
                director_only:
                    form.category === 'staff_conduct' && form.directorOnly
                        ? 1
                        : 0,
                description: form.description,
                photos: form.photos,
                idempotency_key: form.key,
            },
            {
                forceFormData: true,
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
        rows,
        tone,
        chooser,
        form,
        startReport,
        addPhoto,
        missing,
        submit,
        textareaClass,
    };
}
