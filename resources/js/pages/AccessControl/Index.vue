<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Access Control', href: '/access-control' }],
    },
});
defineProps<{
    roles: Array<{ id: number; name: string; permissions: string[] }>;
    permissions: string[];
}>();
const label = (value: string) =>
    value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
</script>

<template>
    <Head title="Access Control" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <ShieldCheck class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Access Control
                </h1>
                <p class="text-sm text-muted-foreground">
                    Roles group permissions; permission scope remains explicit.
                </p>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <Card
                ><CardHeader
                    ><CardDescription>Configured roles</CardDescription
                    ><CardTitle class="text-3xl">{{
                        roles.length
                    }}</CardTitle></CardHeader
                ></Card
            ><Card
                ><CardHeader
                    ><CardDescription>Explicit permissions</CardDescription
                    ><CardTitle class="text-3xl">{{
                        permissions.length
                    }}</CardTitle></CardHeader
                ></Card
            >
        </div>
        <Card>
            <CardHeader
                ><CardTitle>Role permission matrix</CardTitle
                ><CardDescription
                    >Technical administration is intentionally separate from
                    future clinical-content access.</CardDescription
                ></CardHeader
            >
            <CardContent class="divide-y p-0">
                <div
                    v-for="role in roles"
                    :key="role.id"
                    class="grid gap-3 px-6 py-5 lg:grid-cols-[220px_1fr]"
                >
                    <div>
                        <div class="flex items-center gap-2 font-medium">
                            <KeyRound class="size-4 text-emerald-700" />{{
                                label(role.name)
                            }}
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ role.permissions.length }} permissions
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="permission in role.permissions"
                            :key="permission"
                            variant="outline"
                            class="font-mono text-[11px]"
                            >{{ permission }}</Badge
                        >
                    </div>
                </div>
            </CardContent>
        </Card>
    </main>
</template>
