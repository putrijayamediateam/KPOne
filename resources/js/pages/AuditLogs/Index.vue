<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageFrame } from '@/components/ui/page-frame';
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
            /** Q-4c: a short structural note, for example "Via Call next, from the Queue board". */
            detail?: string | null;
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
    <PageFrame>
        <header class="min-w-0 space-y-1">
            <h1
                class="text-[26px] font-semibold tracking-[-0.02em] text-foreground lg:text-[30px]"
            >
                Audit Logs
            </h1>
            <p class="text-[13px] text-muted-foreground">
                Append-oriented security and administration history.
            </p>
        </header>
        <Card>
            <CardHeader
                ><CardTitle class="text-[15px] tabular-nums"
                    >{{ logs.total }} recorded event{{
                        logs.total === 1 ? '' : 's'
                    }}</CardTitle
                ><CardDescription
                    >Sensitive credentials and token values are never
                    recorded.</CardDescription
                ></CardHeader
            >
            <CardContent class="p-0"
                ><div
                    class="overflow-x-auto"
                    tabindex="0"
                    role="region"
                    aria-label="Audit log table"
                >
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead
                            class="border-y bg-card-quiet text-[13px] font-semibold text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 sm:px-5">Event</th>
                                <th class="px-4 py-3 sm:px-5">Actor</th>
                                <th class="px-4 py-3 sm:px-5">Context</th>
                                <th class="px-4 py-3 sm:px-5">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            <tr
                                v-for="log in logs.data"
                                :key="log.id"
                                class="transition-colors hover:bg-muted"
                            >
                                <td class="px-4 py-3.5 sm:px-5">
                                    <div class="font-medium">
                                        {{
                                            formatAuditEventLabel(
                                                log.event,
                                                log.roleNames,
                                            )
                                        }}
                                    </div>
                                    <div
                                        v-if="log.detail"
                                        class="text-xs text-muted-foreground"
                                        data-testid="audit-detail"
                                    >
                                        {{ log.detail }}
                                    </div>
                                    <div
                                        v-if="log.subjectType"
                                        class="text-[12.5px] text-muted-foreground"
                                    >
                                        {{ log.subjectType
                                        }}<template v-if="log.subjectId">
                                            #{{ log.subjectId }}</template
                                        >
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 sm:px-5">
                                    {{ log.actor }}
                                </td>
                                <td class="px-4 py-3.5 sm:px-5">
                                    <Badge
                                        v-if="log.branch"
                                        variant="outline"
                                        >{{ log.branch }}</Badge
                                    ><span v-else class="text-muted-foreground"
                                        >Organisation</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-3.5 whitespace-nowrap text-muted-foreground tabular-nums sm:px-5"
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
    </PageFrame>
</template>
