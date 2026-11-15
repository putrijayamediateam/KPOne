<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    Bot,
    Briefcase,
    Building2,
    ChartNoAxesCombined,
    ChevronRight,
    ClipboardList,
    FileText,
    Globe,
    Search,
    Ticket,
    UserCog,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';

/**
 * UX-21: the Version 2 Clinic management hub. Same props, route and permissions as Classic (`ClinicManagement/Index`):
 * the server lists only pages the person may open, with the V2 sentence-case labels. "Find a page" filters those links
 * in the browser; it sends no request and searches no patient.
 */
type HubLink = { title: string; description: string; href: string };
const props = defineProps<{
    groups: { title: string; icon: string; links: HubLink[] }[];
}>();

const icons: Record<string, Component> = {
    clinic: Building2,
    inventory: Archive,
    patients: Users,
    queue: Ticket,
    reports: ChartNoAxesCombined,
    panel: ClipboardList,
    staff: UserCog,
    documents: FileText,
    website: Globe,
    agents: Bot,
    hr: Briefcase,
};

// Daniel (2026-11-13), V2 only: the KPOne HR pages get their own card instead of filling "Staff and branches", and drop
// the repeated "KPOne HR" prefix inside it (the full name stays as the row's title). Same links; Classic is unchanged.
type Row = HubLink & { fullTitle: string };
type Group = { key: string; title: string; icon: string; links: Row[] };
const HR_PREFIX = /^KPOne HR\s+/i;
const isHr = (link: HubLink) =>
    link.href === '/hr' || link.href.startsWith('/hr/');
const shortHr = (title: string) => {
    const rest = title.replace(HR_PREFIX, '');

    return rest === title || rest === ''
        ? title
        : rest.charAt(0).toUpperCase() + rest.slice(1);
};
const slug = (text: string) =>
    text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
// One pass: every /hr link, from whichever group holds it, goes into a single KPOne HR card placed after the first
// group that held one; a group left empty is dropped. Keys are slugs, so they are safe list ids for aria-controls.
const grouped = computed<Group[]>(() => {
    const out: Group[] = [];
    const hrRows: Row[] = [];
    let hrAt = -1;

    for (const group of props.groups) {
        const hr = group.links.filter(isHr);
        const rest = group.links.filter((link) => !isHr(link));

        hrRows.push(
            ...hr.map((link) => ({
                ...link,
                fullTitle: link.title,
                title: shortHr(link.title),
            })),
        );

        if (rest.length > 0) {
            out.push({
                key: slug(group.title),
                title: group.title,
                icon: group.icon,
                links: rest.map((link) => ({ ...link, fullTitle: link.title })),
            });
        }

        if (hr.length > 0 && hrAt === -1) {
            hrAt = out.length;
        }
    }

    if (hrRows.length > 0) {
        out.splice(hrAt, 0, {
            key: 'kpone-hr',
            title: 'KPOne HR',
            icon: 'hr',
            links: hrRows,
        });
    }

    return out;
});

const query = ref('');
const words = computed(() =>
    query.value.trim().toLowerCase().split(/\s+/).filter(Boolean),
);
const shown = computed(() =>
    grouped.value
        .map((group) => ({
            ...group,
            links: group.links.filter((link) => {
                const text =
                    `${link.fullTitle} ${link.description} ${group.title}`.toLowerCase();

                return words.value.every((word) => text.includes(word));
            }),
        }))
        .filter((group) => group.links.length > 0),
);
const pageCount = (count: number) =>
    `${count} ${count === 1 ? 'page' : 'pages'}`;
const matchCount = computed(() =>
    shown.value.reduce((sum, group) => sum + group.links.length, 0),
);
// Spoken politely after each change to the search (Daniel: "2 pages found").
const announcement = computed(() =>
    words.value.length === 0
        ? ''
        : matchCount.value === 0
          ? 'No pages found'
          : `${pageCount(matchCount.value)} found`,
);

// Below 640 px each card is a closed disclosure ("In-clinic · 11", like Inventory); a search opens the matching cards.
const opened = ref<Set<string>>(new Set());
const isOpen = (key: string) => words.value.length > 0 || opened.value.has(key);
const toggle = (key: string) => {
    // While a search is open every matching card is open; a tap then changes nothing.
    if (words.value.length > 0) {
        return;
    }

    const next = new Set(opened.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    opened.value = next;
};
</script>

<template>
    <Head title="Clinic management" />
    <PageFrame>
        <V2WorkLayout
            title="Clinic management"
            description="Settings, reports and records you can open."
        >
            <template #main>
                <div class="relative max-w-md">
                    <label for="hub-find" class="sr-only">Find a page</label>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        id="hub-find"
                        v-model="query"
                        type="search"
                        placeholder="Find a page"
                        autocomplete="off"
                        aria-describedby="hub-find-result"
                        class="h-11 w-full rounded-[10px] border border-input bg-card pr-3 pl-9 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                        data-test="hub-find"
                    />
                </div>
                <p id="hub-find-result" class="sr-only" role="status">
                    {{ announcement }}
                </p>

                <p
                    v-if="shown.length === 0"
                    class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                    data-test="hub-empty"
                >
                    No page matches “{{ query.trim() }}”.
                </p>

                <div
                    v-else
                    class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3"
                    data-test="hub-groups"
                >
                    <section
                        v-for="group in shown"
                        :key="group.key"
                        :aria-label="group.title"
                        class="flex min-w-0 flex-col rounded-[14px] border bg-card"
                    >
                        <!-- Below 640 px: a closed disclosure with the count, opened by a tap or by a search. -->
                        <h2 class="sm:hidden">
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-3 rounded-[14px] px-4 py-2 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-expanded="isOpen(group.key)"
                                :aria-controls="`hub-list-${group.key}`"
                                data-test="hub-group-toggle"
                                @click="toggle(group.key)"
                            >
                                <component
                                    :is="icons[group.icon] ?? Building2"
                                    class="size-5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    class="min-w-0 flex-1 text-[15px] font-semibold"
                                    >{{ group.title }}
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >· {{ group.links.length }}</span
                                    ></span
                                >
                                <ChevronRight
                                    class="size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none"
                                    :class="{ 'rotate-90': isOpen(group.key) }"
                                    aria-hidden="true"
                                />
                            </button>
                        </h2>
                        <header
                            class="flex items-center gap-3 border-b px-4 py-3 max-sm:hidden"
                        >
                            <component
                                :is="icons[group.icon] ?? Building2"
                                class="size-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <h2
                                class="min-w-0 flex-1 text-[15px] font-semibold"
                            >
                                {{ group.title }}
                            </h2>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ pageCount(group.links.length) }}</span
                            >
                        </header>
                        <ul
                            :id="`hub-list-${group.key}`"
                            class="divide-y p-1.5"
                            :class="{
                                'max-sm:hidden': !isOpen(group.key),
                                'max-sm:border-t': isOpen(group.key),
                            }"
                        >
                            <li
                                v-for="link in group.links"
                                :key="`${link.href}|${link.fullTitle}`"
                            >
                                <Link
                                    :href="link.href"
                                    :title="
                                        link.fullTitle !== link.title
                                            ? link.fullTitle
                                            : undefined
                                    "
                                    class="group flex min-h-11 items-center gap-3 rounded-[10px] px-2.5 py-2 outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block text-sm font-medium"
                                            >{{ link.title }}</span
                                        >
                                        <span
                                            v-if="link.description"
                                            class="mt-0.5 block text-xs leading-4 text-muted-foreground"
                                            >{{ link.description }}</span
                                        >
                                    </span>
                                    <ChevronRight
                                        class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
