<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    ChevronRight,
    FileText,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});

const props = defineProps<{
    summary: {
        organisation: string;
        activeBranch: { id: number; code: string; name: string } | null;
        availableBranches: number;
        visibleStaff: number;
        accessScope: string;
    };
}>();

const page = usePage();
const can = (permission: string) =>
    page.props.auth.permissions.includes(permission);

type ModuleTask = {
    title: string;
    description: string;
    href: string;
    icon: Component;
};

type ModuleGroup = {
    title: string;
    tasks: ModuleTask[];
};

const moduleGroups = computed<ModuleGroup[]>(() =>
    [
        {
            title: 'Financial work',
            tasks: [
                ...(page.props.workspace?.navigation.panelWork
                    ? [
                          {
                              title: 'Panel responsibility',
                              description:
                                  'Review coverage requests; claims submission is planned',
                              href: '/panel-claims',
                              icon: FileText,
                          },
                      ]
                    : []),
                ...(page.props.workspace?.navigation.financeWork
                    ? [
                          {
                              title: 'Finance / Billing',
                              description:
                                  'Review current branch invoices and outstanding balances',
                              href: '/financial-work',
                              icon: FileText,
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Organisation',
            tasks: [
                ...(can('branches.view.branch') ||
                can('branches.view.organisation')
                    ? [
                          {
                              title: 'Branches',
                              description: 'Manage available clinic locations',
                              href: '/branches',
                              icon: Building2,
                          },
                      ]
                    : []),
                ...(can('staff.view.own') ||
                can('staff.view.branch') ||
                can('staff.view.organisation')
                    ? [
                          {
                              title: 'Staff',
                              description: 'Staff you can access',
                              href: '/staff',
                              icon: Users,
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Security & access',
            tasks: [
                ...(can('access.view.organisation')
                    ? [
                          {
                              title: 'Access control',
                              description: 'Review roles and permissions',
                              href: '/access-control',
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
                ...(can('audit.view.organisation')
                    ? [
                          {
                              title: 'Audit logs',
                              description:
                                  'Review administrative and security activity',
                              href: '/audit-logs',
                              icon: FileText,
                          },
                      ]
                    : []),
            ],
        },
    ].filter((group) => group.tasks.length > 0),
);

const contextItems = computed(() => [
    {
        label: 'Active branch',
        value: props.summary.activeBranch?.name ?? 'None assigned',
    },
    {
        label: 'Available branches',
        value: String(props.summary.availableBranches),
    },
    {
        label: 'Staff you can access',
        value: String(props.summary.visibleStaff),
    },
    { label: 'Access scope', value: props.summary.accessScope },
]);
</script>

<template>
    <Head title="Main Menu" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-8"
    >
        <header class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight text-foreground">
                KPOne Main Menu
            </h1>
            <p class="text-sm text-muted-foreground">
                Open an authorized management module for
                {{ summary.organisation }}.
            </p>
        </header>

        <section
            v-if="moduleGroups.length > 0"
            class="grid gap-4 lg:grid-cols-2"
        >
            <div
                v-for="group in moduleGroups"
                :key="group.title"
                class="rounded-xl border bg-card"
            >
                <h2
                    class="border-b px-4 py-2.5 text-sm font-semibold text-foreground"
                >
                    {{ group.title }}
                </h2>
                <div class="divide-y">
                    <Link
                        v-for="task in group.tasks"
                        :key="task.href"
                        :href="task.href"
                        class="flex items-center gap-3 px-4 py-3 text-sm transition-colors duration-150 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <component
                            :is="task.icon"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-foreground">{{
                                task.title
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ task.description }}</span
                            >
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </div>
            </div>
        </section>

        <section>
            <h2
                class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Current context
            </h2>
            <div
                class="grid grid-cols-1 divide-y rounded-xl border bg-card sm:grid-cols-4 sm:divide-x sm:divide-y-0"
            >
                <div
                    v-for="item in contextItems"
                    :key="item.label"
                    class="px-4 py-3"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ item.label }}
                    </p>
                    <p
                        class="mt-0.5 truncate text-lg font-semibold text-foreground"
                    >
                        {{ item.value }}
                    </p>
                </div>
            </div>
        </section>
    </main>
</template>
