<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        columns: number;
        variant?: 'default' | 'catalogue';
        empty?: boolean;
        emptyMessage?: string;
        loading?: boolean;
        minWidth?: string;
        cards?: boolean;
    }>(),
    {
        variant: 'default',
        empty: false,
        emptyMessage: 'No records are available.',
        loading: false,
        minWidth: '720px',
        cards: false,
    },
);
</script>

<template>
    <div
        class="max-w-full overflow-x-auto rounded-[14px] border bg-card"
        :class="cards ? 'kp-table-cards' : ''"
        role="region"
        :aria-label="label"
        tabindex="0"
    >
        <table
            class="w-full text-left"
            :class="
                variant === 'catalogue'
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
                class="[&_tr]:min-h-11 [&_tr]:border-b [&_tr]:border-border/70 [&_tr:last-child]:border-0 [&_tr:not([data-empty-row])]:transition-colors [&_tr:not([data-empty-row])]:hover:bg-muted/50 [&_th]:px-3 [&_th]:py-3 [&_td]:px-3 [&_td]:py-3 [&_td.text-right]:tabular-nums"
                :class="
                    variant === 'catalogue'
                        ? '[&_td]:py-2.5'
                        : ''
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
