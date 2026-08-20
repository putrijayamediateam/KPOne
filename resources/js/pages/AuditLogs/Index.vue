<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatAuditEventLabel } from '@/lib/displayLabels';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audit Logs', href: '/audit-logs' }] },
});

defineProps<{
    logs: {
        data: Array<{
            id: number;
            event: string;
            actor: string;
            branch: string | null;
            subjectType: string | null;
            subjectId: number | null;
            occurredAt: string;
            roleNames: string[];
        }>;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
    };
}>();

const formatTime = (value: string) =>
    new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(value));
</script>

<template>
    <Head title="Audit Logs" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <ScrollText class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Audit Logs
                </h1>
                <p class="text-sm text-muted-foreground">
                    Append-oriented security and administration history.
                </p>
            </div>
        </div>
        <Card>
            <CardHeader
                ><CardTitle
                    >{{ logs.total }} recorded event{{
                        logs.total === 1 ? '' : 's'
                    }}</CardTitle
                ><CardDescription
                    >Sensitive credentials and token values are never
                    recorded.</CardDescription
                ></CardHeader
            >
            <CardContent class="p-0"
                ><div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-y bg-muted/40 text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-6 py-3">Event</th>
                                <th class="px-6 py-3">Actor</th>
                                <th class="px-6 py-3">Context</th>
                                <th class="px-6 py-3">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="log in logs.data" :key="log.id">
                                <td class="px-6 py-4">
                                    <div class="font-medium">
                                        {{
                                            formatAuditEventLabel(
                                                log.event,
                                                log.roleNames,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="log.subjectType"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ log.subjectType }} #{{
                                            log.subjectId
                                        }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">{{ log.actor }}</td>
                                <td class="px-6 py-4">
                                    <Badge
                                        v-if="log.branch"
                                        variant="outline"
                                        >{{ log.branch }}</Badge
                                    ><span v-else class="text-muted-foreground"
                                        >Organisation</span
                                    >
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatTime(log.occurredAt) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div></CardContent
            >
        </Card>
        <div class="flex justify-end gap-2">
            <Button v-if="logs.prev_page_url" variant="outline" as-child
                ><Link :href="logs.prev_page_url">Previous</Link></Button
            ><Button v-if="logs.next_page_url" variant="outline" as-child
                ><Link :href="logs.next_page_url">Next</Link></Button
            >
        </div>
    </main>
</template>
