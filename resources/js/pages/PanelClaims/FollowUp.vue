<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import PanelWorkspaceNav from '@/components/panel/PanelWorkspaceNav.vue';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { myr } from '@/types/billing';

type FollowUpClaim = {
    publicId: string;
    claimNo: string;
    supplementaryTo?: string | null;
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
        <PageHeader
            title="Follow-up list"
            description="Open Panel claims with no new action for longer than their Panel's follow-up days. Follow them up on the Panel's portal."
        />
        <PanelWorkspaceNav active="follow-up" />
        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_240px]">
            <section class="min-w-0 space-y-4" aria-label="Follow-up claims">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="grid min-w-0 gap-1 text-xs">
                        Panel
                        <select
                            :value="filters.panel"
                            class="min-h-11 w-full min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                            aria-label="Panel"
                            @change="
                                go(($event.target as HTMLSelectElement).value)
                            "
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
                    An action is a submission, query, decision, amount change,
                    note, payment or photo. Change a Panel's follow-up days in
                    Checkout settings.
                </p>
                <p class="text-sm text-muted-foreground">
                    Paid and rejected claims are not listed.
                </p>
                <p class="text-sm text-muted-foreground">
                    Prepared claims that have never been submitted are on the
                    Overdue tab. This list shows claims due for follow-up after
                    a previous submission.
                </p>

                <p
                    v-if="claims.data.length === 0"
                    class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
                    data-testid="follow-up-empty"
                >
                    {{
                        filters.panel
                            ? 'No claim for the selected Panel needs a follow-up right now.'
                            : 'No claim needs a follow-up right now.'
                    }}
                </p>
                <ul
                    v-else
                    class="divide-y rounded-xl border bg-card shadow-card"
                    aria-label="Claims to follow up"
                >
                    <li
                        v-for="claim in claims.data"
                        :key="claim.publicId"
                        class="grid gap-2 p-4 text-sm lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_auto] lg:items-center"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="claim.showUrl"
                                class="inline-flex min-h-11 items-center rounded-sm font-medium wrap-anywhere underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                            >
                                Claim {{ claim.claimNo
                                }}<template v-if="claim.supplementaryTo">
                                    · Supplementary to
                                    {{ claim.supplementaryTo }}</template
                                >
                            </Link>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                · {{ claim.invoiceNumber }}</span
                            >
                            <p class="wrap-anywhere">
                                {{ claim.patientName }} · {{ claim.panelName }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ claim.status }} · {{ claim.amountLabel }}
                                {{ myr(claim.amountSen) }}
                            </p>
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium">{{ claim.lastAction }}</p>
                            <p
                                class="text-xs wrap-anywhere text-muted-foreground"
                            >
                                {{ claim.lastActionDate }}
                                <template v-if="claim.lastActionBy">
                                    · by {{ claim.lastActionBy }}</template
                                >
                            </p>
                        </div>
                        <p
                            class="font-medium text-destructive tabular-nums lg:text-right"
                        >
                            {{ claim.daysSinceAction }}
                            {{ claim.daysSinceAction === 1 ? 'day' : 'days' }},
                            no action
                            <span
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                Follow up after {{ claim.followUpDays }}
                                {{ claim.followUpDays === 1 ? 'day' : 'days' }}
                            </span>
                        </p>
                    </li>
                </ul>
                <div class="[&_button]:min-h-11 lg:[&_button]:min-h-9">
                    <CompactPagination
                        v-if="claims.total > 0"
                        :current-page="claims.currentPage"
                        :last-page="claims.lastPage"
                        :total="claims.total"
                        @change="(value) => go(props.filters.panel, value)"
                    />
                </div>
            </section>
            <aside class="min-w-0 space-y-4" aria-label="Follow-up context">
                <section class="rounded-xl border bg-card p-4 shadow-card">
                    <h2 class="text-sm font-semibold">
                        Current follow-up list
                    </h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                Matching claims
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ claims.total }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                Claims on this page
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ claims.data.length }}
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-muted-foreground">
                        Page {{ claims.currentPage }} of {{ claims.lastPage }}.
                        {{ branch.name }} ·
                        {{ filters.panel ? 'Selected Panel' : 'All Panels' }}.
                        Counts follow the current branch and Panel filter.
                    </p>
                </section>
                <details open class="rounded-xl border bg-card p-4 shadow-card">
                    <summary
                        class="min-h-11 cursor-pointer rounded-sm text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                    >
                        Before following up
                    </summary>
                    <div class="mt-2 space-y-3 text-sm text-muted-foreground">
                        <p>
                            Open the claim to review its latest action and
                            evidence before following up on the Panel's portal.
                        </p>
                        <p>
                            Each row uses its Panel's follow-up threshold. A new
                            recorded action can remove a claim from this list.
                        </p>
                        <p>
                            Claimed amounts awaiting a decision are different
                            from unpaid amounts after a decision. Keep the
                            amount label with each claim.
                        </p>
                    </div>
                </details>
            </aside>
        </div>
    </main>
</template>
