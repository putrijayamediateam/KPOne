import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * HR-10b one appraisal page state: rating and sending (manager), the person's one comment, and the Director's sign-off.
 * Every request, lock version and idempotency key lives here, shared by the Classic page (`pages/HR/Appraisal.vue`) and
 * Version 2 (`pages/HR/V2/Appraisal.vue`), so both send identical requests. Moved out of the Classic page unchanged; the
 * Classic template did not change. No total score.
 */
export type Summary = {
    late: number;
    lateMinutes: number;
    noClockIn: number;
    noClockOut: number;
    leaveDays: number | null;
    overtimeMinutes: number;
    asOf?: string;
    partial?: boolean;
    rulesMissing?: boolean;
};

export type AppraisalPageProps = {
    appraisal: {
        id: string;
        own: boolean;
        person: string;
        cycle: { id: string; name: string; period: string; status: string };
        status: 'draft' | 'sent' | 'signed_off';
        statusLabel: string;
        criteria: { title: string; description: string }[];
        ratings: { rating: number | null; comment: string }[];
        summary: Summary | null;
        summaryLive: boolean;
        appraiser: string | null;
        sentOn: string | null;
        staffComment: string | null;
        staffCommentedOn: string | null;
        signedOffBy: string | null;
        sameSigner: boolean;
        ratedByYou: boolean;
        signedOffOn: string | null;
        signOffFrom: string | null;
        commentDays: number;
        lockVersion: number;
        maxComment: number;
        can: { edit: boolean; signOff: boolean; comment: boolean };
    };
};

export function useAppraisalPage(props: AppraisalPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () =>
            errors.value.appraisal ??
            errors.value.records ??
            errors.value.ratings,
    );
    const back = computed(() =>
        props.appraisal.own
            ? '/hr/appraisals?tab=mine'
            : `/hr/appraisals?tab=cycles&cycle=${props.appraisal.cycle.id}`,
    );
    const day = (iso: string | undefined) =>
        iso
            ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : '';
    const hours = (minutes: number) =>
        minutes < 60
            ? `${minutes} min`
            : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

    // ---- Rating (manager, draft) ----
    const form = reactive({
        ratings: props.appraisal.ratings.map((r) => ({ ...r })),
        key: crypto.randomUUID(),
        processing: false,
    });
    const unrated = computed(
        () => form.ratings.filter((r) => r.rating === null).length,
    );
    const confirmSend = ref(false);
    const submit = (send: boolean) => {
        confirmSend.value = false;
        form.processing = true;
        router.post(
            `/hr/appraisals/${props.appraisal.id}`,
            {
                ratings: form.ratings,
                send,
                expected_lock_version: props.appraisal.lockVersion,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (form.key = crypto.randomUUID()),
                onFinish: () => (form.processing = false),
            },
        );
    };

    // ---- Staff comment ----
    const comment = reactive({
        text: '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const sendComment = () => {
        comment.processing = true;
        router.post(
            `/hr/appraisals/${props.appraisal.id}/comment`,
            {
                comment: comment.text,
                expected_lock_version: props.appraisal.lockVersion,
                idempotency_key: comment.key,
            },
            {
                preserveScroll: true,
                onFinish: () => (comment.processing = false),
            },
        );
    };

    // ---- Sign off (Director) ----
    const confirmSignOff = ref(false);
    const signKey = crypto.randomUUID();
    const signBusy = ref(false);
    const signOff = () => {
        signBusy.value = true;
        router.post(
            `/hr/appraisals/${props.appraisal.id}/sign-off`,
            {
                expected_lock_version: props.appraisal.lockVersion,
                idempotency_key: signKey,
            },
            {
                preserveScroll: true,
                onSuccess: () => (confirmSignOff.value = false),
                onFinish: () => (signBusy.value = false),
            },
        );
    };
    const textareaClass =
        'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';

    return {
        errors,
        failure,
        back,
        day,
        hours,
        form,
        unrated,
        confirmSend,
        submit,
        comment,
        sendComment,
        confirmSignOff,
        signBusy,
        signOff,
        textareaClass,
    };
}
