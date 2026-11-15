<script setup lang="ts">
/**
 * Version 2 pager: a plain count line ("1–25 of 120"), then Previous and Next at 44px (36px from 1024px).
 * At the ends the button is shown disabled (dashed, muted), never hidden. The count is above on phones.
 */
const props = defineProps<{
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
    disabled?: boolean;
}>();

const emit = defineEmits<{ change: [page: number] }>();

const from = () =>
    props.total === 0 ? 0 : (props.currentPage - 1) * props.perPage + 1;
const to = () => Math.min(props.currentPage * props.perPage, props.total);

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

const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:border-dashed disabled:bg-transparent disabled:text-muted-foreground disabled:hover:bg-transparent lg:h-9';
</script>

<template>
    <nav
        data-slot="v2-pager"
        aria-label="Pagination"
        class="grid grid-cols-2 items-center gap-2 text-sm sm:flex sm:justify-end"
    >
        <p
            class="order-first col-span-2 text-center text-[13px] text-muted-foreground tabular-nums sm:order-none sm:col-span-1 sm:mr-2 sm:text-left"
            aria-current="page"
        >
            {{ from() }}–{{ to() }} of {{ total }} · Page {{ currentPage }} of
            {{ Math.max(lastPage, 1) }}
        </p>
        <button
            type="button"
            :class="button"
            :disabled="disabled || currentPage <= 1"
            @click="go(currentPage - 1)"
        >
            Previous
        </button>
        <button
            type="button"
            :class="button"
            :disabled="disabled || currentPage >= lastPage"
            @click="go(currentPage + 1)"
        >
            Next
        </button>
    </nav>
</template>
