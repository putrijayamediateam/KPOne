<script setup lang="ts">
/**
 * STK-45: "My reports (N)", a closed disclosure near the stock list, so people find the outcome where they reported
 * from. It always shows, with an empty line when there is nothing (Daniel). States are a neutral dot plus words.
 */
import { ChevronDown } from '@lucide/vue';
import { StatusBadge } from '@/components/ui/status';

export type MyDifferenceReport = {
    id: number;
    item: string;
    skuCode: string;
    batchNumber: string;
    location: string;
    observed: string;
    reportedAt: string;
    state: string;
};

defineProps<{ reports: { rows: MyDifferenceReport[]; total: number } }>();

const observedLabels: Record<string, string> = {
    fewer: 'Fewer than KPOne shows',
    more: 'More than KPOne shows',
    damaged_or_expired: 'Some are damaged or expired',
    unsure: 'Not sure; please count it',
};

const stateLabels: Record<string, string> = {
    awaiting_count: 'Waiting for count',
    awaiting_recount: 'Being recounted',
    awaiting_approval: 'Waiting for approval',
    closed_zero: 'No difference found',
    posted: 'Approved',
    stale: 'Needs a new report',
};
</script>

<template>
    <details
        class="group rounded-lg border bg-card"
        data-testid="my-difference-reports"
    >
        <summary
            class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-3 text-sm font-medium focus-visible:rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9 [&::-webkit-details-marker]:hidden"
        >
            My reports ({{ reports.total }})
            <ChevronDown
                class="ml-auto size-4 shrink-0 transition-transform group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>
        <div class="px-3 pb-3">
            <p
                v-if="reports.rows.length === 0"
                class="text-sm text-muted-foreground"
            >
                You have not reported anything at this branch.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="report in reports.rows"
                    :key="report.id"
                    class="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                >
                    <div class="min-w-0 text-sm">
                        <p class="font-medium break-words">
                            {{ report.item }}
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ report.skuCode }}</span
                            >
                        </p>
                        <p class="text-xs break-words text-muted-foreground">
                            Batch {{ report.batchNumber }} ·
                            {{ report.location }} ·
                            {{
                                observedLabels[report.observed] ??
                                report.observed
                            }}
                            · {{ report.reportedAt }}
                        </p>
                        <p
                            v-if="report.state === 'stale'"
                            class="text-xs text-muted-foreground"
                        >
                            Stock changed before the count. Report again if it
                            still looks wrong.
                        </p>
                    </div>
                    <StatusBadge
                        :status="report.state"
                        :label="stateLabels[report.state] ?? report.state"
                        tone="neutral"
                        dot
                    />
                </li>
            </ul>
            <p
                v-if="reports.total > reports.rows.length"
                class="mt-2 text-xs text-muted-foreground"
            >
                Showing your latest {{ reports.rows.length }} of
                {{ reports.total }}.
            </p>
        </div>
    </details>
</template>
