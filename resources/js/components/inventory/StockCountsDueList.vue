<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
type CountDueRow = {
    skuPublicId: string;
    skuCode: string;
    item: string;
    locationPublicId: string;
    location: string;
    class: 'A' | 'B' | 'C';
    lastCounted: string | null;
    dueOn: string | null;
    overdueDays: number | null;
    open: boolean;
};
const props = defineProps<{ rows: CountDueRow[] }>();
const list = ref<HTMLElement | null>(null);
const focusedRow = ref<string | undefined>();
const rememberFocus = (event: FocusEvent) => {
    focusedRow.value = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-count-row]',
    )?.dataset.countRow;
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

    focusedRow.value = undefined;
};
watch(
    () => props.rows,
    async () => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const identity =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-count-row]')?.dataset
                      .countRow
                : active === document.body
                  ? focusedRow.value
                  : undefined;
        focusedRow.value = undefined;

        if (!identity) {
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

        const row = Array.from(
            list.value?.querySelectorAll<HTMLElement>('[data-count-row]') ?? [],
        ).find((row) => row.dataset.countRow === identity);
        (row ?? list.value)?.focus();
    },
);
</script>

<template>
    <ul
        ref="list"
        tabindex="-1"
        class="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card"
        aria-label="Counts due"
        @focusin="rememberFocus"
        @focusout="leaveFocus"
    >
        <li v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            Nothing is due for counting.
        </li>
        <li
            v-for="row in rows"
            :key="`${row.skuPublicId}:${row.locationPublicId}`"
            class="grid min-w-0 gap-4 p-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)] lg:items-start"
            tabindex="-1"
            :data-count-row="`${row.skuPublicId}:${row.locationPublicId}`"
        >
            <div class="min-w-0 space-y-1">
                <p class="font-medium break-words">{{ row.item }}</p>
                <p class="font-mono text-xs break-all text-muted-foreground">
                    {{ row.skuCode }}
                </p>
                <div class="flex flex-wrap items-center gap-2 pt-1 text-sm">
                    <slot name="status" :row="row" />
                </div>
            </div>
            <dl class="grid min-w-0 grid-cols-2 gap-3 text-sm lg:grid-cols-1">
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">Location</dt>
                    <dd class="mt-1 break-words">{{ row.location }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs text-muted-foreground">
                        Last completed count
                    </dt>
                    <dd class="mt-1 break-words">
                        {{ row.lastCounted ?? 'No completed count' }}
                    </dd>
                </div>
            </dl>
            <div class="min-w-0 lg:justify-self-end">
                <slot name="action" :row="row" />
            </div>
        </li>
    </ul>
</template>
