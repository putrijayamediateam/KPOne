import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * HR-10d one incident report page state: acknowledge, notes, actions, linking a discipline case and closing. Every
 * request, lock version and idempotency key of `pages/HR/Incident.vue` lives here. The report's text, notes and outcome
 * come in the props (the server decides who may read them); photos open only through the existing links.
 */
export type Status = 'reported' | 'acknowledged' | 'closed';
export type Action = {
    id: string;
    description: string | null;
    owner: string;
    dueOn: string;
    overdue: boolean;
    done: boolean;
    doneOn: string | null;
    doneBy: string | null;
    lockVersion: number;
};

export type IncidentPageProps = {
    incident: {
        id: string;
        manager: boolean;
        category: string;
        categoryLabel: string;
        needsClinicalReview: boolean;
        directorOnly: boolean;
        branch: string;
        occurredAt: string | null;
        reportedOn: string | null;
        description: string | null;
        photos: { id: string; kind: string; bytes: number }[];
        status: Status;
        statusLabel: string;
        acknowledgedOn: string | null;
        closedOn: string | null;
        lockVersion: number;
        reporter?: string;
        acknowledgedBy?: string | null;
        closedBy?: string | null;
        outcome?: string | null;
        notes?: { body: string | null; by: string; on: string | null }[];
        actions?: Action[];
        owners?: { id: number; name: string }[];
        link?: {
            linked: boolean;
            caseId: string | null;
            options: { value: string; label: string }[];
            canLink: boolean;
        } | null;
        can?: {
            handle: boolean;
            acknowledge: boolean;
            add: boolean;
            close: boolean;
        };
        ownRefusal?: string | null;
        maxNote?: number;
        today?: string;
    };
};

export function useIncidentPage(props: IncidentPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.incident ?? errors.value.records,
    );
    const r = computed(() => props.incident);
    const base = computed(() => `/hr/incidents/${r.value.id}`);
    const tone = computed(() =>
        r.value.status === 'reported'
            ? 'warning'
            : r.value.status === 'acknowledged'
              ? 'accent'
              : 'neutral',
    );
    const textareaClass =
        'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
    const post = (
        path: string,
        data: Record<string, string | number | null>,
        busy: { processing: boolean },
        done?: () => void,
    ) => {
        busy.processing = true;
        router.post(`${base.value}/${path}`, data, {
            preserveScroll: true,
            onSuccess: () => done?.(),
            onFinish: () => (busy.processing = false),
        });
    };

    const ack = reactive({ key: crypto.randomUUID(), processing: false });
    const acknowledge = () =>
        post(
            'acknowledge',
            {
                expected_lock_version: r.value.lockVersion,
                idempotency_key: ack.key,
            },
            ack,
        );

    const note = reactive({
        text: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const addNote = () =>
        post(
            'notes',
            { note: note.text, idempotency_key: note.key },
            note,
            () => {
                note.text = '';
                note.key = crypto.randomUUID();
            },
        );

    const action = reactive({
        text: '',
        owner: '',
        due: props.incident.today ?? '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const actionMissing = computed(() =>
        action.text.trim().length < 3
            ? 'Describe the action first.'
            : !action.owner
              ? 'Choose who will do it.'
              : null,
    );
    const addAction = () =>
        post(
            'actions',
            {
                description: action.text,
                owner_id: action.owner,
                due_on: action.due,
                idempotency_key: action.key,
            },
            action,
            () => {
                action.text = '';
                action.owner = '';
                action.key = crypto.randomUUID();
            },
        );
    const doneBusy = reactive({ processing: false });
    const markDone = (a: Action) =>
        post(
            `actions/${a.id}/done`,
            {
                expected_lock_version: a.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            doneBusy,
        );

    const link = reactive({
        caseId: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const linkCase = () =>
        post(
            'link',
            {
                case_id: link.caseId,
                expected_lock_version: r.value.lockVersion,
                idempotency_key: link.key,
            },
            link,
        );

    const closing = reactive({
        open: false,
        outcome: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const openActions = computed(
        () => (r.value.actions ?? []).filter((a) => !a.done).length,
    );
    const close = () =>
        post(
            'close',
            {
                outcome: closing.outcome,
                expected_lock_version: r.value.lockVersion,
                idempotency_key: closing.key,
            },
            closing,
            () => (closing.open = false),
        );

    return {
        errors,
        failure,
        r,
        base,
        tone,
        textareaClass,
        ack,
        acknowledge,
        note,
        addNote,
        action,
        actionMissing,
        addAction,
        doneBusy,
        markDone,
        link,
        linkCase,
        closing,
        openActions,
        close,
    };
}
