<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock3, UserRound } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
import { formatDateTime } from '@/lib/presentation';

defineProps<{
    branch: { id: number; code: string; name: string };
    items: Array<{
        publicId: string;
        status: string;
        submissionType: string;
        submittedAt: string;
        expiresAt: string;
        lockVersion: number;
        summary: {
            name: string;
            age: number | null;
            purpose: string | null;
            complaint: string | null;
            duplicateStatus: 'none' | 'possible';
        };
    }>;
}>();
const statusLabel: Record<string, string> = {
    pending: 'Pending',
    under_review: 'Under review',
    correction_required: 'Correction required',
};
const purposeLabel: Record<string, string> = {
    doctor_illness: 'See a doctor / illness',
    pregnancy_check: 'Pregnancy check',
    scan: 'Scan',
    vaccination: 'Vaccination',
    medical_checkup: 'Medical check-up',
    procedure: 'Procedure',
    other: 'Other',
};
</script>

<template>
    <Head title="QR Intake" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <PageHeader
            title="QR Intake"
            :description="`${branch.name} · Review public intake once before creating Patient, Visit and Queue records.`"
        />
        <div class="grid gap-4 xl:grid-cols-2">
            <Card v-for="item in items" :key="item.publicId" class="rounded-xl">
                <CardHeader
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <CardTitle class="flex items-center gap-2 text-base"
                                ><UserRound class="size-4" />{{
                                    item.summary.name
                                }}</CardTitle
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ item.summary.age ?? '—' }} years ·
                                {{
                                    item.submissionType === 'guardian'
                                        ? 'Guardian'
                                        : 'Patient'
                                }}
                            </p>
                        </div>
                        <Badge variant="outline">{{
                            statusLabel[item.status] ?? item.status
                        }}</Badge>
                    </div></CardHeader
                >
                <CardContent class="space-y-4"
                    ><dl class="grid grid-cols-2 gap-3 text-sm">
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">Purpose</dt>
                            <dd>
                                {{
                                    purposeLabel[item.summary.purpose ?? ''] ??
                                    'Not stated'
                                }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">
                                Brief complaint
                            </dt>
                            <dd class="line-clamp-2">
                                {{ item.summary.complaint }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Submitted</dt>
                            <dd>{{ formatDateTime(item.submittedAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Expires</dt>
                            <dd>{{ formatDateTime(item.expiresAt) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Duplicate check
                            </dt>
                            <dd>
                                {{
                                    item.summary.duplicateStatus === 'possible'
                                        ? 'Needs review'
                                        : 'No match found'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <Button as-child class="w-full max-lg:h-11"
                        ><Link :href="`/registration-review/${item.publicId}`"
                            >Review</Link
                        ></Button
                    ></CardContent
                >
            </Card>
            <Card v-if="items.length === 0" class="rounded-xl"
                ><CardContent
                    class="grid place-items-center gap-3 py-14 text-center text-muted-foreground"
                    ><Clock3 class="size-8" />
                    <p>
                        No public intake is waiting for this branch.
                    </p></CardContent
                ></Card
            >
        </div>
    </main>
</template>
