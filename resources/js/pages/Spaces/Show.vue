<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import MeHome from '@/components/spaces/MeHome.vue';
import SpaceAskPanel from '@/components/spaces/SpaceAskPanel.vue';
import SpaceAsOf from '@/components/spaces/SpaceAsOf.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useClockShortcut } from '@/composables/useClockShortcut';
import { useScrollStrip } from '@/composables/useScrollStrip';
import SpacePassThroughLayout from '@/layouts/SpacePassThroughLayout.vue';
import { V2_FRAMED_SPACES } from '@/lib/v2-spaces';
import { waitingTone } from '@/lib/waiting-time';

type Space = { key: string; title: string };

// The space shell draws its own chrome, so it opts out of the staff layouts without touching the shared layout map.
defineOptions({ layout: SpacePassThroughLayout });

type Billing = {
    branchName: string | null;
    asOf: string | null;
    payLater: {
        count: number;
        totalText: string;
        overdueCount: number;
        overdueText: string;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            invoiceNumber: string;
            patient: string;
            amountText: string;
            dueText: string;
            tone: 'normal' | 'amber' | 'red';
            href: string;
        }>;
    } | null;
    cashClosing: {
        status: string;
        statusText: string;
        expectedText: string;
        receiptCount: number;
        changedSinceApproval: boolean;
        href: string;
    } | null;
    awaiting: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            queueNumber: string | null;
            patient: string;
            registeredAt: string;
        }>;
    } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type FrontDesk = {
    branchName: string | null;
    asOf: string | null;
    waiting: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            visitNumber: string;
            queueNumber: string | null;
            patient: string;
            minutes: number;
            href: string;
        }>;
    } | null;
    glance: Record<string, number> | null;
    qrPending: { total: number; href: string } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type Consultation = {
    branchName: string | null;
    asOf: string | null;
    waiting: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            queueNumber: string;
            patient: string;
            urgent: boolean;
            notReady: string | null;
            minutes: number;
            href: string;
        }>;
    } | null;
    held: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            queueNumber: string;
            patient: string;
            heldText: string;
            tooLong: boolean;
            href: string;
        }>;
    } | null;
    next: { ready: boolean; text: string } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type PanelSpaceHome = {
    branchName: string | null;
    asOf: string | null;
    toClaim: {
        pending: number;
        overdue: number;
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            invoiceNumber: string;
            patient: string;
            panel: string;
            amountText: string;
            dueText: string;
            overdue: boolean;
            href: string;
        }>;
    } | null;
    followUp: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            claimNo: string;
            patient: string;
            panel: string;
            status: string;
            sinceText: string;
            href: string;
        }>;
    } | null;
    primaryAction: { title: string; href: string } | null;
    note: string | null;
    links: Array<{ title: string; href: string }>;
};

type StockRow = {
    sku: string;
    item: string;
    location: string;
    detail: string;
    tone: string;
};
type StockHome = {
    branchName: string | null;
    asOf: string | null;
    watch: {
        showLocation: boolean;
        lists: Array<{
            key: string;
            title: string;
            total: number;
            limit: number;
            seeAllHref: string;
            rows: StockRow[];
        }>;
    } | null;
    overdueReviews: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            sku: string;
            item: string;
            text: string;
            tone: string;
        }>;
    } | null;
    readiness: { openings: number; limited: boolean; href: string } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type HrHome = {
    branchName: string | null;
    asOf: string | null;
    approvals: {
        total: number;
        kinds: Array<{
            key: string;
            title: string;
            count: number;
            href: string;
            detail: string | null;
        }>;
    } | null;
    away: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{ name: string; text: string }>;
    } | null;
    roster: {
        label: string;
        status: string;
        shortDays: number;
        href: string;
    } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type MeHome = {
    branchName: string | null;
    asOf: string | null;
    greeting: { text: string; date: string } | null;
    time: { serverNow: string; timezone: string } | null;
    ring: {
        state: 'in' | 'out' | 'done';
        canPress: boolean;
        refusal: string | null;
        needsAttendancePage: boolean;
        serverNow: string;
        timezone: string;
        clockedInAt: string | null;
        clockedInLabel: string | null;
        doneLabel: string | null;
        shiftLabel: string | null;
        rosteredMinutes: number | null;
        onTime: boolean | null;
        lateMinutes: number | null;
    } | null;
    week: {
        days: Array<{
            day: string;
            date: string;
            result: string;
            word: string;
        }>;
        summary: string;
    } | null;
    tiles: Record<
        'leave' | 'claims' | 'roster' | 'attendance',
        { title: string; text: string; href: string } | null
    >;
    credentials: {
        total: number;
        rows: Array<{ name: string; words: string; expired: boolean }>;
    } | null;
    payslip: { period: string; issuedOn: string; href: string } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type ManagementHome = {
    branchName: string | null;
    asOf: string | null;
    staff: { active: number; inactive: number } | null;
    documents: {
        text: string;
        warning: boolean;
        attention: Array<{ name: string; text: string }>;
    } | null;
    primaryAction: { title: string; href: string } | null;
    links: Array<{ title: string; href: string }>;
};

type Pharmacy = {
    branchName: string | null;
    asOf: string | null;
    toDispense: {
        total: number;
        limit: number;
        seeAllHref: string;
        rows: Array<{
            visitNumber: string;
            queueNumber: string | null;
            patient: string;
            minutes: number;
            medicineCount: number;
            href: string;
        }>;
    } | null;
    returnsToCheck: { total: number } | null;
    stockWatch: {
        lowStock: number;
        expiring: number;
        expired: number;
        windowDays: number;
    } | null;
    links: Array<{ title: string; href: string }>;
};

const props = defineProps<{
    space: Space;
    spaces: Space[];
    existing?: { title: string; href: string } | null;
    questions?: Array<{ key: string; label: string }>;
    pharmacy?: Pharmacy | null;
    frontDesk?: FrontDesk | null;
    billing?: Billing | null;
    consultation?: Consultation | null;
    panel?: PanelSpaceHome | null;
    stock?: StockHome | null;
    hr?: HrHome | null;
    management?: ManagementHome | null;
    me?: MeHome | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user!);
const branches = computed(() => page.props.branchContext);
const hub = computed(
    () => page.props.workspace?.navigation.clinicManagement === true,
);

const waitLabel = (minutes: number): string | null =>
    ({ normal: null, amber: 'Long wait', red: 'Very long wait' })[
        waitingTone(minutes)
    ];

const primaryAction = computed(
    () =>
        props.frontDesk?.primaryAction ??
        props.billing?.primaryAction ??
        props.consultation?.primaryAction ??
        props.panel?.primaryAction ??
        props.stock?.primaryAction ??
        props.hr?.primaryAction ??
        props.management?.primaryAction ??
        null,
);

// DS-13: the Open list keeps only the rows the four tiles above it do not already cover.
const meLinks = computed(() => {
    const covered = new Set<string>();
    Object.values(props.me?.tiles ?? {}).forEach((t) => {
        if (t) {
            covered.add(t.href);
        }
    });

    return (props.me?.links ?? []).filter((l) => !covered.has(l.href));
});

const waitDuration = (minutes: number): string => {
    if (minutes < 1440) {
        return `${minutes} min`;
    }

    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    return `${days} ${days === 1 ? 'day' : 'days'}${hours > 0 ? ` ${hours} h` : ''}`;
};

const waitHead = (minutes: number): string =>
    `Waiting ${waitDuration(minutes)}${waitLabel(minutes) ? ',' : ''}`;

const groups = computed(() => {
    const layout: Array<[string, string[]]> = [
        ['Clinic', ['front_desk', 'pharmacy', 'consultation']],
        ['Money', ['billing', 'panel']],
        ['Operations', ['stock', 'hr', 'management']],
    ];

    return layout
        .map(([title, keys]) => ({
            title,
            items: props.spaces.filter((s) => keys.includes(s.key)),
        }))
        .filter((g) => g.items.length > 0);
});

// A person with no department space and no Clinic management link gets no empty rail: the sidebar (or the phone strip)
// is collapsed and the page stands alone, reached through the account menu.
const bare = computed(() => groups.value.length === 0 && !hub.value);

const changeBranch = (event: Event) => {
    const branchId = Number((event.target as HTMLSelectElement).value);

    if (branchId) {
        router.post(
            '/branch-context',
            { branch_id: branchId, return_to: page.url },
            { preserveScroll: true },
        );
    }
};

// 390 px: the nav is one scrolling row. The current space is brought to the start and the hidden edge fades.
const side = ref<HTMLElement | null>(null);
const { measure, fadeStyle } = useScrollStrip(side, '[aria-current="page"]');

// DS-12: on a phone a light dim layer sits behind the open account menu, as dialogs have.
const menuOpen = ref(false);

// DS-12: Me lives in the account menu, so it is not a nav item. A person whose only space is Me gets no space items.
const accountMenu = computed(() => page.props.workspace?.accountMenu);
const {
    load: loadClock,
    press: pressClock,
    label: clockLabel,
    visible: clockVisible,
} = useClockShortcut();
onMounted(() => {
    if (accountMenu.value?.clock) {
        void loadClock();
    }
});

// UX-24 part 1 (Daniel, 2026-11-13): Me home and Management sit in the V2 shell (its sidebar, search, branch switcher,
// Back to Classic and account menu replace this page's own), content in the page frame with a V2 header. The other
// spaces keep this page's own chrome until part 2. The teams' blocks below are unchanged.
// The shell itself is drawn by SpacePassThroughLayout, so this page keeps its markup; here it only drops its own chrome.
const v2Shell = computed(() =>
    V2_FRAMED_SPACES.includes(props.space.key)
        ? (page.props.workspace?.shell ?? null)
        : null,
);
</script>

<template>
    <Head :title="space.title" />
    <div
        class="space-shell"
        :class="{ 'is-bare': bare }"
        :data-v2-frame="v2Shell ? '' : undefined"
    >
        <div
            v-if="!v2Shell"
            ref="side"
            class="space-side"
            :style="fadeStyle"
            @scroll.passive="measure"
        >
            <Link href="/" class="space-logo"
                ><span class="space-logo-mark" aria-hidden="true">K</span
                >KPOne</Link
            >
            <nav aria-label="Spaces" class="space-nav">
                <div v-for="g in groups" :key="g.title" class="space-block">
                    <div class="space-group">{{ g.title }}</div>
                    <Link
                        v-for="s in g.items"
                        :key="s.key"
                        :href="`/spaces/${s.key}`"
                        class="space-item"
                        :class="{ 'is-on': s.key === space.key }"
                        :aria-current="s.key === space.key ? 'page' : undefined"
                    >
                        {{ s.title }}
                    </Link>
                </div>
                <div v-if="hub" class="space-block">
                    <div class="space-group">More</div>
                    <Link href="/clinic-management" class="space-item"
                        >Clinic management</Link
                    >
                </div>
            </nav>
        </div>
        <div
            v-if="menuOpen"
            class="fixed inset-0 z-40 bg-black/30 sm:hidden"
            aria-hidden="true"
        />
        <main
            id="main-content"
            class="space-main"
            :class="{
                'mx-auto w-full max-w-7xl': v2Shell,
            }"
            tabindex="-1"
        >
            <div v-if="!v2Shell" class="space-top">
                <label
                    v-if="branches?.active"
                    class="space-branch"
                    for="space-branch"
                >
                    <span class="space-muted">Branch</span>
                    <select
                        id="space-branch"
                        class="space-select"
                        :value="branches.active.id"
                        @change="changeBranch"
                    >
                        <option
                            v-for="b in branches.available"
                            :key="b.id"
                            :value="b.id"
                        >
                            {{ b.name }}
                        </option>
                    </select>
                </label>
                <button
                    v-if="
                        accountMenu?.clock && clockVisible && space.key !== 'me'
                    "
                    type="button"
                    class="space-clock"
                    data-test="clock-shortcut"
                    @click="pressClock()"
                >
                    {{ clockLabel }}
                </button>
                <DropdownMenu @update:open="menuOpen = $event">
                    <DropdownMenuTrigger :as-child="true">
                        <button
                            type="button"
                            class="space-account"
                            :aria-label="`Account menu, ${user.name}`"
                        >
                            <UserInfo :user="user" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="w-56 max-sm:[&_[role=menuitem]]:min-h-11"
                    >
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            <header
                v-if="v2Shell"
                class="flex flex-wrap items-start justify-between gap-3"
                data-test="v2-space-head"
            >
                <h1 class="v2-page-title">{{ space.title }}</h1>
                <div
                    v-if="
                        primaryAction ||
                        (accountMenu?.clock &&
                            clockVisible &&
                            space.key !== 'me')
                    "
                    class="flex flex-wrap items-center gap-2 max-[359px]:flex-col max-[359px]:items-stretch max-sm:w-full [&>*]:max-sm:grow"
                >
                    <Link
                        v-if="primaryAction"
                        :href="primaryAction.href"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none lg:h-9"
                        >{{ primaryAction.title }}</Link
                    >
                    <!-- Phones and tablets: the clock shortcut sits here, not in the top bar (Daniel). -->
                    <button
                        v-if="
                            accountMenu?.clock &&
                            clockVisible &&
                            space.key !== 'me'
                        "
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:hidden"
                        data-test="v2-space-clock"
                        @click="pressClock()"
                    >
                        {{ clockLabel }}
                    </button>
                </div>
            </header>
            <div v-else class="space-head">
                <h1 class="space-title">{{ space.title }}</h1>
                <Link
                    v-if="primaryAction"
                    :href="primaryAction.href"
                    class="space-primary"
                    >{{ primaryAction.title }}</Link
                >
            </div>
            <template v-if="pharmacy">
                <SpaceAsOf
                    v-if="pharmacy.branchName"
                    :branch-name="pharmacy.branchName"
                    :as-of="pharmacy.asOf"
                    only="pharmacy"
                />
                <section
                    v-if="pharmacy.toDispense"
                    class="space-card"
                    aria-labelledby="to-dispense"
                >
                    <h2 id="to-dispense">
                        To dispense ({{ pharmacy.toDispense.total }})
                    </h2>
                    <p
                        v-if="pharmacy.toDispense.rows.length === 0"
                        class="space-muted"
                    >
                        Nothing is waiting at Dispensary.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in pharmacy.toDispense.rows"
                                :key="row.visitNumber"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span
                                            v-if="row.queueNumber"
                                            class="space-qno"
                                            >{{ row.queueNumber }}</span
                                        >
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.patient
                                            }}</span>
                                            <span class="space-row-meta">
                                                <span
                                                    v-if="row.medicineCount > 0"
                                                    class="space-muted"
                                                    >{{
                                                        row.medicineCount
                                                    }}
                                                    medicine(s)</span
                                                >
                                                <span
                                                    :class="`space-wait is-${waitingTone(row.minutes)}`"
                                                    ><span
                                                        class="space-nowrap"
                                                        >{{
                                                            waitHead(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    >{{ ' '
                                                    }}<span
                                                        v-if="
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        "
                                                        class="space-nowrap"
                                                        >{{
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    ></span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                pharmacy.toDispense.total >
                                pharmacy.toDispense.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ pharmacy.toDispense.rows.length }} of
                            {{ pharmacy.toDispense.total }} ·
                            <Link
                                :href="pharmacy.toDispense.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="pharmacy.returnsToCheck"
                    class="space-card"
                    aria-labelledby="returns"
                >
                    <h2 id="returns">
                        Returns to check ({{ pharmacy.returnsToCheck.total }})
                    </h2>
                    <p
                        v-if="pharmacy.returnsToCheck.total === 0"
                        class="space-muted"
                    >
                        Nothing is waiting for a check.
                    </p>
                </section>
                <section
                    v-if="pharmacy.stockWatch"
                    class="space-card"
                    aria-labelledby="stock-watch"
                >
                    <h2 id="stock-watch">Stock watch</h2>
                    <ul class="space-stats">
                        <li>
                            <strong>{{ pharmacy.stockWatch.lowStock }}</strong>
                            <span class="space-muted">low stock</span>
                        </li>
                        <li>
                            <strong>{{ pharmacy.stockWatch.expiring }}</strong>
                            <span class="space-muted"
                                >expiring in
                                {{ pharmacy.stockWatch.windowDays }}
                                days</span
                            >
                        </li>
                        <li>
                            <strong>{{ pharmacy.stockWatch.expired }}</strong>
                            <span class="space-muted">expired on hand</span>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="pharmacy.links.length"
                    class="space-card"
                    aria-labelledby="open-pages"
                >
                    <h2 id="open-pages">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in pharmacy.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !pharmacy.toDispense &&
                        !pharmacy.returnsToCheck &&
                        !pharmacy.stockWatch
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="frontDesk">
                <SpaceAsOf
                    v-if="frontDesk.branchName"
                    :branch-name="frontDesk.branchName"
                    :as-of="frontDesk.asOf"
                    only="frontDesk"
                />
                <section
                    v-if="frontDesk.waiting"
                    class="space-card"
                    aria-labelledby="fd-waiting"
                >
                    <h2 id="fd-waiting">
                        Waiting ({{ frontDesk.waiting.total }})
                    </h2>
                    <p
                        v-if="frontDesk.waiting.rows.length === 0"
                        class="space-muted"
                    >
                        Nobody is waiting.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in frontDesk.waiting.rows"
                                :key="row.visitNumber"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span
                                            v-if="row.queueNumber"
                                            class="space-qno"
                                            >{{ row.queueNumber }}</span
                                        >
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.patient
                                            }}</span>
                                            <span class="space-row-meta">
                                                <span
                                                    :class="`space-wait is-${waitingTone(row.minutes)}`"
                                                    ><span
                                                        class="space-nowrap"
                                                        >{{
                                                            waitHead(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    >{{ ' '
                                                    }}<span
                                                        v-if="
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        "
                                                        class="space-nowrap"
                                                        >{{
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    ></span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                frontDesk.waiting.total >
                                frontDesk.waiting.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ frontDesk.waiting.rows.length }} of
                            {{ frontDesk.waiting.total }} ·
                            <Link
                                :href="frontDesk.waiting.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="frontDesk.glance"
                    class="space-card"
                    aria-labelledby="fd-glance"
                >
                    <h2 id="fd-glance">Today at a glance</h2>
                    <ul class="space-stats is-glance">
                        <li>
                            <strong>{{ frontDesk.glance.waiting ?? 0 }}</strong>
                            <span class="space-muted">waiting</span>
                        </li>
                        <li>
                            <strong>{{ frontDesk.glance.serving ?? 0 }}</strong>
                            <span class="space-muted">being served</span>
                        </li>
                        <li>
                            <strong>{{
                                frontDesk.glance.completed ?? 0
                            }}</strong>
                            <span class="space-muted">completed</span>
                        </li>
                        <li v-if="frontDesk.glance.billing !== undefined">
                            <strong>{{ frontDesk.glance.billing }}</strong>
                            <span class="space-muted">awaiting billing</span>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="frontDesk.qrPending"
                    class="space-card"
                    aria-labelledby="fd-qr"
                >
                    <h2 id="fd-qr">
                        QR check-ins to review ({{ frontDesk.qrPending.total }})
                    </h2>
                    <p
                        v-if="frontDesk.qrPending.total === 0"
                        class="space-muted"
                    >
                        No QR check-ins are waiting for review.
                    </p>
                    <p v-else class="space-existing">
                        <Link
                            :href="frontDesk.qrPending.href"
                            class="space-link"
                            >Review QR check-ins</Link
                        >
                    </p>
                </section>
                <section
                    v-if="frontDesk.links.length"
                    class="space-card"
                    aria-labelledby="fd-open"
                >
                    <h2 id="fd-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in frontDesk.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !frontDesk.waiting &&
                        !frontDesk.qrPending &&
                        !frontDesk.links.length
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="billing">
                <SpaceAsOf
                    v-if="billing.branchName"
                    :branch-name="billing.branchName"
                    :as-of="billing.asOf"
                    only="billing"
                />
                <section
                    v-if="billing.payLater"
                    class="space-card"
                    aria-labelledby="bl-paylater"
                >
                    <h2 id="bl-paylater">
                        Bills to collect ({{ billing.payLater.count }})
                    </h2>
                    <ul class="space-stats is-glance">
                        <li>
                            <strong>{{ billing.payLater.totalText }}</strong>
                            <span class="space-muted">to collect</span>
                        </li>
                        <li>
                            <strong>{{ billing.payLater.overdueCount }}</strong>
                            <span class="space-muted">overdue</span>
                        </li>
                        <li>
                            <strong>{{ billing.payLater.overdueText }}</strong>
                            <span class="space-muted">overdue amount</span>
                        </li>
                    </ul>
                    <p
                        v-if="billing.payLater.rows.length === 0"
                        class="space-muted"
                    >
                        No pay later is waiting to be collected.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in billing.payLater.rows"
                                :key="row.invoiceNumber"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span class="space-row-text">
                                            <span class="space-row-main"
                                                >{{ row.patient }}
                                                <span class="space-muted">{{
                                                    row.invoiceNumber
                                                }}</span></span
                                            >
                                            <span class="space-row-meta">
                                                <span>{{
                                                    row.amountText
                                                }}</span>
                                                <span
                                                    :class="`space-wait is-${row.tone}`"
                                                    >{{ row.dueText }}</span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                billing.payLater.count >
                                billing.payLater.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ billing.payLater.rows.length }} of
                            {{ billing.payLater.count }} ·
                            <Link
                                :href="billing.payLater.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="billing.cashClosing"
                    class="space-card"
                    aria-labelledby="bl-cash"
                >
                    <h2 id="bl-cash">Cash closing today</h2>
                    <p class="space-ask-sentence">
                        <span
                            class="space-pill"
                            :class="`is-${billing.cashClosing.status}`"
                            >{{ billing.cashClosing.statusText }}</span
                        >
                    </p>
                    <p class="space-muted">
                        Expected cash
                        {{ billing.cashClosing.expectedText }} from
                        {{ billing.cashClosing.receiptCount }}
                        {{
                            billing.cashClosing.receiptCount === 1
                                ? 'receipt'
                                : 'receipts'
                        }}.
                    </p>
                    <p
                        v-if="billing.cashClosing.changedSinceApproval"
                        class="space-ask-error"
                    >
                        Changed after approval. Check the figures.
                    </p>
                </section>
                <section
                    v-if="billing.awaiting"
                    class="space-card"
                    aria-labelledby="bl-awaiting"
                >
                    <h2 id="bl-awaiting">
                        Visits awaiting billing ({{ billing.awaiting.total }})
                    </h2>
                    <p
                        v-if="billing.awaiting.rows.length === 0"
                        class="space-muted"
                    >
                        No visit is awaiting billing.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="(row, i) in billing.awaiting.rows"
                                :key="i"
                                class="space-row-static"
                            >
                                <span class="space-row-body">
                                    <span
                                        v-if="row.queueNumber"
                                        class="space-qno"
                                        >{{ row.queueNumber }}</span
                                    >
                                    <span class="space-row-text">
                                        <span class="space-row-main">{{
                                            row.patient
                                        }}</span>
                                        <span class="space-muted"
                                            >Registered
                                            {{ row.registeredAt }}</span
                                        >
                                    </span>
                                </span>
                            </li>
                        </ul>
                        <p
                            v-if="
                                billing.awaiting.total >
                                billing.awaiting.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ billing.awaiting.rows.length }} of
                            {{ billing.awaiting.total }} ·
                            <Link
                                :href="billing.awaiting.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="billing.links.length"
                    class="space-card"
                    aria-labelledby="bl-open"
                >
                    <h2 id="bl-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in billing.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !billing.payLater &&
                        !billing.cashClosing &&
                        !billing.awaiting
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="consultation">
                <SpaceAsOf
                    v-if="consultation.branchName"
                    :branch-name="consultation.branchName"
                    :as-of="consultation.asOf"
                    only="consultation"
                />
                <section
                    v-if="consultation.waiting"
                    class="space-card"
                    aria-labelledby="cn-waiting"
                >
                    <h2 id="cn-waiting">
                        Waiting for me ({{ consultation.waiting.total }})
                    </h2>
                    <p v-if="consultation.next" class="space-muted">
                        {{ consultation.next.text }}
                    </p>
                    <p
                        v-if="consultation.waiting.rows.length === 0"
                        class="space-muted"
                    >
                        Nobody is waiting for you.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in consultation.waiting.rows"
                                :key="row.queueNumber"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span class="space-qno">{{
                                            row.queueNumber
                                        }}</span>
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.patient
                                            }}</span>
                                            <span class="space-row-meta">
                                                <span
                                                    v-if="row.urgent"
                                                    class="space-wait is-red"
                                                    >Urgent
                                                </span>
                                                <span
                                                    v-if="row.notReady"
                                                    class="space-wait is-amber"
                                                    >Not ready to call:
                                                    {{ row.notReady }}
                                                </span>
                                                <span
                                                    :class="`space-wait is-${waitingTone(row.minutes)}`"
                                                    ><span
                                                        class="space-nowrap"
                                                        >{{
                                                            waitHead(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    >{{ ' '
                                                    }}<span
                                                        v-if="
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        "
                                                        class="space-nowrap"
                                                        >{{
                                                            waitLabel(
                                                                row.minutes,
                                                            )
                                                        }}</span
                                                    ></span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                consultation.waiting.total >
                                consultation.waiting.rows.length
                            "
                            class="space-muted"
                        >
                            Showing
                            {{ consultation.waiting.rows.length }} of
                            {{ consultation.waiting.total }} ·
                            <Link
                                :href="consultation.waiting.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="consultation.held"
                    class="space-card"
                    aria-labelledby="cn-held"
                >
                    <h2 id="cn-held">
                        On hold ({{ consultation.held.total }})
                    </h2>
                    <p
                        v-if="consultation.held.rows.length === 0"
                        class="space-muted"
                    >
                        None of your consultations is on hold.
                    </p>
                    <ul v-else class="space-list">
                        <li
                            v-for="row in consultation.held.rows"
                            :key="row.queueNumber"
                        >
                            <Link :href="row.href" class="space-row">
                                <span class="space-row-body">
                                    <span class="space-qno">{{
                                        row.queueNumber
                                    }}</span>
                                    <span class="space-row-text">
                                        <span class="space-row-main">{{
                                            row.patient
                                        }}</span>
                                        <span
                                            class="space-row-meta"
                                            :class="
                                                row.tooLong
                                                    ? 'space-wait is-amber'
                                                    : ''
                                            "
                                            >{{ row.heldText }}</span
                                        >
                                    </span>
                                </span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section v-if="!consultation.waiting" class="space-card">
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="panel">
                <SpaceAsOf
                    v-if="panel.branchName"
                    :branch-name="panel.branchName"
                    :as-of="panel.asOf"
                    only="panel"
                />
                <section
                    v-if="panel.toClaim"
                    class="space-card"
                    aria-labelledby="pn-claim"
                >
                    <h2 id="pn-claim">
                        Invoices to claim ({{ panel.toClaim.total }})
                    </h2>
                    <ul class="space-stats is-glance">
                        <li>
                            <strong>{{ panel.toClaim.overdue }}</strong>
                            <span class="space-muted">overdue</span>
                        </li>
                        <li>
                            <strong>{{ panel.toClaim.pending }}</strong>
                            <span class="space-muted">not yet due</span>
                        </li>
                    </ul>
                    <p
                        v-if="panel.toClaim.rows.length === 0"
                        class="space-muted"
                    >
                        No approved Panel invoice is waiting for a claim.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in panel.toClaim.rows"
                                :key="row.invoiceNumber"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.patient
                                            }}</span>
                                            <span class="space-row-sub">{{
                                                row.panel
                                            }}</span>
                                            <span class="space-row-meta">
                                                {{ row.amountText }}
                                                <span
                                                    :class="
                                                        row.overdue
                                                            ? 'space-wait is-red'
                                                            : ''
                                                    "
                                                    >{{ row.dueText }}</span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                panel.toClaim.total > panel.toClaim.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ panel.toClaim.rows.length }} of
                            {{ panel.toClaim.total }} ·
                            <Link
                                :href="panel.toClaim.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="panel.followUp"
                    class="space-card"
                    aria-labelledby="pn-follow"
                >
                    <h2 id="pn-follow">
                        Claims to follow up ({{ panel.followUp.total }})
                    </h2>
                    <p
                        v-if="panel.followUp.rows.length === 0"
                        class="space-muted"
                    >
                        No claim is waiting for a follow-up.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li
                                v-for="row in panel.followUp.rows"
                                :key="row.claimNo"
                            >
                                <Link :href="row.href" class="space-row">
                                    <span class="space-row-body">
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.patient
                                            }}</span>
                                            <span class="space-row-sub">{{
                                                row.panel
                                            }}</span>
                                            <span class="space-row-meta">
                                                <span class="space-nowrap">{{
                                                    row.status
                                                }}</span
                                                >{{ ' '
                                                }}<span
                                                    class="space-wait is-amber space-nowrap"
                                                    >{{ row.sinceText }}</span
                                                >
                                            </span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="
                                panel.followUp.total >
                                panel.followUp.rows.length
                            "
                            class="space-muted"
                        >
                            Showing {{ panel.followUp.rows.length }} of
                            {{ panel.followUp.total }} ·
                            <Link
                                :href="panel.followUp.seeAllHref"
                                class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <p v-if="panel.note" class="space-muted">
                    {{ panel.note }}
                </p>
                <section
                    v-if="panel.links.length"
                    class="space-card"
                    aria-labelledby="pn-open"
                >
                    <h2 id="pn-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in panel.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !panel.toClaim && !panel.followUp && !panel.links.length
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="stock">
                <SpaceAsOf
                    v-if="stock.branchName"
                    :branch-name="stock.branchName"
                    :as-of="stock.asOf"
                    only="stock"
                />
                <template v-if="stock.watch">
                    <section
                        v-for="list in stock.watch.lists"
                        :key="list.key"
                        class="space-card"
                        :aria-labelledby="`st-${list.key}`"
                    >
                        <h2 :id="`st-${list.key}`">
                            {{ list.title }} ({{ list.total }})
                        </h2>
                        <p v-if="list.rows.length === 0" class="space-muted">
                            Nothing here.
                        </p>
                        <template v-else>
                            <ul class="space-list">
                                <li
                                    v-for="(row, i) in list.rows"
                                    :key="`${row.sku}-${i}`"
                                >
                                    <Link
                                        :href="list.seeAllHref"
                                        class="space-row"
                                    >
                                        <span class="space-row-body">
                                            <span class="space-row-text">
                                                <span class="space-row-main"
                                                    >{{ row.sku }} ·
                                                    {{ row.item }}</span
                                                >
                                                <span
                                                    v-if="
                                                        stock.watch.showLocation
                                                    "
                                                    class="space-row-sub"
                                                    >{{ row.location }}</span
                                                >
                                                <span
                                                    :class="`space-row-meta space-wait is-${row.tone}`"
                                                    >{{ row.detail }}</span
                                                >
                                            </span>
                                        </span>
                                        <ChevronRight
                                            class="space-chevron"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            </ul>
                            <p
                                v-if="list.total > list.rows.length"
                                class="space-muted"
                            >
                                Showing {{ list.rows.length }} of
                                {{ list.total }} ·
                                <Link :href="list.seeAllHref" class="space-link"
                                    >See all</Link
                                >
                            </p>
                        </template>
                    </section>
                </template>
                <section
                    v-if="stock.overdueReviews"
                    class="space-card"
                    aria-labelledby="st-overdue"
                >
                    <h2 id="st-overdue">
                        Reviews overdue ({{ stock.overdueReviews.total }})
                    </h2>
                    <p
                        v-if="stock.overdueReviews.rows.length === 0"
                        class="space-muted"
                    >
                        No stock review is overdue.
                    </p>
                    <ul v-else class="space-list">
                        <li
                            v-for="(row, i) in stock.overdueReviews.rows"
                            :key="`${row.sku}-${i}`"
                        >
                            <Link
                                :href="stock.overdueReviews.seeAllHref"
                                class="space-row"
                            >
                                <span class="space-row-body">
                                    <span class="space-row-text">
                                        <span class="space-row-main"
                                            >{{ row.sku }} ·
                                            {{ row.item }}</span
                                        >
                                        <span
                                            :class="`space-row-meta space-wait is-${row.tone}`"
                                            >{{ row.text }}</span
                                        >
                                    </span>
                                </span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                    <p
                        v-if="
                            stock.overdueReviews.total >
                            stock.overdueReviews.rows.length
                        "
                        class="space-muted"
                    >
                        Showing {{ stock.overdueReviews.rows.length }} of
                        {{ stock.overdueReviews.total }} ·
                        <Link
                            :href="stock.overdueReviews.seeAllHref"
                            class="space-link"
                            >See all</Link
                        >
                    </p>
                </section>
                <section
                    v-if="stock.readiness"
                    class="space-card"
                    aria-labelledby="st-ready"
                >
                    <h2 id="st-ready">Pilot readiness</h2>
                    <p class="space-muted">
                        {{
                            stock.readiness.limited
                                ? '25 or more'
                                : stock.readiness.openings
                        }}
                        opening
                        {{
                            stock.readiness.openings === 1
                                ? 'balance waits'
                                : 'balances wait'
                        }}
                        for an independent check.
                        <Link :href="stock.readiness.href" class="space-link"
                            >Open Pilot readiness</Link
                        >
                    </p>
                </section>
                <section
                    v-if="stock.links.length"
                    class="space-card"
                    aria-labelledby="st-open"
                >
                    <h2 id="st-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in stock.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="!stock.watch && !stock.links.length"
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="hr">
                <SpaceAsOf
                    v-if="hr.branchName"
                    :branch-name="hr.branchName"
                    :as-of="hr.asOf"
                    only="hr"
                />
                <section
                    v-if="hr.approvals"
                    class="space-card"
                    aria-labelledby="hr-approvals"
                >
                    <h2 id="hr-approvals">
                        Approvals waiting ({{ hr.approvals.total }})
                    </h2>
                    <p
                        v-if="hr.approvals.kinds.length === 0"
                        class="space-muted"
                    >
                        Nothing is waiting for approval.
                    </p>
                    <ul v-else class="space-list">
                        <li v-for="k in hr.approvals.kinds" :key="k.key">
                            <Link :href="k.href" class="space-row">
                                <span class="space-row-body">
                                    <span class="space-row-text">
                                        <span class="space-row-main"
                                            >{{ k.title }}:
                                            <strong>{{ k.count }}</strong></span
                                        >
                                        <span
                                            v-if="k.detail"
                                            class="space-row-sub"
                                            :class="{
                                                'space-wait is-amber':
                                                    k.detail.endsWith('(soon)'),
                                            }"
                                            >{{ k.detail }}</span
                                        >
                                    </span>
                                </span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="hr.away"
                    class="space-card"
                    aria-labelledby="hr-away"
                >
                    <h2 id="hr-away">Away today ({{ hr.away.total }})</h2>
                    <p v-if="hr.away.rows.length === 0" class="space-muted">
                        Nobody is on leave today.
                    </p>
                    <template v-else>
                        <ul class="space-list">
                            <li v-for="(row, i) in hr.away.rows" :key="i">
                                <Link
                                    :href="hr.away.seeAllHref"
                                    class="space-row"
                                >
                                    <span class="space-row-body">
                                        <span class="space-row-text">
                                            <span class="space-row-main">{{
                                                row.name
                                            }}</span>
                                            <span class="space-row-meta">{{
                                                row.text
                                            }}</span>
                                        </span>
                                    </span>
                                    <ChevronRight
                                        class="space-chevron"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                        <p
                            v-if="hr.away.total > hr.away.rows.length"
                            class="space-muted"
                        >
                            Showing {{ hr.away.rows.length }} of
                            {{ hr.away.total }} ·
                            <Link :href="hr.away.seeAllHref" class="space-link"
                                >See all</Link
                            >
                        </p>
                    </template>
                </section>
                <section
                    v-if="hr.roster"
                    class="space-card"
                    aria-labelledby="hr-roster"
                >
                    <h2 id="hr-roster">Roster this week</h2>
                    <Link :href="hr.roster.href" class="space-row">
                        <span class="space-row-body">
                            <span class="space-row-text">
                                <span class="space-row-main">{{
                                    hr.roster.label
                                }}</span>
                                <span class="space-row-sub">{{
                                    hr.roster.status
                                }}</span>
                                <span
                                    class="space-row-meta"
                                    :class="
                                        hr.roster.shortDays > 0
                                            ? 'space-wait is-amber'
                                            : ''
                                    "
                                    >{{
                                        hr.roster.shortDays === 0
                                            ? hr.roster.status === 'Approved'
                                                ? 'No day is short'
                                                : 'No day is short so far'
                                            : hr.roster.shortDays === 1
                                              ? '1 day is short'
                                              : `${hr.roster.shortDays} days are short`
                                    }}</span
                                >
                            </span>
                        </span>
                        <ChevronRight
                            class="space-chevron"
                            aria-hidden="true"
                        />
                    </Link>
                </section>
                <section
                    v-if="hr.links.length"
                    class="space-card"
                    aria-labelledby="hr-open"
                >
                    <h2 id="hr-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in hr.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !hr.approvals &&
                        !hr.away &&
                        !hr.roster &&
                        !hr.links.length
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="management">
                <SpaceAsOf
                    v-if="management.branchName"
                    :branch-name="management.branchName"
                    :as-of="management.asOf"
                    only="management"
                />
                <section
                    v-if="management.staff"
                    class="space-card"
                    aria-labelledby="mg-staff"
                >
                    <h2 id="mg-staff">Staff accounts</h2>
                    <ul class="space-stats is-glance">
                        <li>
                            <strong>{{ management.staff.active }}</strong>
                            <span class="space-muted">active</span>
                        </li>
                        <li>
                            <strong>{{ management.staff.inactive }}</strong>
                            <span class="space-muted">inactive</span>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="management.documents"
                    class="space-card"
                    aria-labelledby="mg-docs"
                >
                    <h2 id="mg-docs">Letters and footers</h2>
                    <p
                        :class="
                            management.documents.warning
                                ? 'space-wait is-amber'
                                : 'space-muted'
                        "
                    >
                        {{ management.documents.text }}
                    </p>
                </section>
                <section
                    v-if="management.links.length"
                    class="space-card"
                    aria-labelledby="mg-open"
                >
                    <h2 id="mg-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in management.links" :key="l.href">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !management.staff &&
                        !management.documents &&
                        !management.links.length
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <template v-else-if="me">
                <MeHome
                    :greeting="me.greeting"
                    :ring="me.ring"
                    :week="me.week"
                    :tiles="me.tiles"
                    :server-now="me.time?.serverNow ?? null"
                    :timezone="me.time?.timezone ?? null"
                />
                <SpaceAsOf
                    v-if="me.branchName"
                    :branch-name="me.branchName"
                    :as-of="me.asOf"
                    only="me"
                />
                <section
                    v-if="me.credentials"
                    class="space-card"
                    aria-labelledby="me-cred"
                >
                    <h2 id="me-cred">My credentials</h2>
                    <ul class="space-list">
                        <li
                            v-for="c in me.credentials.rows"
                            :key="c.name"
                            class="space-row"
                        >
                            <span class="space-row-body">
                                <span class="space-row-text">
                                    <span class="space-row-main">{{
                                        c.name
                                    }}</span>
                                    <span
                                        class="space-row-sub"
                                        :class="
                                            c.expired
                                                ? 'space-wait is-red'
                                                : 'space-wait is-amber'
                                        "
                                        >{{ c.words }}</span
                                    >
                                </span>
                            </span>
                        </li>
                    </ul>
                    <p
                        v-if="me.credentials.total > me.credentials.rows.length"
                        class="space-muted"
                    >
                        Showing {{ me.credentials.rows.length }} of
                        {{ me.credentials.total }}
                    </p>
                </section>
                <section
                    v-if="me.payslip"
                    class="space-card"
                    aria-labelledby="me-pay"
                >
                    <h2 id="me-pay">Latest payslip</h2>
                    <Link :href="me.payslip.href" class="space-row">
                        <span class="space-row-body">
                            <span class="space-row-text">
                                <span class="space-row-main">{{
                                    me.payslip.period
                                }}</span>
                                <span class="space-row-sub"
                                    >Issued {{ me.payslip.issuedOn }}</span
                                >
                            </span>
                        </span>
                        <ChevronRight
                            class="space-chevron"
                            aria-hidden="true"
                        />
                    </Link>
                </section>
                <section
                    v-if="meLinks.length"
                    class="space-card"
                    aria-labelledby="me-open"
                >
                    <h2 id="me-open">Open</h2>
                    <ul class="space-list">
                        <li v-for="l in meLinks" :key="l.title">
                            <Link :href="l.href" class="space-row">
                                <span>{{ l.title }}</span>
                                <ChevronRight
                                    class="space-chevron"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
                <section
                    v-if="
                        !me.ring &&
                        !me.week &&
                        !me.tiles.leave &&
                        !me.tiles.claims &&
                        !me.tiles.roster &&
                        !me.tiles.attendance &&
                        !me.credentials &&
                        !me.payslip &&
                        !me.links.length
                    "
                    class="space-card"
                >
                    <p class="space-muted">
                        Nothing to show here for your role. Your existing
                        screens are unchanged.
                    </p>
                    <p v-if="existing" class="space-existing">
                        <Link :href="existing.href"
                            >Open the existing
                            {{ existing.title }} screens</Link
                        >
                    </p>
                </section>
            </template>
            <section v-else class="space-card" aria-labelledby="work-now">
                <h2 id="work-now">Work now</h2>
                <p class="space-muted">
                    This space is being built. Your existing screens are
                    unchanged.
                </p>
                <p v-if="existing" class="space-existing">
                    <Link :href="existing.href"
                        >Open the existing {{ existing.title }} screens</Link
                    >
                </p>
            </section>
            <SpaceAskPanel
                v-if="questions && questions.length"
                :space="space.key"
                :questions="questions"
            />
            <section class="space-card space-recent" aria-labelledby="recent">
                <h2 id="recent">Recent activity</h2>
                <p class="space-muted">Recent activity will appear here.</p>
            </section>
        </main>
    </div>
</template>
