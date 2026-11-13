<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    Bot,
    Building2,
    ChartNoAxesCombined,
    ChevronRight,
    ClipboardList,
    FileText,
    Globe,
    Search,
    Ticket,
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
    staff: Users,
    documents: FileText,
    website: Globe,
    agents: Bot,
};

const query = ref('');
const words = computed(() =>
    query.value.trim().toLowerCase().split(/\s+/).filter(Boolean),
);
const shown = computed(() =>
    props.groups
        .map((group) => ({
            ...group,
            links: group.links.filter((link) => {
                const text =
                    `${link.title} ${link.description} ${group.title}`.toLowerCase();

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
                    <template v-if="words.length > 0">{{
                        pageCount(matchCount)
                    }}</template>
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
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                    data-test="hub-groups"
                >
                    <section
                        v-for="(group, index) in shown"
                        :key="group.title"
                        :aria-labelledby="`hub-group-${index}`"
                        class="flex min-w-0 flex-col rounded-[14px] border bg-card"
                    >
                        <header
                            class="flex items-center gap-3 border-b px-4 py-3"
                        >
                            <component
                                :is="icons[group.icon] ?? Building2"
                                class="size-5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <h2
                                :id="`hub-group-${index}`"
                                class="min-w-0 flex-1 text-[15px] font-semibold"
                            >
                                {{ group.title }}
                            </h2>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ pageCount(group.links.length) }}</span
                            >
                        </header>
                        <ul class="divide-y p-1.5">
                            <li
                                v-for="link in group.links"
                                :key="`${link.href}|${link.title}`"
                            >
                                <Link
                                    :href="link.href"
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
