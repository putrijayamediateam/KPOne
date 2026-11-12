<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { recordsLabel } from '@/lib/pager';

const props = defineProps<{
    currentPage: number;
    lastPage: number;
    total?: number;
    perPage?: number;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    change: [page: number];
}>();

const go = (page: number) => {
    if (
        props.disabled ||
        page < 1 ||
        page > props.lastPage ||
        page === props.currentPage
    ) {
        return;
    }

    emit('change', page);
};
</script>

<template>
    <nav
        class="grid grid-cols-2 items-center gap-2 text-sm sm:flex sm:flex-wrap sm:justify-end [&_button:disabled]:border-dashed [&_button:disabled]:text-muted-foreground"
        aria-label="Pagination"
    >
        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="max-lg:h-11 max-lg:px-4"
            :disabled="disabled || currentPage <= 1"
            @click="go(currentPage - 1)"
        >
            Previous
        </Button>
        <span
            class="order-first col-span-2 px-1 text-center text-[13px] text-muted-foreground sm:order-none sm:col-span-1 sm:text-left" aria-current="page">
            <template v-if="total !== undefined && perPage"
                >{{ (currentPage - 1) * perPage + 1 }}–{{
                    Math.min(currentPage * perPage, total)
                }}
                of {{ total }} ·
            </template>
            <template v-else-if="total !== undefined"
                >{{ recordsLabel(total) }} ·
            </template>
            Page {{ currentPage }} of {{ Math.max(lastPage, 1) }}
        </span>
        <Button
            type="button"
            variant="secondary"
            size="sm"
            class="max-lg:h-11 max-lg:px-4"
            :disabled="disabled || currentPage >= lastPage"
            @click="go(currentPage + 1)"
        >
            Next
        </Button>
    </nav>
</template>
