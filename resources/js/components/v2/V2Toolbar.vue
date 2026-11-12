<script setup lang="ts">
import { ref } from 'vue';

/**
 * Version 2 toolbar: a search box, the main filters, one primary action, and an optional "More filters" disclosure
 * under the main row (the page puts the extra filters in the `more` slot; Reset emits `reset`).
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        searchLabel: string;
        searchPlaceholder?: string;
        moreFilters?: boolean;
    }>(),
    { searchPlaceholder: '', moreFilters: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    search: [];
    reset: [];
}>();

const open = ref(false);
const id = `v2-more-${Math.random().toString(36).slice(2, 8)}`;

void props;
</script>

<template>
    <div data-slot="v2-toolbar" class="flex min-w-0 flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <label class="relative min-w-0 flex-1 basis-64">
                <span class="sr-only">{{ searchLabel }}</span>
                <svg
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <input
                    type="search"
                    :value="modelValue"
                    :placeholder="searchPlaceholder"
                    class="h-11 w-full rounded-[10px] border border-input bg-card pr-3 pl-9 text-base outline-none selection:bg-primary/25 hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                    @input="
                        emit(
                            'update:modelValue',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                    @keydown.enter.prevent="emit('search')"
                />
            </label>
            <slot name="filters" />
            <button
                v-if="moreFilters"
                type="button"
                class="inline-flex h-11 items-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none lg:h-9"
                :aria-expanded="open"
                :aria-controls="id"
                @click="open = !open"
            >
                More filters
                <svg
                    class="size-4 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <div
                v-if="$slots.primary"
                class="max-sm:w-full [&>*]:max-sm:w-full"
            >
                <slot name="primary" />
            </div>
        </div>
        <div
            v-if="moreFilters && open"
            :id="id"
            class="flex flex-wrap items-end gap-2 rounded-[14px] border bg-card p-3"
        >
            <slot name="more" />
            <button
                type="button"
                class="inline-flex h-11 items-center rounded-[10px] px-4 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                @click="emit('reset')"
            >
                Reset
            </button>
        </div>
    </div>
</template>
