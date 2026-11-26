<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        active: 'claims' | 'follow-up' | 'payments';
        canRecordPayments?: boolean;
    }>(),
    { canRecordPayments: true },
);
const page = usePage();
const links = computed(() => {
    if (!page.props.branchContext?.active) {
        return [];
    }

    const can = (permission: string) =>
        page.props.auth.permissions.includes(permission);

    return [
        {
            key: 'claims',
            label: 'Claims',
            href: '/panel-claims',
            visible: can('panel.claims.manage.branch'),
        },
        {
            key: 'follow-up',
            label: 'Follow-up list',
            href: '/panel-claims/follow-up',
            visible: can('panel.claims.manage.branch'),
        },
        {
            key: 'payments',
            label: 'Record Panel payment',
            href: '/panel-remittances',
            visible:
                can('panel.remittances.record.branch') &&
                props.canRecordPayments !== false,
        },
    ].filter((link) => link.visible);
});
</script>

<template>
    <nav
        v-if="links.length"
        aria-label="Panel workspace"
        class="flex flex-wrap gap-x-2 border-b"
    >
        <template v-for="link in links" :key="link.key">
            <span
                v-if="link.key === props.active"
                aria-current="page"
                class="inline-flex min-h-11 items-center border-b-2 border-primary px-3 py-2 text-sm font-semibold text-foreground lg:min-h-9"
                >{{ link.label }}</span
            >
            <Link
                v-else
                :href="link.href"
                class="inline-flex min-h-11 items-center border-b-2 border-transparent px-3 py-2 text-sm font-medium text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring lg:min-h-9"
                >{{ link.label }}</Link
            >
        </template>
    </nav>
</template>
