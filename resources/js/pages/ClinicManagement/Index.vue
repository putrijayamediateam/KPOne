<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    ArrowRight,
    Bot,
    Building2,
    ChartNoAxesCombined,
    ClipboardList,
    FileText,
    Globe,
    Ticket,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { hubLinkMatches, searchWords } from '@/lib/clinic-hub-search';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clinic management', href: '/clinic-management' },
        ],
    },
});
const props = defineProps<{
    groups: {
        title: string;
        icon: string;
        links: { title: string; description: string; href: string }[];
    }[];
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

/**
 * "Find a page": narrows the links the server already listed for this person (it never adds one, so a page their
 * permissions hide cannot appear). Every typed word must be in the page's title, its description or its card's title, so
 * "stock count" or "refund" finds it. Nothing is sent or stored. Hidden cards and rows use v-show, so they leave the
 * reading order too.
 */
const query = ref('');
const words = computed(() => searchWords(query.value));
const matches = (
    group: { title: string },
    link: { title: string; description: string },
) => hubLinkMatches(words.value, group.title, link);
const matchCount = computed(() =>
    props.groups.reduce(
        (sum, group) =>
            sum + group.links.filter((link) => matches(group, link)).length,
        0,
    ),
);
const hasMatch = (group: (typeof props.groups)[number]) =>
    group.links.some((link) => matches(group, link));
// Spoken politely after each change to the search.
const announcement = computed(() =>
    words.value.length === 0
        ? ''
        : matchCount.value === 0
          ? 'No pages found'
          : `${matchCount.value} ${matchCount.value === 1 ? 'page' : 'pages'} found`,
);
</script>

<template>
    <Head title="Clinic management" />
    <main
        class="mx-auto w-full max-w-[1440px] flex-1 p-4 py-6 md:p-6 lg:px-8 lg:py-8"
    >
        <PageHeader title="Clinic management" class="mb-5" />
        <div class="mb-5 max-w-md">
            <label for="hub-find" class="mb-1.5 block text-sm font-medium"
                >Find a page</label
            >
            <Input
                id="hub-find"
                v-model="query"
                type="search"
                autocomplete="off"
                aria-describedby="hub-find-result"
                data-testid="hub-find"
            />
            <p id="hub-find-result" class="sr-only" role="status">
                {{ announcement }}
            </p>
        </div>
        <p
            v-if="words.length > 0 && matchCount === 0"
            class="mb-5 rounded-lg border bg-card p-6 text-center text-sm text-muted-foreground"
            data-testid="hub-empty"
        >
            No page matches “{{ query.trim() }}”.
        </p>
        <div
            class="grid gap-px overflow-hidden rounded-lg border bg-card md:grid-cols-2 xl:grid-cols-3"
            data-testid="clinic-management-cards"
        >
            <section
                v-for="(group, index) in groups"
                v-show="hasMatch(group)"
                :key="group.title"
                :aria-labelledby="`clinic-card-${index}`"
                class="min-w-0 bg-card p-5 ring-1 ring-border md:p-6"
            >
                <component
                    :is="icons[group.icon]"
                    class="mb-2 size-6"
                    aria-hidden="true"
                />
                <h2
                    :id="`clinic-card-${index}`"
                    class="mb-4 text-base font-semibold"
                >
                    {{ group.title }}
                </h2>
                <ul class="divide-y">
                    <li
                        v-for="link in group.links"
                        v-show="matches(group, link)"
                        :key="link.title"
                    >
                        <Link
                            :href="link.href"
                            class="group flex min-h-16 items-center gap-4 rounded-sm px-2 py-3 transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13px] font-medium">{{
                                    link.title
                                }}</span>
                                <span
                                    class="mt-0.5 block text-xs leading-4 text-muted-foreground"
                                    >{{ link.description }}</span
                                >
                            </span>
                            <ArrowRight
                                class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </main>
</template>
