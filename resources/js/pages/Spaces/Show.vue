<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import SpaceAskPanel from '@/components/spaces/SpaceAskPanel.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import SpacePassThroughLayout from '@/layouts/SpacePassThroughLayout.vue';
import { waitingTone } from '@/lib/waiting-time';

type Space = { key: string; title: string };

// The space shell draws its own chrome, so it opts out of the staff layouts without touching the shared layout map.
defineOptions({ layout: SpacePassThroughLayout });

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

const waitHead = (minutes: number): string =>
    `Waiting ${minutes} min${waitLabel(minutes) ? ',' : ''}`;

const groups = computed(() => {
    const layout: Array<[string, string[]]> = [
        ['Clinic', ['front_desk', 'pharmacy', 'consultation']],
        ['Money', ['billing', 'panel']],
        ['Operations', ['stock', 'hr', 'management']],
        ['You', ['me']],
    ];

    return layout
        .map(([title, keys]) => ({
            title,
            items: props.spaces.filter((s) => keys.includes(s.key)),
        }))
        .filter((g) => g.items.length > 0);
});

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

// 390 px: the nav is one scrolling row. Fade the right edge while more is hidden and bring the current item into view.
// Reloads only this space's data, so the front desk can update without leaving the page (no polling in this slice).
const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({
        only: ['frontDesk'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
};

const side = ref<HTMLElement | null>(null);
const more = ref(false);
const measure = () => {
    const el = side.value;
    more.value = !!el && el.scrollLeft + el.clientWidth < el.scrollWidth - 2;
};
onMounted(async () => {
    await nextTick();
    side.value
        ?.querySelector<HTMLElement>('[aria-current="page"]')
        ?.scrollIntoView({ block: 'nearest', inline: 'center' });
    measure();
});
</script>

<template>
    <Head :title="space.title" />
    <div class="space-shell">
        <div
            ref="side"
            class="space-side"
            :class="{ 'has-more': more }"
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
        <main id="main-content" class="space-main" tabindex="-1">
            <div class="space-top">
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
                <DropdownMenu>
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
            <h1 class="space-title">{{ space.title }}</h1>
            <template v-if="pharmacy">
                <p v-if="pharmacy.branchName" class="space-muted">
                    {{ pharmacy.branchName
                    }}<template v-if="pharmacy.asOf">
                        · as of {{ pharmacy.asOf }}</template
                    >
                </p>
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
                                {{ pharmacy.stockWatch.windowDays }} days</span
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
                <p v-if="frontDesk.branchName" class="space-muted space-asof">
                    <span
                        >{{ frontDesk.branchName
                        }}<template v-if="frontDesk.asOf">
                            · as of {{ frontDesk.asOf }}</template
                        ></span
                    >
                    <button
                        type="button"
                        class="space-refresh"
                        :disabled="refreshing"
                        @click="refresh"
                    >
                        {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                    </button>
                </p>
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
                    <ul class="space-stats">
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
