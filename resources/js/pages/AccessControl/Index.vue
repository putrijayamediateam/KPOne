<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageFrame } from '@/components/ui/page-frame';
import { formatRoleLabel } from '@/lib/displayLabels';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Access Control', href: '/access-control' }],
    },
});
defineProps<{
    roles: Array<{ id: number; name: string; permissions: string[] }>;
    permissions: string[];
}>();
</script>

<template>
    <Head title="Access Control" />
    <PageFrame>
        <header class="min-w-0 space-y-1">
            <h1
                class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
            >
                Access Control
            </h1>
            <p class="text-[13px] text-muted-foreground">
                Roles group permissions; permission scope remains explicit.
            </p>
        </header>
        <div class="grid gap-4 sm:grid-cols-2">
            <Card class="gap-1 px-5 py-5"
                ><CardHeader class="gap-1 px-0"
                    ><CardDescription>Configured roles</CardDescription
                    ><CardTitle class="text-[28px] font-bold tabular-nums">{{
                        roles.length
                    }}</CardTitle></CardHeader
                ></Card
            ><Card class="gap-1 px-5 py-5"
                ><CardHeader class="gap-1 px-0"
                    ><CardDescription>Explicit permissions</CardDescription
                    ><CardTitle class="text-[28px] font-bold tabular-nums">{{
                        permissions.length
                    }}</CardTitle></CardHeader
                ></Card
            >
        </div>
        <Card>
            <CardHeader
                ><CardTitle class="text-[15px]"
                    >Role permission matrix</CardTitle
                ><CardDescription
                    >Technical administration is intentionally separate from
                    future clinical-content access.</CardDescription
                ></CardHeader
            >
            <CardContent class="divide-y p-0">
                <div
                    v-for="role in roles"
                    :key="role.id"
                    class="grid gap-3 px-4 py-4 sm:px-5 lg:grid-cols-[220px_1fr]"
                >
                    <div>
                        <div class="flex items-center gap-2 font-semibold">
                            <KeyRound
                                class="size-4 text-accent-blue"
                                aria-hidden="true"
                            />{{ formatRoleLabel(role.name) }}
                        </div>
                        <div
                            class="mt-1 text-[12.5px] text-muted-foreground tabular-nums"
                        >
                            {{ role.permissions.length }} permissions
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="permission in role.permissions"
                            :key="permission"
                            variant="outline"
                            class="font-mono text-xs"
                            >{{ permission }}</Badge
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </PageFrame>
</template>
