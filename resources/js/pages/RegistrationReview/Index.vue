<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardCheck, Clock3, UserRound } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
    doctor_illness: 'Jumpa doktor / sakit',
    pregnancy_check: 'Pemeriksaan kehamilan',
    scan: 'Scan',
    vaccination: 'Vaksin',
    medical_checkup: 'Medical check-up',
    procedure: 'Prosedur',
    other: 'Lain-lain',
};
</script>

<template>
    <Head title="Pendaftaran QR" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <header class="space-y-1">
            <div class="flex items-center gap-2">
                <ClipboardCheck class="size-6 text-pink-700" />
                <h1 class="text-2xl font-semibold tracking-tight">
                    Pendaftaran QR
                </h1>
            </div>
            <p class="text-sm text-muted-foreground">
                {{ branch.name }} · Review public intake once before creating
                Patient, Visit and Queue records.
            </p>
        </header>
        <div class="grid gap-4 xl:grid-cols-2">
            <Card v-for="item in items" :key="item.publicId">
                <CardHeader
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <CardTitle class="flex items-center gap-2 text-base"
                                ><UserRound class="size-4" />{{
                                    item.summary.name
                                }}</CardTitle
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ item.summary.age ?? 'â€”' }} tahun Â·
                                {{
                                    item.submissionType === 'guardian'
                                        ? 'Penjaga'
                                        : 'Pesakit'
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
                            <dt class="text-muted-foreground">Tujuan</dt>
                            <dd>
                                {{
                                    purposeLabel[item.summary.purpose ?? ''] ??
                                    'Belum dinyatakan'
                                }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">Aduan ringkas</dt>
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
                            <dt class="text-muted-foreground">Duplikasi</dt>
                            <dd>
                                {{
                                    item.summary.duplicateStatus === 'possible'
                                        ? 'Semakan diperlukan'
                                        : 'Tiada calon'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <Button as-child class="w-full"
                        ><Link :href="`/registration-review/${item.publicId}`"
                            >Semak pendaftaran</Link
                        ></Button
                    ></CardContent
                >
            </Card>
            <Card v-if="items.length === 0"
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
