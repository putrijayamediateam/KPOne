import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * HR-10c one restricted discipline case page state: notes, the staff member's reply, linking a show-cause letter, files
 * and the one outcome. Every request, lock version and idempotency key of `pages/HR/DisciplineCase.vue` lives here.
 * Opening the page is recorded by the server before anything is sent; the allegation, entries and outcome come in the
 * props only for people the server allows. Files open only through the existing recorded links.
 */
export type LetterRef = { id: string; reference: string; void: boolean } | null;
export type LetterOption = {
    id: string;
    reference: string;
    issuedOn: string | null;
};
export type Entry = {
    kind: string;
    label: string;
    body: string | null;
    occurredOn: string | null;
    letter: LetterRef;
    file: { id: string; kind: string; bytes: number } | null;
    by: string;
    on: string | null;
};

export type DisciplineCasePageProps = {
    case: {
        id: string;
        person: string;
        status: 'open' | 'closed';
        allegation: string | null;
        incidentOn: string;
        openedBy: string;
        openedOn: string | null;
        outcome: {
            code: string;
            label: string;
            letter: LetterRef;
            note: string | null;
            by: string;
            on: string | null;
        } | null;
        timeline: Entry[];
        showCauseLetters: LetterOption[];
        warningLetters: LetterOption[];
        outcomes: { code: string; label: string; needsLetter: boolean }[];
        fileCount: number;
        limits: {
            note: number;
            reply: number;
            outcomeNote: number;
            files: number;
        };
        lockVersion: number;
        today: string;
    };
};

export function useDisciplineCasePage(props: DisciplineCasePageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(() => errors.value.case ?? errors.value.records);
    const isOpen = computed(() => props.case.status === 'open');
    const base = computed(() => `/hr/discipline/${props.case.id}`);
    const size = (bytes: number) =>
        bytes < 1024 * 1024
            ? `${Math.max(1, Math.round(bytes / 1024))} KB`
            : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    const textareaClass =
        'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
    const post = (
        path: string,
        data: Record<string, FormDataConvertible>,
        done: () => void,
        busy: { processing: boolean },
        forceFormData = false,
    ) => {
        busy.processing = true;
        router.post(`${base.value}/${path}`, data, {
            preserveScroll: true,
            forceFormData,
            onSuccess: done,
            onFinish: () => (busy.processing = false),
        });
    };

    // ---- Note ----
    const note = reactive({
        text: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const addNote = () =>
        post(
            'notes',
            { note: note.text, idempotency_key: note.key },
            () => {
                note.text = '';
                note.key = crypto.randomUUID();
            },
            note,
        );

    // ---- Reply ----
    const reply = reactive({
        text: '',
        receivedOn: props.case.today,
        key: crypto.randomUUID(),
        processing: false,
    });
    const addReply = () =>
        post(
            'replies',
            {
                reply: reply.text,
                received_on: reply.receivedOn,
                idempotency_key: reply.key,
            },
            () => {
                reply.text = '';
                reply.key = crypto.randomUUID();
            },
            reply,
        );

    // ---- Show-cause letter ----
    const link = reactive({
        letter: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const linkLetter = () =>
        post(
            'show-cause',
            { letter_id: link.letter, idempotency_key: link.key },
            () => {
                link.letter = '';
                link.key = crypto.randomUUID();
            },
            link,
        );

    // ---- File ----
    const chooser = ref<{ clear: () => void } | null>(null);
    const upload = reactive({
        file: null as File | null,
        key: crypto.randomUUID(),
        processing: false,
    });
    const addFile = () =>
        post(
            'files',
            { file: upload.file, idempotency_key: upload.key },
            () => {
                upload.file = null;
                upload.key = crypto.randomUUID();

                chooser.value?.clear();
            },
            upload,
            true,
        );

    // ---- Outcome ----
    const outcome = reactive({
        open: false,
        code: '',
        letter: '',
        note: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const chosen = computed(() =>
        props.case.outcomes.find((o) => o.code === outcome.code),
    );
    const closeBlocked = computed(() =>
        !outcome.code
            ? 'Choose the outcome first.'
            : chosen.value?.needsLetter && !outcome.letter
              ? props.case.warningLetters.length
                  ? 'Choose the warning letter first.'
                  : 'Issue the warning letter in HR letters first.'
              : null,
    );
    // The shared confirm dialog has no disabled state, so a blocked confirm shows its reason instead of sending.
    const blockedShown = ref(false);
    const close = () => {
        if (closeBlocked.value) {
            blockedShown.value = true;

            return;
        }

        post(
            'close',
            {
                outcome: outcome.code,
                letter_id: chosen.value?.needsLetter ? outcome.letter : null,
                note: outcome.note,
                expected_lock_version: props.case.lockVersion,
                idempotency_key: outcome.key,
            },
            () => (outcome.open = false),
            outcome,
        );
    };

    return {
        errors,
        failure,
        isOpen,
        base,
        size,
        textareaClass,
        note,
        addNote,
        reply,
        addReply,
        link,
        linkLetter,
        chooser,
        upload,
        addFile,
        outcome,
        chosen,
        closeBlocked,
        blockedShown,
        close,
    };
}
