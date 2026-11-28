<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card } from '@/components/ui/card';
import { PageFrame } from '@/components/ui/page-frame';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <PageFrame width="default">
        <header class="min-w-0 space-y-1">
            <h2
                class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
            >
                Settings
            </h2>
            <p class="text-[13px] text-muted-foreground">
                Manage your profile and account settings
            </p>
        </header>

        <div
            class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_280px] lg:gap-6"
        >
            <Card class="order-2 gap-6 p-5 lg:order-1 lg:max-w-2xl">
                <section class="space-y-6">
                    <slot />
                </section>
            </Card>

            <Card class="order-1 gap-1 p-3 lg:order-2">
                <h3 class="px-2 py-1 text-base font-semibold">Your settings</h3>
                <nav class="flex flex-col gap-1" aria-label="Settings">
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
                        "
                        :class="[
                            'flex min-h-11 items-center rounded-lg px-3 text-sm text-foreground transition-colors hover:bg-accent lg:min-h-9',
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-muted font-semibold'
                                : 'font-medium',
                        ]"
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="mr-2 size-4"
                        />
                        {{ item.title }}
                    </Link>
                </nav>
            </Card>
        </div>
    </PageFrame>
</template>
