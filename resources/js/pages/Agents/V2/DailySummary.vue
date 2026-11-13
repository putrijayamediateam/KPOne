<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DailyLinaReport from '@/components/agents/v2/DailyLinaReport.vue';
import { DateField } from '@/components/ui/date-field';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { plural, ringgit } from '@/lib/agent-summary';
import type { Lina, Stamp } from '@/lib/agent-summary';
import { formatDate, formatDateTime } from '@/lib/presentation';

/**
 * Version 2 Daily summary (shown only in V2; Classic is `pages/Agents/DailySummary.vue`, untouched). Same props, same
 * route (`/agents/daily-summary?date=`), same permissions: the server decides which parts a viewer may see, and a part
 * that arrives as null is simply not drawn. Totals only; no colour carries a direction, the words do.
 */
type HanaCount = { total: number; pending: number };
type Hana = Stamp & {
    date: string;
    counts: Record<'restock' | 'expiry' | 'transfer' | 'slowMoving', HanaCount>;
};
type DueItem = {
    sku: string;
    skuCode: string;
    item: string;
    locationLabel: string;
    lastCompletedCountDate: string | null;
};
type Rizal = Stamp & {
    countsDue: number;
    openCases: number;
    unresolvedVariances: number;
    due: DueItem[];
};
type Faris = Stamp & { allClear: boolean; summary: string };
type BranchBlock = {
    code: string;
    name: string;
    linaMorning: Lina | null;
    linaAfternoon: Lina | null;
    hana: Hana | null;
    rizal: Rizal | null;
};

const props = defineProps<{
    summary: {
        date: string;
        today: string;
        earliest: string;
        canInbox: boolean;
        activeBranchCode: string | null;
        branches: BranchBlock[];
        faris: Faris | null;
    };
}>();

const chosen = ref(props.summary.date);
const changed = computed(() => chosen.value !== props.summary.date);
const show = () => {
    router.get(
        '/agents/daily-summary',
        { date: chosen.value },
        { preserveScroll: true },
    );
};

const slotLabel = (slot: Stamp['slot']) =>
    slot === '0700' ? '07:00 run' : '14:00 update';
const builtLine = (stamp: Stamp) =>
    stamp.builtAt
        ? `${slotLabel(stamp.slot)}, built ${formatDateTime(stamp.builtAt)}`
        : slotLabel(stamp.slot);

const hanaLabels: Record<keyof Hana['counts'], string> = {
    restock: 'Restock',
    expiry: 'Expiry warning',
    transfer: 'Transfer',
    slowMoving: 'Slow-moving',
};
const hanaItems = (hana: Hana) =>
    (Object.keys(hanaLabels) as (keyof Hana['counts'])[]).map((key) => ({
        label: hanaLabels[key],
        value: `${hana.counts[key].total} made, ${hana.counts[key].pending} waiting for a decision`,
    }));
// Counts due is one item at one location; a difference is a count that did not match the books.
const rizalItems = (rizal: Rizal) => [
    { label: 'Counts due', value: plural(rizal.countsDue, 'count') },
    { label: 'Open count cases', value: plural(rizal.openCases, 'case') },
    {
        label: 'Differences not yet resolved',
        value: plural(rizal.unresolvedVariances, 'difference'),
    },
];
const lastCounted = (item: DueItem) =>
    item.lastCompletedCountDate
        ? `Last counted ${formatDate(item.lastCompletedCountDate)}`
        : 'Never counted';

const linaHeading = (lina: Lina) =>
    lina.kind === 'whole_day'
        ? `07:00 report, covers ${formatDate(lina.date)}`
        : `14:00 update, today so far (${formatDate(lina.date)})`;
// Today so far first once the 14:00 update exists. The newest report is open; an older one is a closed bar below it.
const reports = (branch: BranchBlock): Lina[] =>
    [branch.linaAfternoon, branch.linaMorning].filter(
        (lina): lina is Lina => lina !== null,
    );
// One line on the closed bar: the newest report's headline figures.
const headline = (branch: BranchBlock): string => {
    const lina = branch.linaAfternoon ?? branch.linaMorning;

    if (lina === null) {
        return 'No report yet';
    }

    const parts: string[] = [];

    if (lina.clinic) {
        parts.push(`${lina.clinic.now.registered} registered`);
    }

    if (lina.money) {
        parts.push(`Net sales ${ringgit(lina.money.now.netSalesSen)}`);
    }

    return parts.length > 0 ? parts.join(' · ') : 'Report ready';
};
// The active branch opens; when it is not listed (or none is chosen), the first branch opens.
const openFirst = (code: string, index: number) =>
    props.summary.branches.some(
        (branch) => branch.code === props.summary.activeBranchCode,
    )
        ? code === props.summary.activeBranchCode
        : index === 0;

usePageCrumbs(() => [{ title: 'Agents' }, { title: 'Daily summary' }]);

const RIZAL_SHOWN = 5;
const showAllDue = ref<Record<string, boolean>>({});
const dueShown = (branch: BranchBlock) =>
    showAllDue.value[branch.code]
        ? branch.rizal!.due
        : branch.rizal!.due.slice(0, RIZAL_SHOWN);

const outline =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background max-sm:w-full lg:h-9';
const filled =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background max-sm:w-full lg:h-9';
</script>

<template>
    <Head title="Daily summary" />
    <PageFrame>
        <V2WorkLayout
            title="Daily summary"
            description="Built by AI agents at 07:00 and updated at 14:00. Figures are totals only."
        >
            <template #main>
                <p
                    class="text-[15px] leading-[22px] text-muted-foreground"
                    data-test="summary-notice"
                >
                    AI agents read the clinic's totals and write this page; they
                    change nothing and send no messages. You see only the parts
                    your role can already see elsewhere in KPOne.
                </p>

                <form
                    class="flex flex-wrap items-end gap-3"
                    data-test="summary-date"
                    @submit.prevent="show"
                >
                    <V2Field
                        v-slot="{ id }"
                        label="Summary on"
                        class="w-full sm:w-52"
                    >
                        <DateField
                            :id="id"
                            v-model="chosen"
                            :min="summary.earliest"
                            :max="summary.today"
                        />
                    </V2Field>
                    <button
                        type="submit"
                        :class="changed ? filled : outline"
                        data-test="summary-show"
                    >
                        Show
                    </button>
                </form>

                <section
                    v-if="summary.faris"
                    aria-labelledby="faris-heading"
                    class="flex min-w-0 flex-col gap-1.5 rounded-[14px] border border-border bg-card p-4"
                    data-test="summary-faris"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="faris-heading" class="text-base font-semibold">
                            System health
                        </h2>
                        <V2Chip tone="info" label="AI agent" />
                        <span class="text-sm text-muted-foreground">Faris</span>
                    </div>
                    <p class="flex flex-wrap items-center gap-2 text-[15px]">
                        <V2Chip
                            :tone="
                                summary.faris.allClear ? 'success' : 'warning'
                            "
                            :label="
                                summary.faris.allClear
                                    ? 'All clear'
                                    : 'Needs a look'
                            "
                        />
                        <span>{{ summary.faris.summary }}</span>
                    </p>
                    <p class="text-[13px] leading-[18px] text-muted-foreground">
                        {{ builtLine(summary.faris) }}
                    </p>
                </section>

                <p
                    v-if="summary.branches.length === 0"
                    class="rounded-[14px] border border-dashed border-border p-5 text-[15px] leading-[22px] text-muted-foreground"
                    data-test="summary-no-branch"
                >
                    No branch to show. The daily summary lists the branches you
                    work at today.
                </p>

                <V2DisclosureBar
                    v-for="(branch, index) in summary.branches"
                    :key="branch.code"
                    :title="`${branch.name} · ${headline(branch)}`"
                    :default-open="openFirst(branch.code, index)"
                    data-test="summary-branch"
                >
                    <div class="flex min-w-0 flex-col gap-6">
                        <template
                            v-for="(lina, reportIndex) in reports(branch)"
                            :key="lina.slot"
                        >
                            <DailyLinaReport
                                v-if="reportIndex === 0"
                                :lina="lina"
                                :heading="linaHeading(lina)"
                                include-panel-stock
                            />
                            <V2DisclosureBar
                                v-else
                                :title="`${linaHeading(lina)} · Lina, AI agent`"
                            >
                                <DailyLinaReport :lina="lina" />
                            </V2DisclosureBar>
                        </template>
                        <p
                            v-if="!branch.linaMorning && !branch.linaAfternoon"
                            class="text-[15px] text-muted-foreground"
                            data-test="lina-missing"
                        >
                            No report from Lina for this date yet.
                        </p>

                        <section
                            v-if="branch.hana"
                            class="flex min-w-0 flex-col gap-2"
                            data-test="summary-hana"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold">
                                    Stock suggestions
                                </h3>
                                <V2Chip tone="info" label="AI agent" />
                                <span class="text-sm text-muted-foreground"
                                    >Hana</span
                                >
                            </div>
                            <p
                                class="text-[13px] leading-[18px] text-muted-foreground"
                            >
                                {{ builtLine(branch.hana) }}.
                            </p>
                            <V2KeyValueList :items="hanaItems(branch.hana)" />
                            <div
                                v-if="summary.canInbox"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <Link
                                    href="/agents/inbox"
                                    :class="outline"
                                    data-test="summary-inbox-link"
                                >
                                    Open the approval inbox
                                </Link>
                                <span
                                    class="text-[13px] leading-[18px] text-muted-foreground"
                                    >The inbox lists your active branch.</span
                                >
                            </div>
                        </section>

                        <section
                            v-if="branch.rizal"
                            class="flex min-w-0 flex-col gap-2"
                            data-test="summary-rizal"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold">
                                    Stock counts
                                </h3>
                                <V2Chip tone="info" label="AI agent" />
                                <span class="text-sm text-muted-foreground"
                                    >Rizal</span
                                >
                            </div>
                            <p
                                class="text-[13px] leading-[18px] text-muted-foreground"
                            >
                                {{ builtLine(branch.rizal) }}.
                            </p>
                            <V2KeyValueList :items="rizalItems(branch.rizal)" />
                            <ul
                                v-if="branch.rizal.due.length > 0"
                                class="flex flex-col gap-2"
                                data-test="summary-rizal-due"
                            >
                                <li
                                    v-for="item in dueShown(branch)"
                                    :key="`${item.sku}-${item.locationLabel}`"
                                    class="rounded-[10px] border border-border px-3 py-2 text-sm"
                                >
                                    <span class="font-medium break-words">{{
                                        item.item
                                    }}</span>
                                    <span
                                        class="block text-[13px] leading-[18px] text-muted-foreground"
                                        >{{ item.skuCode }} ·
                                        {{ item.locationLabel }} ·
                                        {{ lastCounted(item) }}</span
                                    >
                                </li>
                            </ul>
                            <div
                                v-if="branch.rizal.due.length > RIZAL_SHOWN"
                                class="flex"
                            >
                                <button
                                    type="button"
                                    :class="outline"
                                    :aria-expanded="!!showAllDue[branch.code]"
                                    data-test="summary-rizal-more"
                                    @click="
                                        showAllDue[branch.code] =
                                            !showAllDue[branch.code]
                                    "
                                >
                                    {{
                                        showAllDue[branch.code]
                                            ? 'Show fewer'
                                            : `and ${branch.rizal.due.length - RIZAL_SHOWN} more`
                                    }}
                                </button>
                            </div>
                        </section>
                    </div>
                </V2DisclosureBar>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
