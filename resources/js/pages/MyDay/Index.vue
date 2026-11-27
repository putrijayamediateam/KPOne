<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import RedZoneAlertBanner from '@/components/queue/RedZoneAlertBanner.vue';
import MyRoutine from '@/components/MyRoutine.vue';
import MeHome from '@/components/spaces/MeHome.vue';
import NextThings from '@/components/spaces/NextThings.vue';
import type { NextBlock } from '@/components/spaces/NextThings.vue';
import SpaceAsOf from '@/components/spaces/SpaceAsOf.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';

type Ring = InstanceType<typeof MeHome>['$props']['ring'];
type Week = InstanceType<typeof MeHome>['$props']['week'];
type Station = InstanceType<typeof MeHome>['$props']['station'];
type Tiles = InstanceType<typeof MeHome>['$props']['tiles'];

defineProps<{
    header: { date: string; branchName: string | null };
    today: {
        ring: Ring;
        week: Week;
        station?: Station;
        stationHref?: string | null;
        tiles: Tiles;
        time: { serverNow: string; timezone: string } | null;
        credentials: {
            rows: Array<{ name: string; words: string; expired: boolean }>;
        } | null;
        branchName: string | null;
        asOf: string | null;
    };
    waiting: {
        total: number;
        areas: Array<{ area: string; count: number }>;
    } | null;
    next: NextBlock;
    routine: InstanceType<typeof MyRoutine>['$props']['routine'];
    shortcuts: Array<{ title: string; href: string }>;
}>();

const page = usePage();
// Amirul's red-zone alert (Q-16b) is shown here as it is; the home builds no alert of its own.
const redZone = computed(() => {
    const perms = (page.props.auth as { permissions: string[] }).permissions;
    const branch = (page.props.branchContext as { active?: { id: number } | null } | null)?.active;

    return branch && (perms.includes('queue.call.own') || perms.includes('visits.triage.record.branch')) ? branch.id : null;
});
</script>

<template>
    <Head title="My day" />
    <PageFrame class="space-shell" data-classic-frame>
        <div class="space-main">
            <RedZoneAlertBanner v-if="redZone !== null" :branch-id="redZone" />
            <PageHeader
                title="My day"
                :description="
                    header.branchName
                        ? `${header.date} · ${header.branchName}`
                        : header.date
                "
                data-test="my-day-head"
            />

            <!-- Today first (the clock card), then what needs acting on, then the rest of the person's own week. -->
            <MeHome
                v-if="today.ring || today.week"
                :greeting="null"
                compact
                part="top"
                :ring="today.ring"
                :week="today.week"
                :station="today.station"
                :station-href="today.stationHref"
                :tiles="today.tiles"
                :server-now="today.time?.serverNow ?? null"
                :timezone="today.time?.timezone ?? null"
            />
            <section
                v-else
                class="space-card shadow-card"
                aria-labelledby="day-today"
            >
                <h2 id="day-today">Today</h2>
                <p class="space-muted">Nothing is planned for you today.</p>
            </section>

            <NextThings :next="next" />

            <section
                v-if="waiting"
                class="space-card shadow-card"
                aria-labelledby="day-waiting"
                data-test="my-day-waiting"
            >
                <h2 id="day-waiting">Waiting for me</h2>
                <p class="space-muted mb-4" data-test="waiting-total">
                    {{ waiting.total }} waiting for your decision
                </p>
                <ul class="space-list">
                    <li
                        v-for="a in waiting.areas"
                        :key="a.area"
                        class="space-row"
                    >
                        <span>{{ a.area }}</span>
                        <span class="font-medium tabular-nums">{{
                            a.count
                        }}</span>
                    </li>
                </ul>
                <Link
                    href="/approvals"
                    class="mt-2 inline-flex h-11 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:h-9"
                    >Open Approvals &amp; tasks</Link
                >
            </section>

            <MyRoutine :routine="routine" />

            <MeHome
                v-if="today.ring || today.week"
                :greeting="null"
                compact
                part="rest"
                :ring="today.ring"
                :week="today.week"
                :station="today.station"
                :station-href="today.stationHref"
                :tiles="today.tiles"
                :server-now="today.time?.serverNow ?? null"
                :timezone="today.time?.timezone ?? null"
            />

            <section
                v-if="today.credentials?.rows.length"
                class="space-card shadow-card"
                aria-labelledby="day-cred"
            >
                <h2 id="day-cred">Credentials due</h2>
                <ul class="space-list">
                    <li
                        v-for="c in today.credentials.rows"
                        :key="c.name"
                        class="space-row"
                    >
                        <span class="space-row-body">
                            <span class="space-row-text">
                                <span class="space-row-main">{{ c.name }}</span>
                                <span class="space-row-sub">{{ c.words }}</span>
                            </span>
                        </span>
                    </li>
                </ul>
            </section>
            <SpaceAsOf
                v-if="today.branchName"
                :branch-name="today.branchName"
                :as-of="today.asOf"
                only="today"
            />

            <section
                v-if="shortcuts.length"
                class="space-card shadow-card"
                aria-labelledby="day-shortcuts"
            >
                <h2 id="day-shortcuts">Shortcuts</h2>
                <ul class="space-list">
                    <li v-for="s in shortcuts" :key="s.href">
                        <Link :href="s.href" class="space-row">
                            <span>{{ s.title }}</span>
                            <ChevronRight
                                class="space-chevron"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </PageFrame>
</template>
