<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import OperationalSelect from '@/components/ui/select/OperationalSelect.vue';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';

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
const dateLabel = computed(() =>
    selected.value?.date === 'week' ? 'Any day in the week' : 'Date',
);
const branchOptions = computed(() => [
    { value: 'all', label: 'All branches' },
    ...props.branches.map((item) => ({ value: item.code, label: item.name })),
]);

const ask = () => {
    if (!selected.value) {
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

        return `${sen < 0 ? '-' : ''}RM ${money.format(Math.abs(sen) / 100)}`;
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
const scopeText = computed(() => {
    const scope = props.answer?.scope;

    if (!scope) {
        return '';
    }

    const when = scope.from
        ? `${scope.from} to ${scope.to}`
        : (scope.date ?? 'as of now');

    return `${scope.branch} · ${when}`;
});
</script>

<template>
    <Head title="Quick answers" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Quick answers"
            description="Preset questions answered from KPOne's own figures. Nothing is changed."
        />
        <p
            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
            data-test="assistant-notice"
        >
            Answered by Dato' Arif
            <StatusBadge status="ai_agent" label="AI agent" tone="accent" />
            with fixed rules, not generated text. Each answer is worked out
            again when you ask; nothing is saved.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
            <section
                aria-labelledby="questions-heading"
                class="grid content-start gap-4"
            >
                <h2 id="questions-heading" class="sr-only">Questions</h2>
                <fieldset
                    v-for="group in groups"
                    :key="group.key"
                    class="grid gap-2"
                    :data-test="`group-${group.key}`"
                >
                    <legend class="mb-1 text-sm font-semibold">
                        {{ group.label }}
                    </legend>
                    <label
                        v-for="item in group.questions"
                        :key="item.key"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm"
                        :class="
                            question === item.key
                                ? 'border-brand bg-brand/10 font-medium text-brand-strong'
                                : 'hover:bg-muted/40'
                        "
                    >
                        <input
                            v-model="question"
                            type="radio"
                            name="question"
                            :value="item.key"
                            class="size-4 accent-brand"
                            :data-test="`question-${item.key}`"
                        />
                        <span class="min-w-0">{{ item.label }}</span>
                    </label>
                </fieldset>
            </section>

            <section
                aria-labelledby="answer-heading"
                class="grid min-w-0 content-start gap-4"
            >
                <h2 id="answer-heading" class="sr-only">Answer</h2>
                <form
                    class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
                    @submit.prevent="ask"
                >
                    <div class="grid min-w-0 gap-1 text-sm">
                        <span id="assistant-branch-label" class="font-medium"
                            >Branch</span
                        >
                        <OperationalSelect
                            v-model="branch"
                            :options="branchOptions"
                            label="Branch"
                            labelledby="assistant-branch-label"
                            trigger-class="min-h-11"
                            data-test="assistant-branch"
                        />
                    </div>
                    <div class="grid min-w-0 gap-1 text-sm">
                        <label
                            v-if="usesDate"
                            for="assistant-date"
                            class="font-medium"
                            >{{ dateLabel }}</label
                        >
                        <span v-else class="font-medium">When</span>
                        <Input
                            v-if="usesDate"
                            id="assistant-date"
                            v-model="date"
                            type="date"
                            :max="today"
                            min="2020-01-01"
                            class="min-h-11"
                            data-test="assistant-date"
                        />
                        <p
                            v-else
                            id="assistant-date"
                            class="flex min-h-11 items-center text-muted-foreground"
                            data-test="assistant-date-note"
                        >
                            {{
                                selected?.date === 'today'
                                    ? 'Today'
                                    : 'As of now'
                            }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11"
                        :disabled="!selected || processing"
                        data-test="assistant-ask"
                    >
                        Answer
                    </Button>
                </form>

                <div
                    v-if="error"
                    role="alert"
                    class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm"
                    data-test="assistant-error"
                >
                    {{ error }}
                </div>

                <div aria-live="polite" class="grid min-w-0 gap-4">
                    <article
                        v-if="answer"
                        class="grid min-w-0 gap-3 rounded-xl border bg-card p-4"
                        data-test="assistant-answer"
                    >
                        <header class="grid gap-1">
                            <h3 class="font-semibold">{{ answer.label }}</h3>
                            <p
                                class="text-xs text-muted-foreground"
                                data-test="assistant-scope"
                            >
                                {{ scopeText }}
                            </p>
                        </header>
                        <p class="text-base" data-test="assistant-sentence">
                            {{ answer.sentence }}
                        </p>

                        <template v-if="answer.rows.length > 0">
                            <div class="hidden md:block">
                                <OperationalTable
                                    :label="answer.label"
                                    :columns="answer.columns.length"
                                    min-width="0"
                                >
                                    <template #head>
                                        <tr>
                                            <th
                                                v-for="column in answer.columns"
                                                :key="column.key"
                                                scope="col"
                                                :class="
                                                    isNumber(column)
                                                        ? 'text-right'
                                                        : ''
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
                                                :class="
                                                    isNumber(
                                                        answer.columns[
                                                            cellIndex
                                                        ],
                                                    )
                                                        ? 'text-right tabular-nums'
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
                                                :class="
                                                    isNumber(
                                                        answer.columns[
                                                            cellIndex
                                                        ],
                                                    )
                                                        ? 'text-right tabular-nums'
                                                        : ''
                                                "
                                            >
                                                {{ format(cell) }}
                                            </td>
                                        </tr>
                                    </template>
                                </OperationalTable>
                            </div>
                            <ul
                                class="grid gap-2 md:hidden"
                                :aria-label="answer.label"
                            >
                                <li
                                    v-for="(row, index) in [
                                        ...answer.rows,
                                        ...(answer.totals
                                            ? [answer.totals]
                                            : []),
                                    ]"
                                    :key="index"
                                    class="rounded-lg border p-3 text-sm"
                                    :class="
                                        answer.totals &&
                                        index === answer.rows.length
                                            ? 'font-semibold'
                                            : ''
                                    "
                                >
                                    <p class="mb-2 font-medium break-words">
                                        {{ format(row[0]) }}
                                    </p>
                                    <dl
                                        class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1"
                                    >
                                        <template
                                            v-for="(
                                                cell, cellIndex
                                            ) in row.slice(1)"
                                            :key="cellIndex"
                                        >
                                            <dt class="text-muted-foreground">
                                                {{
                                                    answer.columns[
                                                        cellIndex + 1
                                                    ].label
                                                }}
                                            </dt>
                                            <dd
                                                class="min-w-0 text-right break-words tabular-nums"
                                            >
                                                {{ format(cell) }}
                                            </dd>
                                        </template>
                                    </dl>
                                </li>
                            </ul>
                        </template>
                        <p
                            v-if="answer.truncated"
                            class="text-xs text-muted-foreground"
                            data-test="assistant-truncated"
                        >
                            Showing the first {{ answer.rows.length }} of
                            {{ answer.totalRows }} rows. The sentence counts
                            every row.
                        </p>
                    </article>
                    <p
                        v-else-if="!error"
                        class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
                    >
                        Choose a question, then press Answer.
                    </p>
                </div>
            </section>
        </div>
    </main>
</template>
