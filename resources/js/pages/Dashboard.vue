<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    ChevronRight,
    ClipboardPlus,
    FileText,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { mainMenuGroups } from '@/lib/workspace-navigation';
import type { WorkspaceDestination } from '@/lib/workspace-navigation';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Main Menu', href: '/dashboard' }] },
});

defineProps<{
    summary: {
        organisation: string;
        activeBranch: { id: number; code: string; name: string } | null;
    };
}>();

const page = usePage();
const moduleGroups = computed(() =>
    page.props.workspace ? mainMenuGroups(page.props.workspace.navigation) : [],
);

const icons: Record<WorkspaceDestination['icon'], Component> = {
    activity: Activity,
    building: Building2,
    clipboard: ClipboardPlus,
    file: FileText,
    shield: ShieldCheck,
    users: Users,
};
</script>

<template>
    <Head title="Main Menu" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <header class="border-b border-border/80 pb-4">
            <h1 class="text-2xl font-semibold tracking-tight text-foreground">
                KPOne Main Menu
            </h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                Open an authorized workspace for {{ summary.organisation
                }}<template v-if="summary.activeBranch">
                    · {{ summary.activeBranch.name }}</template
                >.
            </p>
        </header>

        <div class="grid gap-7">
            <section
                v-for="(group, groupIndex) in moduleGroups"
                :key="group.label"
                :aria-labelledby="`module-group-${groupIndex}`"
            >
                <h2
                    :id="`module-group-${groupIndex}`"
                    class="mb-2.5 text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                >
                    {{ group.label }}
                </h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="destination in group.destinations"
                        :key="destination.href"
                        :href="destination.href"
                        class="group relative flex min-h-24 cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4 text-left transition-[border-color,background-color,box-shadow,transform] hover:-translate-y-px hover:border-foreground/20 hover:bg-muted/35 hover:shadow-sm focus-visible:border-pink-600 focus-visible:ring-2 focus-visible:ring-pink-600/35 focus-visible:ring-offset-2 focus-visible:outline-none dark:hover:border-foreground/30 dark:hover:bg-muted/25"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground transition-colors group-hover:bg-pink-50 group-hover:text-pink-800 dark:group-hover:bg-pink-950/35 dark:group-hover:text-pink-200"
                        >
                            <component
                                :is="icons[destination.icon]"
                                class="size-4.5"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-semibold text-foreground"
                            >
                                {{ destination.label }}
                            </span>
                            <span
                                class="mt-1 block text-xs leading-5 text-muted-foreground"
                            >
                                {{ destination.description }}
                            </span>
                        </span>
                        <ChevronRight
                            class="mt-1 size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </section>
        </div>
    </main>
</template>
