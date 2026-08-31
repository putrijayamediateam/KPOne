<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, Stethoscope } from '@lucide/vue';
import type { ClinicalHistoryDetailPage } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Queue', href: '/queue' }] },
});

const { historical } = defineProps<{
    historical: ClinicalHistoryDetailPage;
}>();

const display = (value: number | string | null, suffix = '') =>
    value === null ? 'Not recorded' : `${value}${suffix}`;
</script>

<template>
    <Head title="Previous Consultation" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    :href="historical.navigation.backUrl"
                    class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-800 hover:underline"
                >
                    <ArrowLeft class="size-3.5" />
                    {{ historical.navigation.backLabel }}
                </Link>
                <div class="flex items-center gap-3">
                    <div class="rounded-lg bg-emerald-100 p-2 text-emerald-800">
                        <Stethoscope class="size-5" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-semibold tracking-tight">
                                Previous Consultation
                            </h1>
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground"
                            >
                                Read only
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Historical clinical record
                        </p>
                    </div>
                </div>
            </div>
            <div class="text-right text-xs text-muted-foreground">
                <div class="font-medium text-foreground">
                    {{ historical.branch.name }}
                </div>
                <div>
                    {{
                        new Date(
                            historical.encounter.startedAt,
                        ).toLocaleString()
                    }}
                </div>
            </div>
        </header>

        <section class="grid gap-3 rounded-lg bg-muted/25 p-4 md:grid-cols-2">
            <div>
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Patient
                </div>
                <div class="font-medium">{{ historical.patient.name }}</div>
                <div class="font-mono text-xs text-muted-foreground">
                    {{ historical.patient.patientNumber }} ·
                    {{ historical.patient.dateOfBirth ?? 'DOB unknown' }} ·
                    {{ historical.patient.sex }}
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Encounter
                </div>
                <div class="font-mono text-sm">
                    {{ historical.visit.visitNumber }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ historical.encounter.attendingClinician }} · In progress
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <ClipboardList class="size-4 text-emerald-800" />
                <h2 class="font-semibold">Vitals</h2>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">
                        Blood pressure
                    </div>
                    <div class="mt-1 font-medium">
                        <template
                            v-if="
                                historical.vitals.systolicBp !== null &&
                                historical.vitals.diastolicBp !== null
                            "
                        >
                            {{ historical.vitals.systolicBp }} /
                            {{ historical.vitals.diastolicBp }} mmHg
                        </template>
                        <template v-else>Not recorded</template>
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Pulse</div>
                    <div class="mt-1 font-medium">
                        {{ display(historical.vitals.pulseBpm, ' bpm') }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Temperature</div>
                    <div class="mt-1 font-medium">
                        {{
                            display(historical.vitals.temperatureCelsius, ' °C')
                        }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">SpO2</div>
                    <div class="mt-1 font-medium">
                        {{ display(historical.vitals.spo2Percent, '%') }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Weight</div>
                    <div class="mt-1 font-medium">
                        {{ display(historical.vitals.weightKg, ' kg') }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Height</div>
                    <div class="mt-1 font-medium">
                        {{ display(historical.vitals.heightCm, ' cm') }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Derived BMI</div>
                    <div class="mt-1 font-medium">
                        {{ display(historical.vitals.bmi) }}
                    </div>
                </div>
                <div class="rounded-md bg-muted/25 p-3 text-sm">
                    <div class="text-xs text-muted-foreground">Observed</div>
                    <div class="mt-1 font-medium">
                        {{
                            historical.vitals.observedAt
                                ? new Date(
                                      historical.vitals.observedAt,
                                  ).toLocaleString()
                                : 'Not recorded'
                        }}
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="font-semibold">Clinical Note</h2>
            <div
                class="min-h-28 rounded-lg bg-muted/20 p-4 text-sm leading-relaxed whitespace-pre-wrap"
            >
                {{
                    historical.encounter.clinicalNote ??
                    'No clinical note recorded.'
                }}
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="font-semibold">Diagnoses</h2>
            <ol v-if="historical.diagnoses.length" class="space-y-2">
                <li
                    v-for="(diagnosis, index) in historical.diagnoses"
                    :key="`${index}-${diagnosis.diagnosisText}`"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-lg bg-muted/20 p-3 text-sm"
                >
                    <div>
                        <div class="font-medium">
                            {{ index + 1 }}. {{ diagnosis.diagnosisText }}
                            <span
                                v-if="diagnosis.isPrimary"
                                class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800"
                            >
                                Primary
                            </span>
                        </div>
                        <div
                            v-if="
                                diagnosis.diagnosisCode && diagnosis.codeSystem
                            "
                            class="mt-1 font-mono text-xs text-muted-foreground"
                        >
                            {{ diagnosis.codeSystem }} ·
                            {{ diagnosis.diagnosisCode }}
                        </div>
                    </div>
                </li>
            </ol>
            <p
                v-else
                class="rounded-lg bg-muted/20 px-4 py-5 text-sm text-muted-foreground"
            >
                No diagnosis recorded for this Encounter.
            </p>
        </section>
    </main>
</template>
