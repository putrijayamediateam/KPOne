<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { nextTick, ref, useId, watch } from 'vue';
import { StatusBadge } from '@/components/ui/status';
import { trimQuantity } from '@/lib/supplier-terms';
import type { InventoryDocument } from '@/types/inventory-operations';

const props = defineProps<{
    rows: InventoryDocument[];
    branchId: number;
    busy: boolean;
}>();
const id = useId();
const list = ref<HTMLElement | null>(null);
const selected = ref<string | null>(null);
watch(
    [() => props.rows, () => props.branchId],
    async ([rows, branch], [, previousBranch]) => {
        const active =
            typeof document === 'undefined'
                ? null
                : (document.activeElement as HTMLElement | null);
        const focusedRequest =
            active && list.value?.contains(active)
                ? active.closest<HTMLElement>('[data-request-row]')?.dataset
                      .requestRow
                : undefined;

        if (
            branch !== previousBranch ||
            !rows.some((row) => row.publicId === selected.value)
        ) {
            selected.value = null;
        }

        if (focusedRequest) {
            await nextTick();

            if (
                document.activeElement !== active &&
                document.activeElement !== document.body
            ) {
                return;
            }

            if (active?.isConnected && active.getClientRects().length) {
                return;
            }

            const trigger =
                branch === previousBranch
                    ? Array.from(
                          list.value?.querySelectorAll<HTMLButtonElement>(
                              'button[data-request-trigger]',
                          ) ?? [],
                      ).find(
                          (button) =>
                              button.dataset.requestTrigger === focusedRequest,
                      )
                    : undefined;
            (trigger ?? list.value)?.focus();
        }
    },
);
const quantity = (value: string | null | undefined, unit?: string): string =>
    value === null || value === undefined
        ? '—'
        : `${trimQuantity(value)}${unit ? ` ${unit}` : ''}`;
const openDifferences = (row: InventoryDocument): number =>
    (row.differences ?? []).filter((difference) => difference.status === 'open')
        .length;
</script>

<template>
    <section
        ref="list"
        tabindex="-1"
        aria-label="Recent stock requests and transfers"
        :aria-busy="busy"
        class="@container overflow-hidden rounded-2xl border border-border bg-card [&_button]:min-h-11 [&_button]:min-w-11 lg:[&_button]:min-h-9 lg:[&_button]:min-w-9 [&_input:not([type=radio])]:min-h-11 lg:[&_input:not([type=radio])]:min-h-9"
    >
        <div class="border-b px-4 py-3">
            <h4 class="text-sm font-semibold">Recent requests</h4>
            <p class="mt-1 text-xs text-muted-foreground">
                Up to 50 recent requests visible at this branch. Open a request
                to review its lines and available actions.
            </p>
        </div>
        <p v-if="rows.length === 0" class="p-6 text-sm text-muted-foreground">
            No recent stock requests for this branch.
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="row in rows"
                :key="row.publicId"
                :data-request-row="row.publicId"
            >
                <div class="px-2 lg:px-4">
                    <button
                        type="button"
                        :disabled="busy"
                        :data-request-trigger="row.publicId"
                        :aria-expanded="selected === row.publicId"
                        :aria-controls="`${id}-request-${row.publicId}`"
                        class="grid w-full grid-cols-1 items-start gap-3 rounded-lg p-3 text-left hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none @2xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1.5fr)_minmax(0,1fr)] @2xl:items-center @2xl:px-0 @2xl:py-4"
                        @click="
                            !busy &&
                            (selected =
                                selected === row.publicId ? null : row.publicId)
                        "
                    >
                        <span class="flex min-w-0 items-start gap-3"
                            ><ChevronRight
                                aria-hidden="true"
                                class="mt-0.5 size-4 shrink-0 transition-transform"
                                :class="
                                    selected === row.publicId ? 'rotate-90' : ''
                                "
                            /><span class="min-w-0"
                                ><span
                                    class="block font-mono text-sm font-semibold break-words"
                                    >Request {{ row.number }}</span
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >{{ row.lines.length }}
                                    {{
                                        row.lines.length === 1
                                            ? 'line'
                                            : 'lines'
                                    }}</span
                                ></span
                            ></span
                        >
                        <span class="min-w-0 space-y-1 text-sm break-words"
                            ><span class="block"
                                ><span class="text-xs text-muted-foreground"
                                    >From </span
                                >{{ row.source }}</span
                            ><span class="block"
                                ><span class="text-xs text-muted-foreground"
                                    >To </span
                                >{{ row.destination }}</span
                            ></span
                        >
                        <span class="flex min-w-0 flex-wrap gap-1"
                            ><StatusBadge
                                class="max-w-full break-words whitespace-normal! [&>span]:shrink-0"
                                :status="row.status" /><StatusBadge
                                v-if="openDifferences(row)"
                                class="max-w-full break-words whitespace-normal! [&>span]:shrink-0"
                                status="pending"
                                tone="warning"
                                :label="`${openDifferences(row)} ${openDifferences(row) === 1 ? 'difference' : 'differences'} open`"
                        /></span>
                    </button>
                </div>
                <div
                    v-show="selected === row.publicId"
                    :id="`${id}-request-${row.publicId}`"
                    class="space-y-4 border-t bg-muted/20 p-4 lg:px-7"
                >
                    <div>
                        <h5 class="text-sm font-medium">Request lines</h5>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Quantities use each item's stock unit. A dash means
                            no quantity has been recorded yet.
                        </p>
                    </div>
                    <ul
                        class="divide-y divide-border rounded-xl border bg-card"
                    >
                        <li
                            v-for="line in row.lines"
                            :key="line.publicId"
                            class="space-y-3 p-3"
                        >
                            <div>
                                <p class="text-sm font-medium break-words">
                                    {{ line.item ?? line.sku }}
                                </p>
                                <p
                                    class="mt-1 font-mono text-xs break-words text-muted-foreground"
                                >
                                    {{ line.sku }}
                                </p>
                            </div>
                            <dl
                                class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm @lg:grid-cols-4"
                            >
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Requested
                                    </dt>
                                    <dd class="mt-1 break-words tabular-nums">
                                        {{
                                            quantity(
                                                line.requestedQuantity,
                                                line.stockUnit,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Dispatched
                                    </dt>
                                    <dd class="mt-1 break-words tabular-nums">
                                        {{
                                            quantity(
                                                line.dispatchedQuantity,
                                                line.stockUnit,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Received
                                    </dt>
                                    <dd class="mt-1 break-words tabular-nums">
                                        {{
                                            quantity(
                                                line.receivedQuantity,
                                                line.stockUnit,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Batch
                                    </dt>
                                    <dd class="mt-1 font-mono break-words">
                                        {{ line.batch ?? '—' }}
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    </ul>
                    <slot name="details" :row="row" />
                </div>
            </li>
        </ul>
    </section>
</template>
