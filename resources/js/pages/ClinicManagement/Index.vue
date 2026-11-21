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
    X,
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
// One polite status line for the search: "3 pages match", or the empty message. The container is always in the page and
// only its text changes, so a screen reader says it once; the input does not point at it, so it is not read twice.
const noMatch = computed(
    () => words.value.length > 0 && matchCount.value === 0,
);
const resultLine = computed(() =>
    words.value.length === 0 || noMatch.value
        ? ''
        : `${matchCount.value} ${matchCount.value === 1 ? 'page matches' : 'pages match'}`,
);
const input = ref<{ $el: HTMLInputElement } | null>(null);
const clear = () => {
    query.value = '';
    input.value?.$el.focus();
};
</script>

<template>
    <Head title="Clinic management" />
    <main
        class="mx-auto w-full max-w-[1440px] flex-1 p-4 py-6 md:p-6 lg:px-8 lg:py-8"
    >
        <PageHeader title="Clinic management" class="mb-5" />
        <div class="mb-3 max-w-md">
            <label for="hub-find" class="mb-1.5 block text-sm font-medium"
                >Find a page</label
            >
            <div class="relative">
                <Input
                    id="hub-find"
                    ref="input"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    class="pr-11 [&::-webkit-search-cancel-button]:hidden [&::-webkit-search-cancel-button]:appearance-none"
                    data-testid="hub-find"
                />
                <button
                    v-if="query"
                    type="button"
                    aria-label="Clear search"
                    class="absolute top-1/2 right-0 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring lg:size-9"
                    data-testid="hub-clear"
                    @click="clear"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
        </div>
        <div role="status" class="mb-5 min-h-5 text-sm text-muted-foreground">
            <template v-if="resultLine">{{ resultLine }}</template>
            <p
                v-if="noMatch"
                class="rounded-lg border bg-card p-6 text-center"
                data-testid="hub-empty"
            >
                No page matches “{{ query.trim() }}”.
            </p>
        </div>
        <div
            class="grid gap-px overflow-hidden rounded-lg border bg-card md:grid-cols-2 xl:grid-cols-3"
            data-testid="clinic-management-cards"
            v-show="!noMatch"
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
