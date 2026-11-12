<script setup lang="ts">
/**
 * Usage: prefer `cards` on phones for any table with row actions. A plain table below 1024px scrolls sideways
 * (with a right-edge fade and a sticky first column); `cards` turns each row into a card, `cards="xl"` below 1280px.
 * In cards, give each cell a `data-label`; the first cell is the primary field; put a selection checkbox in
 * `td[data-select]` wrapped in a label (a 44px tap area) and row actions in `td[data-actions]`.
 * `dense` gives 44px rows at 14px; the default is 52px at 15px.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        columns: number;
        variant?: 'default' | 'catalogue';
        empty?: boolean;
        emptyMessage?: string;
        loading?: boolean;
        minWidth?: string;
        dense?: boolean;
        cards?: boolean | '' | 'lg' | 'xl';
    }>(),
    {
        variant: 'default',
        empty: false,
        emptyMessage: 'No records are available.',
        loading: false,
        minWidth: '720px',
        dense: false,
        cards: false,
    },
);

// Cards are opt-in; bare `cards` means below 1024px, cards="xl" below 1280px.
const cardsClass = computed(() =>
    props.cards === false
        ? ''
        : props.cards === 'xl'
          ? 'kp-table-cards kp-table-cards-xl'
          : 'kp-table-cards kp-table-cards-lg',
);

// Scroll cue: a right-edge fade while the table can scroll further, and the
// visible width, so an empty message stays centred on screen, not in the scroll width.
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
        class="kp-table max-w-full overflow-x-auto rounded-[14px] border bg-card"
        :class="[
            cardsClass,
            more
                ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                : '',
        ]"
        @scroll.passive="measure"
        role="region"
        :aria-label="label"
        tabindex="0"
    >
        <table
            class="w-full text-left"
            :class="
                dense
                    ? 'text-sm'
                    : 'text-[15px] [&_.text-right]:tabular-nums'
            "
            :style="{ minWidth }"
            :aria-busy="loading"
        >
            <caption class="sr-only">
                {{ label }}
            </caption>
            <thead
                class="border-b bg-muted text-[13px] font-medium text-muted-foreground [&_th]:px-3 [&_th]:py-2.5 [&_th:first-child]:sticky [&_th:first-child]:left-0 [&_th:first-child]:bg-muted"
                :class="variant === 'catalogue' ? '[&_th]:whitespace-nowrap' : ''"
            >
                <slot name="head" />
            </thead>
            <tbody
                class="[&_tr]:min-h-11 [&_tr]:border-b [&_tr]:border-border/70 [&_tr:last-child]:border-0 [&_tr:not([data-empty-row])]:transition-colors [&_tr:not([data-empty-row])]:hover:bg-muted/50 [&_th]:px-3 [&_th]:px-3 [&_td]:px-3 [&_td.text-right]:tabular-nums [&_td:first-child]:sticky [&_td:first-child]:left-0 [&_td:first-child]:bg-card"
                :class="
                    dense
                        ? '[&_td]:py-2 [&_th]:py-2'
                        : '[&_td]:py-3.5 [&_th]:py-3.5'
                "
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
                    <td :colspan="columns" class="p-0">
                        <div
                            class="sticky left-0 flex min-h-28 flex-col items-center justify-center gap-2 px-4 py-6 text-center"
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
