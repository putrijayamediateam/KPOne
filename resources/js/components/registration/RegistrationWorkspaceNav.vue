<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        active: 'today' | 'patients' | 'qr';
        qrAvailable?: boolean;
        qrPendingCount?: number;
    }>(),
    { qrAvailable: undefined },
);
const page = usePage();
const links = computed(() => {
    const permissions = page.props.auth?.permissions ?? [];
    const branch = page.props.branchContext?.active;
    const canViewVisits =
        !!branch && permissions.includes('visits.view.branch');

    return [
        {
            key: 'today',
            label: 'Today',
            href: '/registration',
            visible: canViewVisits,
        },
        {
            key: 'qr',
            label: 'QR check-ins',
            href: '/registration?tab=qr-intake',
            visible:
                canViewVisits &&
                permissions.includes('public_intakes.review.branch') &&
                props.qrAvailable !== false,
        },
        {
            key: 'patients',
            label: 'Patients',
            href: '/patients',
            visible: permissions.includes('patients.search.organisation'),
        },
    ].filter((link) => link.visible);
});
</script>

<template>
    <nav
        v-if="links.length"
        aria-label="Registration workspace"
        class="flex flex-wrap gap-1 rounded-xl bg-muted p-1"
    >
        <Link
            v-for="link in links"
            :key="link.key"
            :href="link.href"
            :aria-current="active === link.key ? 'page' : undefined"
            class="inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:min-h-9"
            :class="
                active === link.key
                    ? 'bg-card text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'
            "
            >{{ link.label
            }}<span
                v-if="link.key === 'qr' && qrPendingCount !== undefined"
                class="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums"
                :aria-label="`${qrPendingCount} pending check-ins`"
                >{{ qrPendingCount }}</span
            ></Link
        >
    </nav>
</template>
