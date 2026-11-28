<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock3, MapPin } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageFrame } from '@/components/ui/page-frame';
import { StatusBadge } from '@/components/ui/status';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Branches', href: '/branches' }] },
});

defineProps<{
    branches: Array<{
        id: number;
        code: string;
        name: string;
        timezone: string;
        isActive: boolean;
    }>;
    selectedBranchId: number | null;
}>();
</script>

<template>
    <Head title="Branches" />
    <PageFrame>
        <header class="min-w-0 space-y-1">
            <h1
                class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
            >
                Branches
            </h1>
            <p class="text-[13px] text-muted-foreground">
                Locations available within your current permission scope.
            </p>
        </header>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="branch in branches"
                :key="branch.id"
                :class="
                    selectedBranchId === branch.id &&
                    'border-accent-blue ring-2 ring-accent-blue/20'
                "
            >
                <CardHeader
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <CardTitle class="text-[15px]">{{
                                branch.name
                            }}</CardTitle
                            ><CardDescription class="mt-1">{{
                                branch.code
                            }}</CardDescription>
                        </div>
                        <StatusBadge
                            :status="branch.isActive ? 'active' : 'inactive'"
                            :tone="branch.isActive ? 'success' : 'danger'"
                            :label="branch.isActive ? 'Active' : 'Inactive'"
                        /></div
                ></CardHeader>
                <CardContent class="space-y-4 text-sm">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <MapPin class="size-4" aria-hidden="true" /> Klinik
                        Putrijaya branch
                    </div>
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Clock3 class="size-4" aria-hidden="true" />
                        {{ branch.timezone }}
                    </div>
                    <Link
                        :href="`/branches/${branch.id}`"
                        class="inline-flex min-h-11 items-center font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring lg:min-h-9"
                        >View authorised branch →</Link
                    >
                </CardContent>
            </Card>
        </div>
    </PageFrame>
</template>
