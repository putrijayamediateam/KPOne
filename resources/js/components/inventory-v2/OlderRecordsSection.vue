<script setup lang="ts">
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate } from '@/lib/presentation';
import { trimQuantity } from '@/lib/supplier-terms';
import type { InventoryOperations } from '@/types/inventory-operations';

/**
 * Inventory V2 page 2c: the legacy stocktakes and manual adjustments, read only, as V2 tables (STK-6A closed both;
 * new counts and write-offs go through the governed pages). Nothing here changes stock.
 */
defineProps<{ operations: InventoryOperations }>();

const STATUS: Record<string, string> = {
    draft: 'Draft',
    counting: 'Counting',
    review: 'In review',
    posted: 'Posted',
    cancelled: 'Cancelled',
};
const n = (value: unknown) => trimQuantity(String(value ?? '0'));
</script>

<template>
    <div class="space-y-5" data-test="v2-older-records">
        <p class="text-sm text-muted-foreground">
            Read only. New counts and write-offs use the governed pages:
            <a
                href="/inventory/counts"
                class="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline lg:min-h-0"
                >Stock count</a
            >
            and
            <a
                href="/inventory/writeoffs"
                class="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline lg:min-h-0"
                >Write-off</a
            >.
        </p>

        <section class="space-y-3" aria-labelledby="v2-old-stocktakes-title">
            <h3 id="v2-old-stocktakes-title" class="text-[15px] font-semibold">
                Old stocktakes
            </h3>
            <V2Table
                label="Old stocktakes"
                :columns="3"
                cards="xl"
                min-width="640px"
                :empty="operations.stocktakes.length === 0"
                empty-message="No old stocktakes."
            >
                <template #head>
                    <tr>
                        <th scope="col">Stocktake</th>
                        <th scope="col">Lines</th>
                        <th scope="col">Status</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in operations.stocktakes"
                        :key="row.publicId"
                    >
                        <td data-label="Stocktake" data-primary>
                            <span class="font-medium break-words">{{
                                row.number
                            }}</span>
                        </td>
                        <td data-label="Lines">
                            <ul class="grid min-w-0 gap-1 text-sm">
                                <li
                                    v-for="line in row.lines"
                                    :key="line.publicId"
                                    class="break-words"
                                >
                                    {{ line.sku }} · batch {{ line.batch }} ·
                                    expected {{ n(line.expectedQuantity) }} ·
                                    counted
                                    {{
                                        line.physicalQuantity == null
                                            ? 'not recorded'
                                            : n(line.physicalQuantity)
                                    }}
                                </li>
                            </ul>
                        </td>
                        <td data-label="Status">
                            <V2Chip
                                tone="neutral"
                                :label="STATUS[row.status] ?? row.status"
                            />
                        </td>
                    </tr>
                </template>
            </V2Table>
        </section>

        <section class="space-y-3" aria-labelledby="v2-old-adjustments-title">
            <h3 id="v2-old-adjustments-title" class="text-[15px] font-semibold">
                Old manual adjustments
            </h3>
            <V2Table
                label="Old manual adjustments"
                :columns="4"
                cards="xl"
                min-width="640px"
                :empty="operations.adjustments.length === 0"
                empty-message="No old adjustments."
            >
                <template #head>
                    <tr>
                        <th scope="col">Item and batch</th>
                        <th scope="col">Change</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Posted</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in operations.adjustments"
                        :key="row.publicId"
                    >
                        <td data-label="Item and batch" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    row.sku
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    >Batch {{ row.batch }} ·
                                    {{ row.location }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Change">
                            {{ row.direction === 'out' ? '−' : '+'
                            }}{{ n(row.quantity) }}
                        </td>
                        <td data-label="Reason">
                            {{ formatInventoryReason(row.reasonCode) }}
                        </td>
                        <td data-label="Posted">
                            <span class="whitespace-nowrap">{{
                                formatDate(row.postedAt)
                            }}</span>
                        </td>
                    </tr>
                </template>
            </V2Table>
        </section>
    </div>
</template>
