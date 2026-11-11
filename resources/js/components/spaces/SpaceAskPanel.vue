<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
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
                <p class="space-ask-meta space-muted">
                    as of {{ answer.asOf }} ·
                    <Link :href="answer.href" class="space-link"
                        >Open the full list</Link
                    >
                </p>
            </template>
            <p v-else class="space-muted">Pick a question</p>
        </div>
    </section>
</template>
