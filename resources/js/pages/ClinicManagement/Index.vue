<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    ArrowRight,
    Building2,
    ChartNoAxesCombined,
    ClipboardList,
    FileText,
    Globe,
    Ticket,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clinic management', href: '/clinic-management' },
        ],
    },
});
defineProps<{
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
};
</script>

<template>
    <Head title="Clinic management" />
    <main
        class="mx-auto w-full max-w-[1440px] flex-1 p-4 py-6 md:p-6 lg:px-8 lg:py-8"
    >
        <h1 class="mb-4 text-lg font-semibold tracking-tight">
            Clinic management
        </h1>
        <div
            class="grid gap-px overflow-hidden rounded-lg border bg-card md:grid-cols-2 xl:grid-cols-3"
            data-testid="clinic-management-cards"
        >
            <section
                v-for="(group, index) in groups"
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
                    <li v-for="link in group.links" :key="link.title">
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
