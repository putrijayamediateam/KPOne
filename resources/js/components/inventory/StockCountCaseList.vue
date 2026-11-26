<script
    setup
    lang="ts"
    generic="
        Row extends {
            id: number;
            public_id: string;
            sku_code: string;
            batch_number: string;
            location: string;
            kind: string;
            state: string;
            blind: boolean;
        }
    "
>
import { ChevronRight } from '@lucide/vue';
import { nextTick, ref, useId, watch } from 'vue';
const props = defineProps<{
    rows: Row[];
    enabled: boolean;
    listLabel?: string;
    branchId: number;
    busy: boolean;
    states: Record<string, string>;
    caseLabel: (kind: string) => string;
}>();
const id = useId();
const selected = ref<string | null>(null);
const list = ref<HTMLElement | null>(null);
const focusedCase = ref<string | undefined>();
const rememberFocus = (event: FocusEvent) => {
    focusedCase.value = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-count-case]',
    )?.dataset.countCase;
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

    focusedCase.value = undefined;
};
watch(
    [() => props.rows, () => props.branchId],
    async ([rows, branch], [, previousBranch]) => {
        if (!props.enabled || typeof document === 'undefined') {
            return;
        }

        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const identity =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-count-case]')?.dataset
                      .countCase
                : active === document.body
                  ? focusedCase.value
                  : undefined;
        focusedCase.value = undefined;

        if (
            branch !== previousBranch ||
            !rows.some((row) => row.public_id === selected.value)
        ) {
            selected.value = null;
        }

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

        const trigger =
            branch === previousBranch
                ? Array.from(
                      list.value?.querySelectorAll<HTMLButtonElement>(
                          '[data-case-trigger]',
                      ) ?? [],
                  ).find((button) => button.dataset.caseTrigger === identity)
                : undefined;
        (trigger ?? list.value)?.focus();
    },
);
</script>

<template>
    <template v-if="!enabled">
        <slot v-for="row in rows" :key="row.public_id" :row="row" />
    </template>
    <section
        v-else
        ref="list"
        tabindex="-1"
        :aria-label="listLabel ?? 'Stock count cases'"
        :aria-busy="busy"
        class="overflow-hidden rounded-2xl border border-border bg-card"
        @focusin="rememberFocus"
        @focusout="leaveFocus"
    >
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            No matching cases.
        </p>
        <div
            v-for="row in rows"
            :key="row.public_id"
            :data-count-case="row.public_id"
            class="border-b border-border last:border-b-0"
        >
            <button
                type="button"
                :data-case-trigger="row.public_id"
                :aria-expanded="selected === row.public_id"
                :aria-controls="`${id}-${row.public_id}`"
                :disabled="busy"
                class="grid min-h-11 w-full min-w-0 gap-3 p-4 text-left hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60 lg:min-h-9 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.2fr)]"
                @click="
                    !busy &&
                    (selected =
                        selected === row.public_id ? null : row.public_id)
                "
            >
                <span class="flex min-w-0 items-start gap-2">
                    <ChevronRight
                        class="mt-0.5 size-4 shrink-0 transition-transform"
                        :class="{ 'rotate-90': selected === row.public_id }"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 space-y-1"
                        ><span class="block text-xs text-muted-foreground">{{
                            caseLabel(row.kind)
                        }}</span
                        ><span class="block font-medium break-all">{{
                            row.sku_code
                        }}</span
                        ><span
                            class="block text-sm break-words text-muted-foreground"
                            >Batch {{ row.batch_number }}</span
                        ></span
                    >
                </span>
                <span class="min-w-0"
                    ><span class="block text-xs text-muted-foreground"
                        >Location</span
                    ><span class="mt-1 block text-sm break-words">{{
                        row.location
                    }}</span></span
                >
                <span class="min-w-0"
                    ><span class="block text-xs text-muted-foreground"
                        >Status</span
                    ><span class="mt-1 block text-sm font-medium break-words">{{
                        states[row.state] ?? row.state
                    }}</span
                    ><span
                        v-if="row.blind"
                        class="mt-1 block text-xs text-muted-foreground"
                        >Blind count · system quantity hidden</span
                    ><slot name="summary" :row="row"
                /></span>
            </button>
            <div
                :id="`${id}-${row.public_id}`"
                v-show="selected === row.public_id"
                class="border-t border-border bg-muted/20 p-3 sm:p-4"
            >
                <slot :row="row" />
            </div>
        </div>
    </section>
</template>
