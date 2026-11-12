<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Question = { key: string; label: string };
type Answer = {
    key: string;
    label: string;
    sentence: string;
    columns: string[];
    rows: string[][];
    asOf: string;
    href: string;
};

const props = defineProps<{ space: string; questions: Question[] }>();

const answer = ref<Answer | null>(null);
const busy = ref<string | null>(null);
const error = ref('');
const failed = ref<Question | null>(null);

// Below lg an answer shows as cards: the first cell (and the second after a queue number) is the title and the
// remaining cells are one muted line.
const cardOf = (
    cells: string[],
): { title: string; parts: string[]; change: string | null } => {
    const numbered = answer.value?.columns[0] === 'Number';
    const head = numbered ? 2 : 1;

    // A date column reads like a join date at the end of a muted line, so its card text is prefixed with its heading.
    const labelled = cells.map((cell, i) =>
        answer.value?.columns[i] === 'Inactive since' &&
        cell !== 'Date not recorded'
            ? `Inactive since ${cell}`
            : cell,
    );
    // A "Change" column (the explainers) is shown as its own line, so it is not lost at the end of a muted line.
    const at = answer.value?.columns.indexOf('Change') ?? -1;
    const change = at > 0 ? (labelled[at] ?? null) : null;
    const rest = labelled.filter((_, i) => i >= head && i !== at);

    return { title: labelled.slice(0, head).join(' '), parts: rest, change };
};

// A long answer reads as a semibold first sentence and a regular rest; a short one stays semibold as a whole.
const sentenceParts = computed((): { lead: string; rest: string } => {
    const text = answer.value?.sentence ?? '';

    if (text.length > 140) {
        // Plain string work (no lookbehind, which older Safari cannot parse): the first sentence end from index 30.
        for (let i = 30; i < text.length - 2; i++) {
            if (
                '.!?'.includes(text[i]) &&
                text[i + 1] === ' ' &&
                /[A-Z0-9]/.test(text[i + 2])
            ) {
                return { lead: text.slice(0, i + 1), rest: text.slice(i + 2) };
            }
        }
    }

    return { lead: text, rest: '' };
});

const csrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const ask = async (question: Question) => {
    if (busy.value) {
        return;
    }

    busy.value = question.key;
    error.value = '';
    failed.value = null;

    try {
        answer.value = await requestJson<Answer>(`/spaces/${props.space}/ask`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ question: question.key }),
        });
    } catch (caught) {
        answer.value = null;
        const refused =
            caught instanceof JsonRequestError && caught.status === 403;
        error.value = refused
            ? 'You can no longer ask this question.'
            : 'Could not load. Try again.';
        failed.value = refused ? null : question;
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <section class="space-card" aria-labelledby="space-ask">
        <h2 id="space-ask">Quick questions</h2>
        <p class="space-muted">Fixed questions. Nothing is typed.</p>
        <div class="space-ask-list" role="group" aria-label="Questions">
            <button
                v-for="q in questions"
                :key="q.key"
                type="button"
                class="space-ask-btn"
                :class="{ 'is-on': answer?.key === q.key }"
                :aria-pressed="answer?.key === q.key"
                :disabled="busy !== null"
                @click="ask(q)"
            >
                {{ q.label }}
            </button>
        </div>
        <div
            class="space-ask-answer"
            aria-live="polite"
            :aria-busy="busy !== null"
        >
            <div v-if="busy" class="space-ask-skeleton" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>
            <p v-if="busy" class="space-visually-hidden">Loading…</p>
            <div v-else-if="error" role="alert" class="space-ask-failed">
                <p class="space-ask-error">{{ error }}</p>
                <button
                    v-if="failed"
                    type="button"
                    class="space-ask-btn"
                    @click="ask(failed)"
                >
                    Retry
                </button>
            </div>
            <template v-else-if="answer">
                <p class="space-ask-sentence">
                    <span class="space-ask-lead">{{ sentenceParts.lead }}</span
                    ><template v-if="sentenceParts.rest">
                        {{ sentenceParts.rest }}</template
                    >
                </p>
                <div
                    v-if="answer.rows.length"
                    class="space-ask-table"
                    :class="{ 'is-numbered': answer.columns[0] === 'Number' }"
                >
                    <table>
                        <thead>
                            <tr>
                                <th
                                    v-for="c in answer.columns"
                                    :key="c"
                                    scope="col"
                                >
                                    {{ c }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in answer.rows" :key="i">
                                <td v-for="(cell, j) in r" :key="j">
                                    {{ cell }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul v-if="answer.rows.length" class="space-ask-cards">
                    <li v-for="(r, i) in answer.rows" :key="i">
                        <strong>{{ cardOf(r).title }}</strong>
                        <span v-if="cardOf(r).parts.length" class="space-muted"
                            ><template
                                v-for="(part, k) in cardOf(r).parts"
                                :key="k"
                                ><span v-if="k > 0" aria-hidden="true"> · </span
                                ><span
                                    :class="{
                                        'space-nowrap': part.length <= 20,
                                    }"
                                    >{{ part }}</span
                                ></template
                            ></span
                        >
                        <strong
                            v-if="cardOf(r).change"
                            class="space-ask-change"
                            >{{ cardOf(r).change }}</strong
                        >
                    </li>
                </ul>
                <p class="space-ask-meta space-muted">
                    <span>as of {{ answer.asOf }}</span
                    ><span class="space-ask-dot" aria-hidden="true"> · </span>
                    <Link :href="answer.href" class="space-link"
                        >Open the full list</Link
                    >
                </p>
            </template>
            <p v-else class="space-muted">Pick a question</p>
        </div>
    </section>
</template>
