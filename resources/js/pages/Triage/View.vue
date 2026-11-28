<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import type { TriageZone } from '@/lib/triage-zone';

// TRI-1e (owner, 24 Nov 2026, "ya semua"): the patient's own doctor reads the visit's triage record. View only: no form,
// no lock version or idempotency key, nothing to post. The server recorded this open before it sent the page.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'My Queue', href: '/queue' },
            { title: 'Triage', href: '/queue' },
        ],
    },
});

type Save = {
    sequence: number;
    recordedAt: string;
    recordedBy: string | null;
    readings: Record<string, string | null>;
    guidelineVersion: number | null;
    redFlags: string[];
    yellowSigns: string[];
    suggestedZone: TriageZone | null;
    chosenZone: TriageZone | null;
    reason: string | null;
};
defineProps<{
    visit: {
        visitNumber: string;
        patientName: string;
        patientNumber: string;
        queueNumber: string | null;
        priority: string;
    };
    currentZone: TriageZone | null;
    saves: Save[];
}>();

const fields = [
    { key: 'systolic_bp', label: 'Systolic BP', unit: 'mmHg' },
    { key: 'diastolic_bp', label: 'Diastolic BP', unit: 'mmHg' },
    { key: 'pulse_bpm', label: 'Pulse', unit: 'bpm' },
    { key: 'temperature_celsius', label: 'Temperature', unit: '°C' },
    { key: 'spo2_percent', label: 'SpO2', unit: '%' },
    { key: 'weight_kg', label: 'Weight', unit: 'kg' },
    { key: 'height_cm', label: 'Height', unit: 'cm' },
];
// Daniel (TRI-1e review): show what was measured, not padded decimals ("96.00" reads "96", "37.20" reads "37.2").
// No shared vitals formatter exists yet (design debt DD-117, Queue & TV); this page keeps its own until it does.
const trimReading = (value: string) =>
    value.includes('.') ? value.replace(/\.?0+$/, '') : value;
const unitAfter = (unit: string) => (unit === '%' ? unit : ` ${unit}`);
const zoneLabel = (zone: TriageZone) =>
    ({ red: 'Red zone', yellow: 'Yellow zone', green: 'Green zone' })[zone];
const zoneTone = (zone: TriageZone) =>
    ({ green: 'success', yellow: 'warning', red: 'danger' })[zone] as
        'success' | 'warning' | 'danger';
</script>

<template>
    <Head title="Triage" />
    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-4 px-4 py-5 sm:px-6 lg:px-8 lg:py-6"
    >
        <PageHeader
            title="Triage"
            :description="`${visit.patientName} · ${visit.patientNumber}${visit.queueNumber ? ` · ${visit.queueNumber}` : ''}`"
        >
            <template #actions>
                <Button
                    as-child
                    variant="outline"
                    size="sm"
                    class="max-lg:h-11"
                >
                    <Link href="/queue">My Queue</Link>
                </Button>
            </template>
        </PageHeader>

        <p class="text-sm text-muted-foreground" data-testid="triage-view-note">
            View only. The CA records triage; you cannot change it here.
        </p>

        <section
            class="rounded-xl border border-border bg-card p-4 shadow-card sm:p-5"
            data-testid="triage-view-current"
        >
            <h2 class="text-[15px] font-semibold">Zone now</h2>
            <div class="mt-2">
                <StatusBadge
                    v-if="currentZone"
                    status="zone"
                    :tone="zoneTone(currentZone)"
                    :label="zoneLabel(currentZone)"
                />
                <span v-else-if="saves.length" class="text-sm">Triaged</span>
                <span v-else class="text-sm text-muted-foreground"
                    >No triage yet</span
                >
            </div>
        </section>

        <section
            v-for="save in saves"
            :key="save.sequence"
            class="rounded-xl border border-border bg-card p-4 shadow-card sm:p-5"
            data-testid="triage-view-save"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-[15px] font-semibold">
                    Triage {{ save.sequence }}
                </h2>
                <p class="text-[12.5px] text-muted-foreground">
                    {{ save.recordedAt
                    }}<span v-if="save.recordedBy">
                        · {{ save.recordedBy }}</span
                    >
                </p>
            </div>

            <dl
                class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg bg-card-quiet p-3 text-sm sm:grid-cols-4"
            >
                <div v-for="f in fields" :key="f.key">
                    <dt class="text-xs text-muted-foreground">{{ f.label }}</dt>
                    <dd class="font-medium tabular-nums">
                        <template v-if="save.readings[f.key]"
                            >{{ trimReading(save.readings[f.key] as string)
                            }}{{ unitAfter(f.unit) }}</template
                        >
                        <span v-else class="text-muted-foreground">—</span>
                    </dd>
                </div>
            </dl>

            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <p class="text-xs text-muted-foreground">Red flags</p>
                    <ul v-if="save.redFlags.length" class="list-disc pl-5">
                        <li v-for="flag in save.redFlags" :key="flag">
                            {{ flag }}
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground">None ticked</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Yellow signs</p>
                    <ul v-if="save.yellowSigns.length" class="list-disc pl-5">
                        <li v-for="sign in save.yellowSigns" :key="sign">
                            {{ sign }}
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground">None ticked</p>
                </div>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3 text-sm"
            >
                <span v-if="save.suggestedZone"
                    ><span class="text-muted-foreground"
                        >Guideline suggested</span
                    >
                    {{ zoneLabel(save.suggestedZone) }}</span
                >
                <span class="inline-flex items-center gap-2"
                    ><span class="text-muted-foreground">Chosen</span>
                    <StatusBadge
                        v-if="save.chosenZone"
                        status="zone"
                        :tone="zoneTone(save.chosenZone)"
                        :label="zoneLabel(save.chosenZone)"
                    />
                    <span v-else>Readings only</span></span
                >
                <span v-if="save.reason"
                    ><span class="text-muted-foreground">Reason</span>
                    {{ save.reason }}</span
                >
            </div>
            <p class="mt-2 text-[12.5px] text-muted-foreground">
                {{
                    save.guidelineVersion
                        ? `Triage guideline v${save.guidelineVersion}`
                        : 'No guideline in force'
                }}
            </p>
        </section>
    </div>
</template>
