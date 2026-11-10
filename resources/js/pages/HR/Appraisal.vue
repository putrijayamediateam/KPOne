<script setup lang="ts">
// HR-10b one appraisal: the manager rates each criterion 1 to 5 with a comment and sends it; the person reads it and may
// add one comment; a Director signs it off. The attendance, leave and overtime summary is read only (live while a draft,
// frozen once sent). No total score.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';

type Summary = {
    late: number;
    lateMinutes: number;
    noClockIn: number;
    noClockOut: number;
    leaveDays: number | null;
    overtimeMinutes: number;
};

const props = defineProps<{
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
        signedOffOn: string | null;
        lockVersion: number;
        maxComment: number;
        can: { edit: boolean; signOff: boolean; comment: boolean };
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(
    () =>
        errors.value.appraisal ?? errors.value.records ?? errors.value.ratings,
);
const back = computed(() =>
    props.appraisal.own
        ? '/hr/appraisals?tab=mine'
        : `/hr/appraisals?tab=cycles&cycle=${props.appraisal.cycle.id}`,
);
const hours = (minutes: number) =>
    `${Math.floor(minutes / 60)} h ${minutes % 60} min`;

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
        { preserveScroll: true, onFinish: () => (comment.processing = false) },
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
</script>

<template>
    <Head :title="`Appraisal · ${appraisal.person}`" />
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <Link :href="back" class="text-sm underline"
                >Back to appraisals</Link
            >
            <PageHeader
                eyebrow="KPOne HR · appraisal"
                :title="appraisal.person"
                :description="`${appraisal.cycle.name} · ${appraisal.cycle.period}`"
            >
                <template #title-extra>
                    <StatusBadge
                        :status="appraisal.status"
                        :label="appraisal.statusLabel"
                        :tone="
                            appraisal.status === 'signed_off'
                                ? 'success'
                                : appraisal.status === 'sent'
                                  ? 'accent'
                                  : 'neutral'
                        "
                    />
                </template>
            </PageHeader>
            <SyntheticNote />
        </div>

        <ErrorState
            v-if="failure && !confirmSend && !confirmSignOff"
            :message="failure"
        />

        <p v-if="appraisal.appraiser" class="text-sm text-muted-foreground">
            Rated by {{ appraisal.appraiser }}, sent {{ appraisal.sentOn
            }}<template v-if="appraisal.signedOffBy">
                · signed off by {{ appraisal.signedOffBy }} on
                {{ appraisal.signedOffOn }}</template
            >.
        </p>

        <!-- Read-only summary for the cycle's dates -->
        <section
            aria-labelledby="summary-title"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            data-test="appraisal-summary"
        >
            <h2 id="summary-title" class="text-sm font-semibold">
                Attendance, leave and overtime in this period
            </h2>
            <p class="text-xs text-muted-foreground">
                {{
                    appraisal.summaryLive
                        ? 'Worked out now from KPOne HR; it is frozen when the appraisal is sent.'
                        : 'As it was when the appraisal was sent.'
                }}
            </p>
            <dl
                v-if="appraisal.summary"
                class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
            >
                <div class="flex justify-between gap-2">
                    <dt>Late days</dt>
                    <dd class="tabular-nums">
                        {{ appraisal.summary.late }} ({{
                            hours(appraisal.summary.lateMinutes)
                        }})
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>No clock-in</dt>
                    <dd class="tabular-nums">
                        {{ appraisal.summary.noClockIn }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>No clock-out</dt>
                    <dd class="tabular-nums">
                        {{ appraisal.summary.noClockOut }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Approved leave</dt>
                    <dd class="tabular-nums">
                        {{
                            appraisal.summary.leaveDays === null
                                ? 'Cannot be counted (working days not set up)'
                                : `${appraisal.summary.leaveDays} days`
                        }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Approved overtime</dt>
                    <dd class="tabular-nums">
                        {{ hours(appraisal.summary.overtimeMinutes) }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Ratings -->
        <form
            class="flex flex-col gap-3"
            data-test="appraisal-ratings"
            @submit.prevent="confirmSend = true"
        >
            <fieldset
                v-for="(c, i) in appraisal.criteria"
                :key="i"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                :aria-describedby="c.description ? `crit-help-${i}` : undefined"
            >
                <legend class="sr-only">{{ c.title }}</legend>
                <p class="font-medium" aria-hidden="true">
                    {{ i + 1 }}. {{ c.title }}
                </p>
                <p
                    v-if="c.description"
                    :id="`crit-help-${i}`"
                    class="text-sm text-muted-foreground"
                >
                    {{ c.description }}
                </p>
                <template v-if="appraisal.can.edit">
                    <div
                        class="flex flex-wrap gap-2"
                        role="radiogroup"
                        :aria-label="`Rating for ${c.title}, 1 to 5`"
                    >
                        <label
                            v-for="n in 5"
                            :key="n"
                            class="flex min-h-11 min-w-11 cursor-pointer items-center justify-center gap-1 rounded-md border px-3 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand/10 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring lg:min-h-9"
                        >
                            <input
                                v-model="form.ratings[i]!.rating"
                                type="radio"
                                :name="`rating-${i}`"
                                :value="n"
                                class="sr-only"
                            />
                            {{ n }}
                        </label>
                    </div>
                    <InputError :message="errors[`ratings.${i}.rating`]" />
                    <Label :for="`rating-comment-${i}`"
                        >Comment (optional)</Label
                    >
                    <textarea
                        :id="`rating-comment-${i}`"
                        v-model="form.ratings[i]!.comment"
                        rows="2"
                        maxlength="500"
                        :class="textareaClass"
                        :aria-invalid="
                            errors[`ratings.${i}.comment`] ? 'true' : undefined
                        "
                    ></textarea>
                    <InputError :message="errors[`ratings.${i}.comment`]" />
                </template>
                <template v-else>
                    <p class="text-sm" data-test="appraisal-rating">
                        <span class="font-semibold tabular-nums">{{
                            appraisal.ratings[i]?.rating ?? '–'
                        }}</span>
                        of 5
                    </p>
                    <p
                        v-if="appraisal.ratings[i]?.comment"
                        class="text-sm break-words whitespace-pre-wrap"
                    >
                        {{ appraisal.ratings[i]?.comment }}
                    </p>
                </template>
            </fieldset>
            <p class="text-xs text-muted-foreground">
                1 is the lowest and 5 the highest. Each criterion stands on its
                own; KPOne adds no total.
            </p>
            <div
                v-if="appraisal.can.edit"
                class="flex flex-wrap items-center gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="appraisal-save-draft"
                    @click="submit(false)"
                >
                    Save draft
                </Button>
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing || unrated > 0"
                    :aria-describedby="unrated > 0 ? 'send-hint' : undefined"
                    data-test="appraisal-send"
                    >Send to {{ appraisal.person }}</Button
                >
                <span
                    v-if="unrated > 0"
                    id="send-hint"
                    class="text-sm text-muted-foreground"
                    >Rate every criterion first ({{ unrated }} left).</span
                >
            </div>
        </form>

        <!-- The person's comment -->
        <section
            v-if="appraisal.status !== 'draft'"
            aria-labelledby="staff-comment-title"
            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
            data-test="appraisal-staff-comment"
        >
            <h2 id="staff-comment-title" class="text-sm font-semibold">
                {{
                    appraisal.own
                        ? 'Your comment'
                        : `${appraisal.person}'s comment`
                }}
            </h2>
            <p
                v-if="appraisal.staffComment"
                class="text-sm break-words whitespace-pre-wrap"
            >
                {{ appraisal.staffComment }}
                <span class="block text-xs text-muted-foreground"
                    >Added {{ appraisal.staffCommentedOn }}</span
                >
            </p>
            <form
                v-else-if="appraisal.can.comment"
                class="flex flex-col gap-2"
                @submit.prevent="sendComment"
            >
                <Label for="staff-comment"
                    >Add your comment (optional, once)</Label
                >
                <textarea
                    id="staff-comment"
                    v-model="comment.text"
                    rows="4"
                    :maxlength="appraisal.maxComment"
                    :class="textareaClass"
                    aria-describedby="staff-comment-help"
                    :aria-invalid="errors.comment ? 'true' : undefined"
                ></textarea>
                <p
                    id="staff-comment-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ comment.text.length }} of
                    {{ appraisal.maxComment }} characters. It cannot be changed
                    after you send it.
                </p>
                <InputError :message="errors.comment" />
                <Button
                    type="submit"
                    class="self-start max-lg:min-h-11"
                    :disabled="comment.processing || !comment.text.trim()"
                    data-test="appraisal-comment"
                >
                    {{ comment.processing ? 'Saving…' : 'Add comment' }}
                </Button>
            </form>
            <p v-else class="text-sm text-muted-foreground">No comment.</p>
        </section>

        <div v-if="appraisal.can.signOff">
            <Button
                class="max-lg:min-h-11"
                data-test="appraisal-sign-off"
                @click="confirmSignOff = true"
                >Sign off</Button
            >
        </div>

        <OperationalConfirmDialog
            :open="confirmSend"
            :title="`Send to ${appraisal.person}?`"
            description="The ratings and comments can no longer be changed, and the summary is frozen. The staff member can read it and add a comment."
            confirm-label="Send"
            processing-label="Sending…"
            :processing="form.processing"
            @update:open="(v: boolean) => (confirmSend = v)"
            @confirm="submit(true)"
        />
        <OperationalConfirmDialog
            :open="confirmSignOff"
            title="Sign this appraisal off?"
            :description="
                appraisal.staffComment
                    ? 'It no longer changes after this.'
                    : 'The staff member has not commented yet. After sign-off they can no longer add a comment, and the appraisal no longer changes.'
            "
            confirm-label="Sign off"
            cancel-label="Not yet"
            processing-label="Signing off…"
            :processing="signBusy"
            :error="failure"
            @update:open="(v: boolean) => (confirmSignOff = v)"
            @confirm="signOff"
        />
    </div>
</template>
