<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { PageFrame } from '@/components/ui/page-frame';
import { PageHeader } from '@/components/ui/page-header';
import type { TreatmentRoomDirectory } from '@/types/treatment-room';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Treatment room', href: '/treatment-room' }],
    },
});
defineProps<{ room: TreatmentRoomDirectory }>();
const visitUrl = (visitNumber: string) =>
    `/treatment-room/${encodeURIComponent(visitNumber)}`;
</script>

<template>
    <Head title="Treatment room" />
    <main class="flex min-w-0 flex-1 flex-col">
        <PageFrame class="sm:px-6">
            <PageHeader
                title="Patients for the treatment room"
                description="Serving consultations with treatment progress or a treatment-room call today."
            >
                <template #actions
                    ><Button variant="outline" as-child
                        ><Link href="/queue">Queue</Link></Button
                    ></template
                >
            </PageHeader>
            <p
                v-if="room.rows.length === 0"
                class="rounded-lg border bg-card p-6 text-sm text-muted-foreground shadow-card"
            >
                No patients are currently listed for the treatment room.
            </p>
            <div v-else>
                <div
                    class="hidden overflow-x-auto rounded-lg border bg-card shadow-card lg:block"
                >
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-b bg-muted/40 text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Patient</th>
                                <th class="px-4 py-3 font-medium">Queue</th>
                                <th class="px-4 py-3 font-medium">
                                    Treatment progress
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in room.rows"
                                :key="row.visitNumber"
                                class="border-b last:border-0"
                            >
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ row.patientName }}
                                    </p>
                                    <p
                                        class="text-[12.5px] text-muted-foreground"
                                    >
                                        {{ row.patientNumber }} ·
                                        {{ row.visitNumber }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium tabular-nums">
                                        {{ row.queueNumber }}
                                    </p>
                                    <p
                                        class="text-[12.5px] text-muted-foreground"
                                    >
                                        {{ row.isHeld ? 'On hold' : 'Serving' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p v-if="row.steps.length">
                                        {{ row.steps[0].label }} ·
                                        {{ row.steps[0].state }}
                                    </p>
                                    <p v-else class="text-muted-foreground">
                                        No step started
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        variant="outline"
                                        class="h-9"
                                        as-child
                                        ><Link
                                            :href="visitUrl(row.visitNumber)"
                                            >{{
                                                row.canRecord
                                                    ? 'Record treatment'
                                                    : 'View treatment'
                                            }}</Link
                                        ></Button
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="space-y-3 lg:hidden">
                    <article
                        v-for="row in room.rows"
                        :key="row.visitNumber"
                        class="space-y-3 rounded-lg border bg-card p-4 shadow-card"
                    >
                        <div>
                            <h2 class="font-semibold">
                                {{ row.queueNumber }} · {{ row.patientName }}
                            </h2>
                            <p class="text-[12.5px] text-muted-foreground">
                                {{ row.patientNumber }} · {{ row.visitNumber }}
                            </p>
                        </div>
                        <p class="text-sm">
                            {{ row.isHeld ? 'On hold' : 'Serving' }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                row.steps.length
                                    ? `${row.steps[0].label} · ${row.steps[0].state}`
                                    : 'No step started'
                            }}
                        </p>
                        <Button
                            variant="outline"
                            class="min-h-11 w-full"
                            as-child
                            ><Link :href="visitUrl(row.visitNumber)">{{
                                row.canRecord
                                    ? 'Record treatment'
                                    : 'View treatment'
                            }}</Link></Button
                        >
                    </article>
                </div>
            </div>
        </PageFrame>
    </main>
</template>
