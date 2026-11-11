<script setup lang="ts">
import { ref } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Question = { key: string; label: string };
type Answer = {
    key: string;
    label: string;
    sentence: string;
    columns: string[];
    rows: string[][];
};

const props = defineProps<{ space: string; questions: Question[] }>();

const answer = ref<Answer | null>(null);
const busy = ref<string | null>(null);
const error = ref('');

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
        error.value =
            caught instanceof JsonRequestError && caught.status === 403
                ? 'You can no longer ask this question.'
                : 'The answer could not be loaded. Try again.';
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <section class="space-card" aria-labelledby="space-ask">
        <h2 id="space-ask">Ask</h2>
        <div class="space-ask-list" role="group" aria-label="Questions">
            <button
                v-for="q in questions"
                :key="q.key"
                type="button"
                class="space-ask-btn"
                :class="{ 'is-on': answer?.key === q.key }"
                :disabled="busy !== null"
                @click="ask(q)"
            >
                {{ q.label }}
            </button>
        </div>
        <div class="space-ask-answer" aria-live="polite">
            <p v-if="busy" class="space-muted">Loading…</p>
            <p v-else-if="error" class="space-ask-error" role="alert">
                {{ error }}
            </p>
            <template v-else-if="answer">
                <p class="space-ask-sentence">{{ answer.sentence }}</p>
                <div v-if="answer.rows.length" class="space-ask-table">
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
            </template>
        </div>
    </section>
</template>
