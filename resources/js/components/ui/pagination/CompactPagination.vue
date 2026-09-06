<script setup lang="ts">
import { Button } from '@/components/ui/button';

const props = defineProps<{
    currentPage: number;
    lastPage: number;
    total?: number;
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
        class="flex flex-wrap items-center justify-end gap-2 text-sm"
        aria-label="Pagination"
    >
        <Button
            type="button"
            variant="secondary"
            size="sm"
            :disabled="disabled || currentPage <= 1"
            @click="go(currentPage - 1)"
        >
            Previous
        </Button>
        <span class="px-1 text-xs text-muted-foreground" aria-current="page">
            <template v-if="total !== undefined">{{ total }} records · </template>
            Page {{ currentPage }} of {{ Math.max(lastPage, 1) }}
        </span>
        <Button
            type="button"
            variant="secondary"
            size="sm"
            :disabled="disabled || currentPage >= lastPage"
            @click="go(currentPage + 1)"
        >
            Next
        </Button>
    </nav>
</template>
