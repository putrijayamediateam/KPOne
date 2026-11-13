<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 Quick answers (shown only in V2; Classic is `pages/Agents/Assistant.vue`, untouched). Same props, same
 * route (`GET /agents/assistant?question=&branch=&date=`), same permission and the same fixed-rule answers: nothing
 * is generated here and nothing is saved. The server decides which questions a viewer is offered.
 */
type DateMode = 'date' | 'today' | 'week' | 'none';
type Question = { key: string; label: string; date: DateMode };
type Group = { key: string; label: string; questions: Question[] };
type Cell = { value: number | string | null; kind: string };
type Column = { key: string; label: string; align: 'left' | 'right' };
type Answer = {
    question: string;
    label: string;
    sentence: string;
    columns: Column[];
    rows: Cell[][];
    totalRows: number;
    truncated: boolean;
    totals: Cell[] | null;
    scope: {
        branch: string;
        date: string | null;
        from: string | null;
        to: string | null;
    };
    runId: number;
};

const props = defineProps<{
    groups: Group[];
    branches: { code: string; name: string }[];
    today: string;
    filters: { question: string | null; branch: string; date: string | null };
    answer: Answer | null;
    error: string | null;
}>();

const questions = computed(() =>
    props.groups.flatMap((group) => group.questions),
);
const question = ref<string>(
    props.filters.question ?? questions.value[0]?.key ?? '',
);
const branch = ref<string>(props.filters.branch || 'all');
const date = ref<string>(props.filters.date ?? props.today);
const processing = ref(false);

const selected = computed(() =>
    questions.value.find((item) => item.key === question.value),
);
const usesDate = computed(
    () => !!selected.value && ['date', 'week'].includes(selected.value.date),
);
// The same words the server uses, so a typed date outside the range reads the same before and after Answer.
const dateLimitMessage = 'Choose a date from 1 Jan 2020 up to today.';
const dateLabel = computed(() =>
    selected.value?.date === 'week' ? 'Any day in the week' : 'Date',
);
const branchOptions = computed(() => [
    { value: 'all', label: 'All branches' },
    ...props.branches.map((item) => ({ value: item.code, label: item.name })),
]);

const ask = () => {
    // A date the box could not read leaves the model empty: the box says why, and nothing is asked.
    if (!selected.value || (usesDate.value && !date.value)) {
        return;
    }

    processing.value = true;
    router.get(
        '/agents/assistant',
        {
            question: selected.value.key,
            branch: branch.value,
            ...(usesDate.value ? { date: date.value } : {}),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};

const money = new Intl.NumberFormat('en-MY', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
const count = new Intl.NumberFormat('en-MY');
const format = (cell: Cell): string => {
    if (cell.value === null || cell.value === '') {
        return '—';
    }

    if (cell.kind === 'money') {
        const sen = Number(cell.value);

        return `${sen < 0 ? '−' : ''}RM ${money.format(Math.abs(sen) / 100)}`;
    }

    if (cell.kind === 'count') {
        return count.format(Number(cell.value));
    }

    if (cell.kind === 'minutes') {
        return `${count.format(Number(cell.value))} min`;
    }

    return String(cell.value);
};
const isNumber = (column: Column) => column.align === 'right';

// The answer sits below the questions: bring it into view and move focus to its heading.
const answerHeading = ref<HTMLElement | null>(null);
const showAnswer = async () => {
    await nextTick();
    const heading = answerHeading.value;

    if (!heading) {
        return;
    }

    heading.focus({ preventScroll: true });
    // Scroll only when the heading is hidden (below the screen, or under the sticky top bar).
    const { top } = heading.getBoundingClientRect();

    if (top >= 80 && top <= window.innerHeight - 160) {
        return;
    }

    const reduce = window.matchMedia?.(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    heading.scrollIntoView({
        behavior: reduce ? 'auto' : 'smooth',
        block: 'start',
    });
};
watch(
    () => props.answer?.runId,
    (runId) => {
        if (runId) {
            void showAnswer();
        }
    },
);
onMounted(() => {
    if (props.answer) {
        void showAnswer();
    }
});
const scopeText = computed(() => {
    const scope = props.answer?.scope;

    if (!scope) {
        return '';
    }

    const when = scope.from
        ? `${formatDate(scope.from)} to ${formatDate(scope.to)}`
        : scope.date
          ? formatDate(scope.date)
          : 'As of now';

    // The server names the scope in lower case ("all branches"); a line starts with a capital.
    const branchName =
        scope.branch.charAt(0).toUpperCase() + scope.branch.slice(1);

    return `${branchName} · ${when}`;
});

const filled =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 max-sm:w-full lg:h-9 xl:mt-[26px]';
</script>

<template>
    <Head title="Quick answers" />
    <PageFrame>
        <V2WorkLayout
            title="Quick answers"
            description="Preset questions answered from KPOne's own figures. Nothing is changed."
        >
            <template #main>
                <p
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] leading-[22px] text-muted-foreground"
                    data-test="assistant-notice"
                >
                    <span>Answered by Dato' Arif</span>
                    <V2Chip tone="info" label="AI agent" />
                    <span
                        >with fixed rules, not generated text. Each answer is
                        worked out again when you ask; nothing is saved.</span
                    >
                </p>

                <form class="flex min-w-0 flex-col gap-5" @submit.prevent="ask">
                    <section
                        aria-labelledby="questions-heading"
                        class="flex min-w-0 flex-col gap-3"
                    >
                        <h2
                            id="questions-heading"
                            class="text-lg font-semibold"
                        >
                            Question
                        </h2>
                        <div class="grid gap-4 xl:grid-cols-2">
                            <fieldset
                                v-for="group in groups"
                                :key="group.key"
                                class="flex min-w-0 flex-col gap-2"
                                :data-test="`group-${group.key}`"
                            >
                                <legend
                                    class="mb-1 text-sm font-medium text-muted-foreground"
                                >
                                    {{ group.label }}
                                </legend>
                                <label
                                    v-for="item in group.questions"
                                    :key="item.key"
                                    class="flex min-h-11 cursor-pointer items-center gap-3 rounded-[10px] border px-3 py-2 text-[15px] leading-5 focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background"
                                    :class="
                                        question === item.key
                                            ? 'border-primary bg-primary/10 font-medium text-foreground'
                                            : 'border-border bg-card hover:bg-muted'
                                    "
                                >
                                    <input
                                        v-model="question"
                                        type="radio"
                                        name="question"
                                        :value="item.key"
                                        class="size-4 shrink-0 accent-primary outline-none"
                                        :data-test="`question-${item.key}`"
                                    />
                                    <span class="min-w-0">{{
                                        item.label
                                    }}</span>
                                </label>
                            </fieldset>
                        </div>
                    </section>

                    <section
                        aria-label="Branch and date"
                        class="grid min-w-0 gap-3 rounded-[14px] border border-border bg-card p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-start"
                    >
                        <V2Field v-slot="{ id }" label="Branch">
                            <OperationalSelect
                                :id="id"
                                v-model="branch"
                                :options="branchOptions"
                                label="Branch"
                                trigger-class="min-h-11 w-full lg:min-h-9"
                                data-test="assistant-branch"
                            />
                        </V2Field>
                        <V2DateField
                            v-if="usesDate"
                            v-model="date"
                            :label="dateLabel"
                            subject="The date"
                            :max="today"
                            min="2020-01-01"
                            :min-message="dateLimitMessage"
                            :max-message="dateLimitMessage"
                            data-test="assistant-date"
                        />
                        <div v-else class="flex min-w-0 flex-col gap-1.5">
                            <span class="text-sm font-medium text-foreground"
                                >When</span
                            >
                            <p
                                class="flex min-h-11 items-center text-[15px] text-muted-foreground lg:min-h-9"
                                data-test="assistant-date-note"
                            >
                                {{
                                    selected?.date === 'today'
                                        ? 'Today'
                                        : 'As of now'
                                }}
                            </p>
                        </div>
                        <button
                            type="submit"
                            :class="filled"
                            :disabled="
                                !selected || processing || (usesDate && !date)
                            "
                            data-test="assistant-ask"
                        >
                            Answer
                        </button>
                    </section>
                </form>

                <div
                    v-if="error"
                    role="alert"
                    class="rounded-[14px] border border-destructive/40 bg-card p-4 text-[15px] leading-[22px] text-destructive"
                    data-test="assistant-error"
                >
                    {{ error }}
                </div>

                <div aria-live="polite" class="flex min-w-0 flex-col gap-3">
                    <article
                        v-if="answer"
                        class="flex min-w-0 flex-col gap-3 rounded-[14px] border border-border bg-card p-4"
                        data-test="assistant-answer"
                    >
                        <header class="flex flex-col gap-1">
                            <h2
                                ref="answerHeading"
                                tabindex="-1"
                                class="scroll-mt-20 text-lg font-semibold outline-none"
                                data-test="assistant-answer-heading"
                            >
                                {{ answer.label }}
                            </h2>
                            <p
                                class="text-[13px] leading-[18px] text-muted-foreground"
                                data-test="assistant-scope"
                            >
                                {{ scopeText }}
                            </p>
                        </header>
                        <p
                            class="text-[15px] leading-[22px]"
                            data-test="assistant-sentence"
                        >
                            {{ answer.sentence }}
                        </p>

                        <V2Table
                            v-if="answer.rows.length > 0"
                            :label="answer.label"
                            :columns="answer.columns.length"
                            cards="xl"
                            min-width="640px"
                        >
                            <template #head>
                                <tr>
                                    <th
                                        v-for="column in answer.columns"
                                        :key="column.key"
                                        scope="col"
                                        :class="
                                            isNumber(column) ? 'text-right' : ''
                                        "
                                    >
                                        {{ column.label }}
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr
                                    v-for="(row, index) in answer.rows"
                                    :key="index"
                                    data-test="assistant-row"
                                >
                                    <td
                                        v-for="(cell, cellIndex) in row"
                                        :key="cellIndex"
                                        :data-label="
                                            answer.columns[cellIndex].label
                                        "
                                        :data-primary="
                                            cellIndex === 0 ? '' : undefined
                                        "
                                        :class="
                                            isNumber(answer.columns[cellIndex])
                                                ? 'text-right'
                                                : 'break-words'
                                        "
                                    >
                                        {{ format(cell) }}
                                    </td>
                                </tr>
                                <tr
                                    v-if="answer.totals"
                                    class="font-semibold"
                                    data-test="assistant-totals"
                                >
                                    <td
                                        v-for="(
                                            cell, cellIndex
                                        ) in answer.totals"
                                        :key="cellIndex"
                                        :data-label="
                                            answer.columns[cellIndex].label
                                        "
                                        :data-primary="
                                            cellIndex === 0 ? '' : undefined
                                        "
                                        :class="
                                            isNumber(answer.columns[cellIndex])
                                                ? 'text-right'
                                                : ''
                                        "
                                    >
                                        {{ format(cell) }}
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <p
                            v-if="answer.truncated"
                            class="text-[13px] leading-[18px] text-muted-foreground"
                            data-test="assistant-truncated"
                        >
                            Showing the first {{ answer.rows.length }} of
                            {{ answer.totalRows }} rows. The sentence counts
                            every row.
                        </p>
                    </article>
                    <p
                        v-else-if="!error"
                        class="rounded-[14px] border border-dashed border-border p-5 text-[15px] leading-[22px] text-muted-foreground"
                        data-test="assistant-empty"
                    >
                        Choose a question, then press Answer.
                    </p>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
