<script
    setup
    lang="ts"
    generic="
        Row extends {
            public_id: string;
            sku_code: string;
            batch_number: string;
        }
    "
>
import { nextTick, ref, watch } from 'vue';
const props = defineProps<{
    rows: Row[];
    branchId: number;
    activeBatchId: string | null;
    busy: boolean;
}>();
const list = ref<HTMLElement | null>(null);
let returnTo: { batch: string; branch: number } | null = null;
watch(
    [() => props.activeBatchId, () => props.branchId],
    ([batch, branch], [, previousBranch]) => {
        if (previousBranch !== undefined && branch !== previousBranch) {
            returnTo = null;

            return;
        }

        if (batch !== null) {
            returnTo = { batch, branch };
        }
    },
    { immediate: true },
);
const restoreFocus = (event: Event) => {
    if (
        typeof document === 'undefined' ||
        !returnTo ||
        returnTo.branch !== props.branchId
    ) {
        return;
    }

    const target = returnTo;
    event.preventDefault();
    const active = document.activeElement as HTMLElement | null;

    if (
        active &&
        active !== document.body &&
        !list.value?.contains(active) &&
        !active.closest('[role="dialog"]')
    ) {
        return;
    }

    void nextTick(() => {
        if (target.branch !== props.branchId) {
            return;
        }

        if (
            document.activeElement !== active &&
            document.activeElement !== document.body &&
            !list.value?.contains(document.activeElement)
        ) {
            return;
        }

        const row = Array.from(
            list.value?.querySelectorAll<HTMLElement>('[data-hold-batch]') ??
                [],
        ).find((row) => row.dataset.holdBatch === target.batch);
        const action = Array.from(
            row?.querySelectorAll<HTMLButtonElement>('button') ?? [],
        ).find(
            (button) => !button.disabled && button.getClientRects().length > 0,
        );
        (action ?? row ?? list.value)?.focus();
    });
};
defineExpose({ restoreFocus });
</script>

<template>
    <section
        ref="list"
        tabindex="-1"
        aria-label="Batch safety hold worklist"
        :aria-busy="busy"
        class="overflow-hidden rounded-2xl border border-border bg-card"
    >
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            No batches match this search.
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="row in rows"
                :key="row.public_id"
                :data-hold-batch="row.public_id"
                tabindex="-1"
                class="grid min-w-0 gap-4 p-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.2fr)]"
            >
                <div class="min-w-0 space-y-3">
                    <div class="space-y-1">
                        <h2 class="font-medium break-all">
                            {{ row.sku_code }}
                        </h2>
                        <p class="text-sm break-words text-muted-foreground">
                            Batch {{ row.batch_number }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Expiry</p>
                        <div class="mt-1 text-sm">
                            <slot name="expiry" :row="row" />
                        </div>
                    </div>
                </div>
                <div class="min-w-0 space-y-3">
                    <slot name="status" :row="row" />
                    <div
                        class="flex flex-wrap gap-2 [&_button]:min-h-11 [&_button]:min-w-11 lg:[&_button]:min-h-9 lg:[&_button]:min-w-9"
                    >
                        <slot name="actions" :row="row" />
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
