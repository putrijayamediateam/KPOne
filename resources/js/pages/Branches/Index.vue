<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Clock3, MapPin } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

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
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <Building2 class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Branches</h1>
                <p class="text-sm text-muted-foreground">
                    Locations available within your current permission scope.
                </p>
            </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="branch in branches"
                :key="branch.id"
                :class="
                    selectedBranchId === branch.id &&
                    'border-emerald-400 ring-2 ring-emerald-500/10'
                "
            >
                <CardHeader
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <CardTitle>{{ branch.name }}</CardTitle
                            ><CardDescription class="mt-1">{{
                                branch.code
                            }}</CardDescription>
                        </div>
                        <Badge
                            :variant="
                                branch.isActive ? 'secondary' : 'destructive'
                            "
                            >{{
                                branch.isActive ? 'Active' : 'Inactive'
                            }}</Badge
                        >
                    </div></CardHeader
                >
                <CardContent class="space-y-4 text-sm">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <MapPin class="size-4" /> Klinik Putrijaya branch
                    </div>
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Clock3 class="size-4" /> {{ branch.timezone }}
                    </div>
                    <Link
                        :href="`/branches/${branch.id}`"
                        class="inline-flex font-medium text-emerald-700 hover:text-emerald-900"
                        >View authorised branch →</Link
                    >
                </CardContent>
            </Card>
        </div>
    </main>
</template>
