<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Version 2 table. Default rows are 52px at 15px; `dense` is 44px at 14px. Cells carry `data-label` for the phone
 * cards (`cards`: below 1024px, or `cards="xl"` below 1280px); `td[data-select]` and `td[data-actions]` are the
 * selection row on top and the full-width action row at the bottom of a card. Right-aligned cells use tabular figures.
 * The empty state sits in a cell with no padding and never wider than that cell (a padded cell used to push the page
 * about 10px sideways in cards mode). A card cell that holds an email, a code or free text opts in to breaking a long
 * unbreakable value with `data-wrap` on its `td`; other cells never split a word or a number ("RM 1,240.00"). A table that can scroll shows a right-edge fade and keeps its first column in view. Prefer cards on phones for any
 * table with row actions.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        columns: number;
        dense?: boolean;
        empty?: boolean;
        emptyMessage?: string;
        loading?: boolean;
        minWidth?: string;
        cards?: false | 'lg' | 'xl';
    }>(),
    {
        dense: false,
        empty: false,
        emptyMessage: 'No records are available.',
        loading: false,
        minWidth: '720px',
        cards: false,
    },
);

const cardsClass = computed(() =>
    props.cards ? `v2-cards v2-cards-${props.cards}` : '',
);
const box = ref<HTMLElement | null>(null);
const more = ref(false);
const visible = ref<number | null>(null);
let observer: ResizeObserver | null = null;

const measure = () => {
    const el = box.value;

    if (!el) {
        return;
    }

    more.value = el.scrollWidth - el.clientWidth - el.scrollLeft > 1;
    visible.value = el.clientWidth;
};

onMounted(() => {
    measure();

    if (typeof ResizeObserver !== 'undefined' && box.value) {
        observer = new ResizeObserver(measure);
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div
        ref="box"
        class="v2-table max-w-full overflow-x-auto rounded-[14px] border bg-card"
        :class="[
            cardsClass,
            more
                ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                : '',
        ]"
        role="region"
        :aria-label="label"
        tabindex="0"
        @scroll.passive="measure"
    >
        <table
            class="w-full text-left"
            :class="dense ? 'text-sm' : 'text-[15px]'"
            :style="{ minWidth }"
            :aria-busy="loading"
        >
            <caption class="sr-only">
                {{
                    label
                }}
            </caption>
            <thead
                class="border-b bg-muted text-[13px] font-medium text-muted-foreground [&_th]:px-3 [&_th]:py-3 [&_th:first-child]:sticky [&_th:first-child]:left-0 [&_th:first-child]:bg-muted"
            >
                <slot name="head" />
            </thead>
            <tbody
                class="[&_td]:px-3 [&_td.text-right]:tabular-nums [&_td:first-child]:sticky [&_td:first-child]:left-0 [&_td:first-child]:bg-card [&_tr]:min-h-11 [&_tr]:border-b [&_tr]:border-border/70 [&_tr:last-child]:border-0 [&_tr:not([data-empty-row])]:hover:bg-muted/50"
                :class="dense ? '[&_td]:py-2' : '[&_td]:py-3.5'"
            >
                <tr v-if="loading" data-empty-row>
                    <td
                        :colspan="columns"
                        class="h-20 text-center text-sm text-muted-foreground"
                        role="status"
                    >
                        Loading records…
                    </td>
                </tr>
                <tr v-else-if="empty" data-empty-row>
                    <td :colspan="columns" class="!p-0">
                        <div
                            class="sticky left-0 flex min-h-28 max-w-full flex-col items-center justify-center gap-2 px-4 py-6 text-center"
                            :style="visible ? { width: `${visible}px` } : {}"
                        >
                            <svg
                                class="size-6 text-muted-foreground"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M22 12h-6l-2 3h-4l-2-3H2" />
                                <path
                                    d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"
                                />
                            </svg>
                            <p class="text-sm text-muted-foreground">
                                {{ emptyMessage }}
                            </p>
                        </div>
                    </td>
                </tr>
                <slot v-else name="body" />
            </tbody>
        </table>
    </div>
</template>
