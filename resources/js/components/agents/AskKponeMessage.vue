<script setup lang="ts">
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    AI_LINE,
    branchLabel,
    groupFigures,
    isLongAnswer,
    REMOVED_MESSAGE,
} from '@/lib/ask-kpone';
import type { AskMessage } from '@/lib/ask-kpone';
import { formatDateTime } from '@/lib/presentation';

const props = defineProps<{ message: AskMessage }>();

const expanded = ref(false);
const text = computed(() => props.message.text ?? '');
const long = computed(() => isLongAnswer(text.value));
const groups = computed(() => groupFigures(props.message.figures));
</script>

<template>
    <div
        v-if="message.role === 'user'"
        class="flex justify-end"
        data-ask-message="user"
        :data-ask-id="message.id"
    >
        <p
            v-if="!message.purged"
            class="max-w-[85%] rounded-lg bg-muted px-3 py-2 text-sm break-words whitespace-pre-wrap text-foreground"
        >
            {{ text }}
        </p>
        <p v-else class="text-xs text-muted-foreground">
            {{ REMOVED_MESSAGE }}
        </p>
    </div>
    <div
        v-else
        class="min-w-0 space-y-3 rounded-lg border border-border bg-card px-3 py-3 text-sm text-card-foreground"
        data-ask-message="assistant"
        :data-ask-id="message.id"
    >
        <p v-if="message.purged" class="text-xs text-muted-foreground">
            {{ REMOVED_MESSAGE }}
        </p>
        <template v-else>
            <div>
                <p
                    class="break-words whitespace-pre-wrap"
                    :class="long && !expanded ? 'line-clamp-12' : ''"
                >
                    {{ text }}
                </p>
                <button
                    v-if="long"
                    type="button"
                    class="mt-1 inline-flex min-h-11 items-center text-xs font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-8"
                    :aria-expanded="expanded"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? 'Show less' : 'Show more' }}
                </button>
            </div>

            <section
                v-if="message.figures.length"
                aria-label="Figures"
                class="rounded-md border border-border bg-muted/40 px-3 py-2"
            >
                <h3 class="text-xs font-semibold text-muted-foreground">
                    Figures
                </h3>
                <div
                    v-for="g in groups"
                    :key="g.group ?? '_'"
                    class="mt-2 first:mt-1"
                >
                    <p
                        v-if="g.group"
                        class="text-xs font-medium text-foreground"
                    >
                        {{ branchLabel(g.group) }}
                    </p>
                    <p
                        v-if="g.items[0]?.asOf"
                        class="text-xs text-muted-foreground"
                    >
                        As of {{ formatDateTime(g.items[0].asOf) }}
                    </p>
                    <ul class="divide-y divide-border">
                        <li
                            v-for="(f, i) in g.items"
                            :key="i"
                            class="flex flex-wrap items-baseline justify-between gap-x-3 py-1"
                        >
                            <span class="min-w-0 break-words">{{
                                f.label
                            }}</span>
                            <span class="font-semibold tabular-nums">{{
                                f.text
                            }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <a
                v-if="message.report"
                :href="message.report.url"
                class="inline-flex min-h-11 items-center gap-1 text-sm font-medium text-brand-strong underline underline-offset-4 hover:no-underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-8"
                >{{ message.report.label
                }}<span aria-hidden="true">&rarr;</span></a
            >

            <p
                class="flex items-center gap-2 text-xs text-muted-foreground"
                data-ask-ai-line
            >
                <Badge variant="outline">AI</Badge>
                <span>{{ AI_LINE }}</span>
            </p>
        </template>
    </div>
</template>
