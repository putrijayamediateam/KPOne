<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useAppraisalPage } from '@/composables/hr/useAppraisalPage';
import type { AppraisalPageProps } from '@/composables/hr/useAppraisalPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 of one appraisal (shown only in V2; Classic is `pages/HR/Appraisal.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useAppraisalPage`, which Classic uses
 * too, so this page sends none of its own. Each criterion stands on its own; KPOne adds no total and no ranking.
 * Ratings, comments and the summary are the page's own content and come in the props exactly as in Classic; nothing
 * is kept in the browser.
 */
const props = defineProps<AppraisalPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Appraisals', href: '/hr/appraisals' },
    { title: props.appraisal.person },
]);

const {
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
} = useAppraisalPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const statusTone = computed(() =>
    props.appraisal.status === 'signed_off'
        ? 'success'
        : props.appraisal.status === 'sent'
          ? 'info'
          : 'neutral',
);
const scale = ['1', '2', '3', '4', '5'].map((n) => ({ value: n, label: n }));
const ratingModel = (i: number) => {
    const r = form.ratings[i]?.rating;

    return r === null || r === undefined ? null : String(r);
};
const setRating = (i: number, v: string) => {
    const row = form.ratings[i];

    if (row) {
        row.rating = Number(v);
    }
};
const sendReason = computed(() =>
    unrated.value > 0
        ? `Rate every criterion first (${unrated.value} left).`
        : '',
);
const commentReason = computed(() =>
    comment.text.trim() === '' ? 'Write your comment to continue.' : '',
);
const signDescription = computed(
    () =>
        (props.appraisal.ratedByYou
            ? 'You rated this appraisal, so it will show as signed off by the same person. '
            : '') +
        (props.appraisal.staffComment
            ? 'It no longer changes after this.'
            : 'The staff member has not commented yet. After sign-off they can no longer add a comment, and the appraisal no longer changes.'),
);
</script>

<template>
    <Head :title="`Appraisal · ${appraisal.person}`" />
    <PageFrame>
        <V2WorkLayout
            :title="appraisal.person"
            :description="`${appraisal.cycle.name} · ${appraisal.cycle.period}`"
        >
            <template #actions>
                <Link :href="back" :class="button">Back to appraisals</Link>
                <button
                    v-if="appraisal.can.signOff"
                    type="button"
                    :class="primaryButton"
                    data-test="appraisal-sign-off"
                    @click="confirmSignOff = true"
                >
                    Sign off
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <V2Chip
                            :tone="statusTone"
                            :label="appraisal.statusLabel"
                        />
                    </div>
                    <SyntheticNote />
                    <p
                        v-if="failure && !confirmSend && !confirmSignOff"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <p
                        v-if="appraisal.appraiser"
                        class="text-sm text-muted-foreground"
                    >
                        Rated by {{ appraisal.appraiser }}, sent
                        {{ appraisal.sentOn
                        }}<template
                            v-if="appraisal.signedOffBy && appraisal.sameSigner"
                        >
                            · signed off by the same person on
                            {{ appraisal.signedOffOn }}</template
                        ><template v-else-if="appraisal.signedOffBy">
                            · signed off by {{ appraisal.signedOffBy }} on
                            {{ appraisal.signedOffOn }}</template
                        >.
                    </p>

                    <!-- Read-only summary for the cycle's dates -->
                    <section
                        aria-labelledby="v2-summary-title"
                        :class="cardClass"
                        data-test="appraisal-summary"
                    >
                        <h2 id="v2-summary-title" class="text-sm font-semibold">
                            Attendance, leave and overtime in this period
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{
                                appraisal.summaryLive
                                    ? 'Worked out now from KPOne HR; it is frozen when the appraisal is sent.'
                                    : 'As it was when the appraisal was sent.'
                            }}
                        </p>
                        <p
                            v-if="appraisal.summary?.partial"
                            class="text-sm text-muted-foreground"
                            data-test="summary-partial"
                        >
                            Attendance as of {{ day(appraisal.summary.asOf) }}:
                            the period had not ended yet.
                        </p>
                        <p
                            v-if="appraisal.summary?.rulesMissing"
                            class="text-sm text-warning-strong"
                            data-test="summary-rules-missing"
                        >
                            <span class="font-semibold">Note:</span> attendance
                            rules were not set for part of this period, so late
                            days and missed clock-ins may be under-counted.
                        </p>
                        <dl
                            v-if="appraisal.summary"
                            class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                        >
                            <div class="flex justify-between gap-2">
                                <dt>Late days</dt>
                                <dd class="tabular-nums">
                                    {{
                                        appraisal.summary.late === 0
                                            ? '0'
                                            : `${appraisal.summary.late} (${hours(appraisal.summary.lateMinutes)})`
                                    }}
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
                                    {{
                                        hours(appraisal.summary.overtimeMinutes)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Ratings -->
                    <p
                        class="text-sm text-muted-foreground"
                        data-test="rating-scale"
                    >
                        <span class="font-medium text-foreground"
                            >1 lowest · 5 highest</span
                        >. Each criterion stands on its own; KPOne adds no
                        total.
                    </p>
                    <div
                        class="flex flex-col gap-3"
                        data-test="appraisal-ratings"
                    >
                        <section
                            v-for="(c, i) in appraisal.criteria"
                            :key="i"
                            :class="cardClass"
                        >
                            <p class="font-medium">
                                {{ i + 1 }}. {{ c.title }}
                            </p>
                            <p
                                v-if="c.description"
                                class="text-sm text-muted-foreground"
                            >
                                {{ c.description }}
                            </p>
                            <template v-if="appraisal.can.edit">
                                <V2OptionGroup
                                    :model-value="ratingModel(i)"
                                    :options="scale"
                                    :label="`Rating for ${c.title}, 1 to 5`"
                                    :error="errors[`ratings.${i}.rating`]"
                                    @update:model-value="
                                        (v: string) => setRating(i, v)
                                    "
                                />
                                <V2Field
                                    v-slot="{ id, describedBy }"
                                    label="Comment (optional)"
                                    :error="errors[`ratings.${i}.comment`]"
                                >
                                    <textarea
                                        :id="id"
                                        v-model="form.ratings[i]!.comment"
                                        rows="2"
                                        maxlength="500"
                                        :class="textareaClass"
                                        :aria-describedby="describedBy"
                                    ></textarea>
                                </V2Field>
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
                        </section>
                        <div
                            v-if="appraisal.can.edit"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <button
                                type="button"
                                :class="button"
                                :disabled="form.processing"
                                data-test="appraisal-save-draft"
                                @click="submit(false)"
                            >
                                Save draft
                            </button>
                            <button
                                type="button"
                                :class="primaryButton"
                                :disabled="form.processing || unrated > 0"
                                :aria-describedby="
                                    unrated > 0 ? 'v2-send-hint' : undefined
                                "
                                data-test="appraisal-send"
                                @click="confirmSend = true"
                            >
                                Send to {{ appraisal.person }}
                            </button>
                            <span
                                v-if="sendReason"
                                id="v2-send-hint"
                                class="text-sm text-muted-foreground"
                                >{{ sendReason }}</span
                            >
                        </div>
                    </div>

                    <!-- The person's comment -->
                    <section
                        v-if="appraisal.status !== 'draft'"
                        aria-labelledby="v2-staff-comment-title"
                        :class="cardClass"
                        data-test="appraisal-staff-comment"
                    >
                        <h2
                            id="v2-staff-comment-title"
                            class="text-sm font-semibold"
                        >
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
                        <template v-else-if="appraisal.can.comment">
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Add your comment (optional, once)"
                                :hint="`${comment.text.length} of ${appraisal.maxComment} characters. It cannot be changed after you send it.`"
                                :error="errors.comment"
                            >
                                <textarea
                                    :id="id"
                                    v-model="comment.text"
                                    rows="4"
                                    :maxlength="appraisal.maxComment"
                                    :class="textareaClass"
                                    :aria-describedby="describedBy"
                                ></textarea>
                            </V2Field>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="primaryButton"
                                    :disabled="
                                        comment.processing ||
                                        commentReason !== ''
                                    "
                                    :aria-describedby="
                                        commentReason
                                            ? 'v2-comment-hint'
                                            : undefined
                                    "
                                    data-test="appraisal-comment"
                                    @click="sendComment"
                                >
                                    {{
                                        comment.processing
                                            ? 'Saving…'
                                            : 'Add comment'
                                    }}
                                </button>
                                <span
                                    v-if="commentReason"
                                    id="v2-comment-hint"
                                    class="text-sm text-muted-foreground"
                                    >{{ commentReason }}</span
                                >
                            </div>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            No comment.
                        </p>
                    </section>

                    <p
                        v-if="!appraisal.own && appraisal.signOffFrom"
                        class="text-sm text-muted-foreground"
                        data-test="appraisal-sign-off-wait"
                    >
                        {{ appraisal.person }} has
                        {{ appraisal.commentDays }} days to comment. A Director
                        can sign off once they comment, or from
                        {{ appraisal.signOffFrom }}.
                    </p>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="confirmSend"
            :title="`Send to ${appraisal.person}?`"
            description="The ratings and comments can no longer be changed, and the summary is frozen. The staff member can read it and add a comment."
            confirm-label="Send"
            :busy="form.processing"
            @update:open="(open: boolean) => (confirmSend = open)"
            @confirm="submit(true)"
        />
        <V2Dialog
            :open="confirmSignOff"
            title="Sign this appraisal off?"
            :description="signDescription"
            confirm-label="Sign off"
            cancel-label="Not yet"
            :busy="signBusy"
            @update:open="(open: boolean) => (confirmSignOff = open)"
            @confirm="signOff"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
