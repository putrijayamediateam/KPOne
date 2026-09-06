<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        columns: number;
        empty?: boolean;
        emptyMessage?: string;
        loading?: boolean;
        minWidth?: string;
    }>(),
    {
        empty: false,
        emptyMessage: 'No records are available.',
        loading: false,
        minWidth: '720px',
    },
);
</script>

<template>
    <div
        class="max-w-full overflow-x-auto rounded-xl border bg-card"
        role="region"
        :aria-label="label"
        tabindex="0"
    >
        <table
            class="w-full text-left text-sm"
            :style="{ minWidth }"
            :aria-busy="loading"
        >
            <caption class="sr-only">
                {{ label }}
            </caption>
            <thead
                class="border-b bg-muted/60 text-xs font-medium text-muted-foreground"
            >
                <slot name="head" />
            </thead>
            <tbody
                class="[&_tr]:min-h-10 [&_tr]:border-b [&_tr]:border-border/70 [&_tr:last-child]:border-0 [&_tr:not([data-empty-row])]:transition-colors [&_tr:not([data-empty-row])]:hover:bg-muted/40 [&_th]:px-3 [&_th]:py-2.5 [&_td]:px-3 [&_td]:py-2.5"
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
