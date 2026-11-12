<script setup lang="ts">
import { computed } from 'vue';

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
</script>

<template>
    <div
        class="max-w-full overflow-x-auto rounded-[14px] border bg-card"
        :class="cardsClass"
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
                class="border-b bg-muted text-[13px] font-medium text-muted-foreground [&_th]:px-3 [&_th]:py-2.5"
                :class="variant === 'catalogue' ? '[&_th]:whitespace-nowrap' : ''"
            >
                <slot name="head" />
            </thead>
            <tbody
                class="[&_tr]:min-h-11 [&_tr]:border-b [&_tr]:border-border/70 [&_tr:last-child]:border-0 [&_tr:not([data-empty-row])]:transition-colors [&_tr:not([data-empty-row])]:hover:bg-muted/50 [&_th]:px-3 [&_th]:px-3 [&_td]:px-3 [&_td.text-right]:tabular-nums"
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
                    <td
                        :colspan="columns"
                        class="h-24 text-center text-sm text-muted-foreground"
                    >
                        {{ emptyMessage }}
                    </td>
                </tr>
                <slot v-else name="body" />
            </tbody>
        </table>
    </div>
</template>
