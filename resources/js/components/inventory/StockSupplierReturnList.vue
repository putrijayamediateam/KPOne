<script setup lang="ts" generic="Row extends { id: number }">
import { ChevronRight } from '@lucide/vue';
import { nextTick, ref, useId, watch } from 'vue';

const props = defineProps<{
    rows: Row[];
    contextKey: string;
    branchId: number;
    busy: boolean;
    listLabel: string;
    emptyText: string;
    number: (row: Row) => string;
    rowTestId?: (row: Row) => string;
}>();
const id = useId();
const list = ref<HTMLElement | null>(null);
const selected = ref<number | null>(null);
const focusedOrder = ref<string | undefined>();
const toggle = (publicId: number) => {
    if (!props.busy) {
        selected.value = selected.value === publicId ? null : publicId;
    }
};
const rememberFocus = (event: FocusEvent) => {
    focusedOrder.value = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-return-row]',
    )?.dataset.returnRow;
};
const leaveFocus = (event: FocusEvent) => {
    if (
        event.relatedTarget &&
        list.value?.contains(event.relatedTarget as Node)
    ) {
        return;
    }

    if (
        !event.relatedTarget &&
        (event.target as HTMLButtonElement).disabled === true
    ) {
        return;
    }

    focusedOrder.value = undefined;
};
watch(
    [() => props.rows, () => props.branchId, () => props.contextKey],
    async ([rows, branch, context], [, oldBranch, oldContext]) => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const identity =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-return-row]')?.dataset
                      .returnRow
                : typeof document !== 'undefined' && active === document.body
                  ? focusedOrder.value
                  : undefined;
        focusedOrder.value = undefined;

        if (
            branch !== oldBranch ||
            context !== oldContext ||
            !rows.some((row) => row.id === selected.value)
        ) {
            selected.value = null;
        }

        if (!identity || typeof document === 'undefined') {
            return;
        }

        await nextTick();

        if (
            document.activeElement !== active &&
            document.activeElement !== document.body
        ) {
            return;
        }

        if (
            active !== document.body &&
            active?.isConnected &&
            active.getClientRects().length
        ) {
            return;
        }

        const trigger =
            branch === oldBranch
                ? Array.from(
                      list.value?.querySelectorAll<HTMLButtonElement>(
                          '[data-return-trigger]',
                      ) ?? [],
                  ).find((button) => button.dataset.returnTrigger === identity)
                : undefined;
        (trigger ?? list.value)?.focus();
    },
);
</script>
<template>
    <section
        ref="list"
        tabindex="-1"
        :aria-label="listLabel"
        :aria-busy="busy"
        class="overflow-hidden rounded-2xl border border-border bg-card"
        @focusin="rememberFocus"
        @focusout="leaveFocus"
    >
        <p v-if="busy" role="status" class="border-b border-border p-4 text-sm">
            A return action is in progress. Keep its details open until it
            finishes.
        </p>
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            {{ emptyText }}
        </p>
        <article
            v-for="row in rows"
            :key="row.id"
            :data-return-row="row.id"
            :data-testid="rowTestId?.(row)"
            class="border-b border-border last:border-b-0"
        >
            <div
                class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1fr)_8rem] lg:items-start"
            >
                <div class="min-w-0"><slot name="summary" :row="row" /></div>
                <button
                    type="button"
                    :data-return-trigger="row.id"
                    :aria-label="`${selected === row.id ? 'Close' : 'Open'} details for return ${number(row)}`"
                    :aria-expanded="selected === row.id"
                    :aria-controls="`${id}-${row.id}`"
                    :disabled="busy"
                    class="inline-flex min-h-11 items-center justify-center gap-1 rounded-lg border border-border px-3 text-sm hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9"
                    @click="toggle(row.id)"
                >
                    <ChevronRight
                        aria-hidden="true"
                        class="size-4 shrink-0"
                        :class="selected === row.id ? 'rotate-90' : ''"
                    />
                    {{ selected === row.id ? 'Close' : 'Open details' }}
                </button>
            </div>
            <div
                v-show="selected === row.id"
                :id="`${id}-${row.id}`"
                :aria-label="`Details for return ${number(row)}`"
                class="space-y-3 border-t border-border bg-muted/10 p-4"
            >
                <slot :row="row" />
            </div>
        </article>
    </section>
</template>
