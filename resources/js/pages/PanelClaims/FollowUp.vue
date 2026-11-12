<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';

type FollowUpClaim = {
    publicId: string;
    claimNo: string;
    patientName: string;
    invoiceNumber: string;
    panelName: string;
    status: string;
    amountLabel: string;
    amountSen: number;
    lastAction: string;
    lastActionDate: string;
    lastActionBy: string;
    daysSinceAction: number;
    followUpDays: number;
    showUrl: string;
};

const props = defineProps<{
    branch: { id: number; name: string };
    filters: { panel: string };
    panels: { code: string; name: string; followUpDays: number }[];
    claims: {
        data: FollowUpClaim[];
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();

const go = (panel: string, page = 1) =>
    router.get(
        '/panel-claims/follow-up',
        {
            ...(panel ? { panel } : {}),
            ...(page > 1 ? { page } : {}),
        },
        { preserveScroll: true, replace: true },
    );
</script>

<template>
    <Head title="Follow-up list" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <Link
            href="/panel-claims"
            class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground underline-offset-2 hover:text-foreground hover:underline max-lg:min-h-11"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            Back to Panel Claims
        </Link>
        <PageHeader
            title="Follow-up list"
            :description="`${branch.name} · Open Panel claims with no new action for longer than their Panel's follow-up days. Follow them up on the Panel's portal.`"
        />
        <div class="flex flex-wrap items-end gap-3">
            <label
                class="grid min-w-0 gap-1.5 text-[13px] font-medium text-foreground max-sm:w-full"
            >
                Panel
                <select
                    :value="filters.panel"
                    class="h-9 w-full min-w-0 rounded-lg border border-input bg-card px-3 text-sm font-normal outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 max-lg:h-11 max-lg:text-base sm:min-w-72 dark:bg-field-fill/30"
                    aria-label="Panel"
                    @change="go(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">All Panels</option>
                    <option
                        v-for="panel in panels"
                        :key="panel.code"
                        :value="panel.code"
                    >
                        {{ panel.name }} (after {{ panel.followUpDays }}
                        {{ panel.followUpDays === 1 ? 'day' : 'days' }})
                    </option>
                </select>
            </label>
        </div>
        <p class="text-sm text-muted-foreground">
            An action is a submission, query, decision, amount change, note,
            payment or photo. Paid and rejected claims are not listed. A claim
            marked “Prepared (not yet submitted)” still needs submitting on the
            Panel's portal. Change a Panel's follow-up days in Checkout
            settings.
        </p>

        <EmptyState
            v-if="claims.data.length === 0"
            title="No claim needs a follow-up right now."
            data-testid="follow-up-empty"
        />
        <ul
            v-else
            class="divide-y rounded-xl border bg-card"
            aria-label="Claims to follow up"
        >
            <li
                v-for="claim in claims.data"
                :key="claim.publicId"
                class="grid gap-2 p-4 text-sm md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_auto] md:items-center"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-1.5">
                        <Link
                            :href="claim.showUrl"
                            class="inline-flex items-center font-medium underline-offset-2 hover:underline max-lg:min-h-11"
                        >
                            Claim {{ claim.claimNo }}
                        </Link>
                        <span class="font-mono text-xs text-muted-foreground">
                            · {{ claim.invoiceNumber }}</span
                        >
                    </div>
                    <p class="wrap-anywhere">
                        {{ claim.patientName }} · {{ claim.panelName }}
                    </p>
                    <p
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                    >
                        <StatusBadge
                            :status="claim.status"
                            :label="claim.status"
                        />
                        <span class="tabular-nums"
                            >{{ claim.amountLabel }}
                            {{ myr(claim.amountSen) }}</span
                        >
                    </p>
                </div>
                <div class="min-w-0">
                    <p class="font-medium">{{ claim.lastAction }}</p>
                    <p class="text-xs wrap-anywhere text-muted-foreground">
                        {{ claim.lastActionDate }}
                        <template v-if="claim.lastActionBy">
                            · by {{ claim.lastActionBy }}</template
                        >
                    </p>
                </div>
                <p
                    class="font-medium text-warning-strong tabular-nums md:text-right"
                >
                    {{ claim.daysSinceAction }}
                    {{ claim.daysSinceAction === 1 ? 'day' : 'days' }}, no
                    action
                    <span
                        class="block text-xs font-normal text-muted-foreground"
                    >
                        Follow up after {{ claim.followUpDays }}
                        {{ claim.followUpDays === 1 ? 'day' : 'days' }}
                    </span>
                </p>
            </li>
        </ul>
        <CompactPagination
            v-if="claims.total > 0"
            :current-page="claims.currentPage"
            :last-page="claims.lastPage"
            :total="claims.total"
            @change="(value) => go(props.filters.panel, value)"
        />
    </main>
</template>
