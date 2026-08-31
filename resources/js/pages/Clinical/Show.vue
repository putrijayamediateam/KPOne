<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ClipboardCheck,
    LoaderCircle,
    Plus,
    Stethoscope,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { ClinicalEncounterPage } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Queue', href: '/queue' }] },
});

const props = defineProps<{ clinical: ClinicalEncounterPage }>();
type NumericInput = number | string | null;
type DiagnosisInput = {
    diagnosis_text: string;
    diagnosis_code: string;
    code_system: string;
    is_primary: boolean;
};

const form = useForm({
    expected_branch_id: props.clinical.branch.id,
    lock_version: props.clinical.encounter.lockVersion,
    clinical_note: props.clinical.encounter.clinicalNote ?? '',
    vitals: {
        systolic_bp: props.clinical.vitals.systolicBp as NumericInput,
        diastolic_bp: props.clinical.vitals.diastolicBp as NumericInput,
        pulse_bpm: props.clinical.vitals.pulseBpm as NumericInput,
        temperature_celsius: props.clinical.vitals
            .temperatureCelsius as NumericInput,
        spo2_percent: props.clinical.vitals.spo2Percent as NumericInput,
        weight_kg: props.clinical.vitals.weightKg as NumericInput,
        height_cm: props.clinical.vitals.heightCm as NumericInput,
    },
    diagnoses: props.clinical.diagnoses.map((diagnosis): DiagnosisInput => ({
        diagnosis_text: diagnosis.diagnosisText,
        diagnosis_code: diagnosis.diagnosisCode ?? '',
        code_system: diagnosis.codeSystem ?? '',
        is_primary: diagnosis.isPrimary,
    })),
});

const bmi = computed(() => {
    const weight = Number(form.vitals.weight_kg);
    const height = Number(form.vitals.height_cm);

    return weight > 0 && height > 0
        ? (weight / (height / 100) ** 2).toFixed(1)
        : '—';
});
const stateError = computed(
    () =>
        form.errors.lock_version ||
        (form.errors as Record<string, string>).encounter ||
        form.errors.expected_branch_id,
);

const addDiagnosis = () => {
    form.diagnoses.push({
        diagnosis_text: '',
        diagnosis_code: '',
        code_system: '',
        is_primary: form.diagnoses.length === 0,
    });
};
const removeDiagnosis = (index: number) => {
    form.diagnoses.splice(index, 1);

    if (
        form.diagnoses.length &&
        !form.diagnoses.some((row) => row.is_primary)
    ) {
        form.diagnoses[0].is_primary = true;
    }
};
const setPrimary = (index: number) => {
    form.diagnoses.forEach((row, rowIndex) => {
        row.is_primary = rowIndex === index;
    });
};
const save = () => {
    form.patch(
        `/visits/${encodeURIComponent(props.clinical.visit.visitNumber)}/encounter`,
        {
            preserveScroll: true,
            onSuccess: () => {
                form.lock_version = props.clinical.encounter.lockVersion;
                form.defaults();
            },
        },
    );
};
</script>

<template>
    <Head title="Clinical Encounter" />
    <main class="flex flex-1 flex-col gap-4 p-4 pb-24 md:p-6 md:pb-24">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    href="/queue"
                    class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-800 hover:underline"
                >
                    <ArrowLeft class="size-3.5" /> My Queue
                </Link>
                <div class="flex items-center gap-3">
                    <div class="rounded-lg bg-emerald-100 p-2 text-emerald-800">
                        <Stethoscope class="size-5" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ clinical.patient.name }}
                        </h1>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ clinical.patient.patientNumber }} ·
                            {{ clinical.patient.dateOfBirth ?? 'DOB unknown' }}
                            ·
                            {{ clinical.patient.sex }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="text-right text-xs text-muted-foreground">
                <div class="font-medium text-foreground">
                    {{ clinical.branch.name }} · Queue
                    {{ clinical.queue.queueNumber }}
                </div>
                <div>
                    {{ clinical.encounter.attendingClinician }} · In progress
                </div>
            </div>
        </header>

        <section class="grid gap-3 rounded-lg bg-muted/25 p-4 md:grid-cols-3">
            <div>
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Visit
                </div>
                <div class="font-mono text-sm">
                    {{ clinical.visit.visitNumber }}
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Priority
                </div>
                <div
                    :class="
                        clinical.visit.priority === 'urgent'
                            ? 'font-semibold text-red-700'
                            : ''
                    "
                >
                    {{
                        clinical.visit.priority === 'urgent'
                            ? 'Urgent'
                            : 'Normal'
                    }}
                </div>
            </div>
            <div>
                <div
                    class="text-[11px] font-semibold text-muted-foreground uppercase"
                >
                    Registration reason
                </div>
                <div class="text-sm">
                    {{
                        clinical.visit.registrationReason ??
                        'No reason recorded'
                    }}
                </div>
            </div>
        </section>

        <div
            class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/50 p-3 text-sm text-amber-900"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            {{ clinical.limitations.structuredHistory }}
        </div>

        <div
            v-if="stateError"
            role="alert"
            class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            {{ stateError }}
        </div>

        <form class="space-y-5" @submit.prevent="save">
            <section class="space-y-3">
                <div>
                    <h2 class="font-semibold">Vitals</h2>
                    <p class="text-xs text-muted-foreground">
                        Enter the measurements available for this consultation.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="text-sm"
                        >Systolic BP
                        <input
                            v-model="form.vitals.systolic_bp"
                            inputmode="numeric"
                            type="number"
                            min="1"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.systolic_bp']"
                        />
                    </label>
                    <label class="text-sm"
                        >Diastolic BP
                        <input
                            v-model="form.vitals.diastolic_bp"
                            inputmode="numeric"
                            type="number"
                            min="1"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.diastolic_bp']"
                        />
                    </label>
                    <label class="text-sm"
                        >Pulse (bpm)
                        <input
                            v-model="form.vitals.pulse_bpm"
                            inputmode="numeric"
                            type="number"
                            min="1"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.pulse_bpm']"
                        />
                    </label>
                    <label class="text-sm"
                        >Temperature (°C)
                        <input
                            v-model="form.vitals.temperature_celsius"
                            inputmode="decimal"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.temperature_celsius']"
                        />
                    </label>
                    <label class="text-sm"
                        >SpO₂ (%)
                        <input
                            v-model="form.vitals.spo2_percent"
                            inputmode="decimal"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.spo2_percent']"
                        />
                    </label>
                    <label class="text-sm"
                        >Weight (kg)
                        <input
                            v-model="form.vitals.weight_kg"
                            inputmode="decimal"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.weight_kg']"
                        />
                    </label>
                    <label class="text-sm"
                        >Height (cm)
                        <input
                            v-model="form.vitals.height_cm"
                            inputmode="decimal"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3"
                        />
                        <InputError
                            :message="form.errors['vitals.height_cm']"
                        />
                    </label>
                    <div class="rounded-md bg-muted/35 p-3 text-sm">
                        <div class="text-xs text-muted-foreground">
                            Derived BMI
                        </div>
                        <div class="mt-1 text-lg font-semibold">{{ bmi }}</div>
                    </div>
                </div>
            </section>

            <section class="space-y-2">
                <div>
                    <h2 class="font-semibold">Clinical Note</h2>
                    <p class="text-xs text-muted-foreground">
                        Consultation findings only. This is separate from the
                        Registration reason.
                    </p>
                </div>
                <textarea
                    v-model="form.clinical_note"
                    maxlength="20000"
                    rows="9"
                    class="w-full rounded-md border bg-background p-3 text-sm"
                    placeholder="Record the clinical consultation note…"
                />
                <div class="flex justify-between text-xs text-muted-foreground">
                    <InputError :message="form.errors.clinical_note" />
                    <span
                        >{{ form.clinical_note.length.toLocaleString() }} /
                        20,000</span
                    >
                </div>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold">Diagnoses</h2>
                        <p class="text-xs text-muted-foreground">
                            Structured codes are optional in Phase 2A.
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="addDiagnosis"
                    >
                        <Plus class="size-4" /> Add diagnosis
                    </Button>
                </div>
                <InputError :message="form.errors.diagnoses" />
                <div
                    v-for="(diagnosis, index) in form.diagnoses"
                    :key="index"
                    class="grid gap-2 rounded-lg bg-muted/20 p-3 md:grid-cols-[minmax(260px,1.5fr)_160px_160px_auto_auto]"
                >
                    <label class="text-xs"
                        >Diagnosis
                        <input
                            v-model="diagnosis.diagnosis_text"
                            maxlength="500"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                        />
                        <InputError
                            :message="
                                form.errors[`diagnoses.${index}.diagnosis_text`]
                            "
                        />
                    </label>
                    <label class="text-xs"
                        >Code
                        <input
                            v-model="diagnosis.diagnosis_code"
                            maxlength="50"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                        />
                        <InputError
                            :message="
                                form.errors[`diagnoses.${index}.diagnosis_code`]
                            "
                        />
                    </label>
                    <label class="text-xs"
                        >Code system
                        <input
                            v-model="diagnosis.code_system"
                            maxlength="50"
                            class="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm"
                        />
                        <InputError
                            :message="
                                form.errors[`diagnoses.${index}.code_system`]
                            "
                        />
                    </label>
                    <label class="flex items-center gap-2 self-center text-sm">
                        <input
                            type="radio"
                            :checked="diagnosis.is_primary"
                            @change="setPrimary(index)"
                        />
                        Primary
                    </label>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="self-center"
                        aria-label="Remove diagnosis"
                        @click="removeDiagnosis(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <p
                    v-if="!form.diagnoses.length"
                    class="rounded-lg bg-muted/20 px-4 py-5 text-sm text-muted-foreground"
                >
                    No diagnosis recorded yet. An in-progress Encounter may be
                    saved without one.
                </p>
            </section>

            <section v-if="clinical.history.length" class="space-y-2">
                <h2 class="font-semibold">Recent Encounters</h2>
                <div
                    v-for="item in clinical.history"
                    :key="item.visitNumber"
                    class="flex flex-wrap justify-between gap-2 border-b py-2 text-sm last:border-0"
                >
                    <span>
                        <span class="font-medium">{{
                            new Date(item.startedAt).toLocaleDateString()
                        }}</span>
                        · {{ item.branch }} · {{ item.attendingClinician }}
                    </span>
                    <span class="text-muted-foreground">
                        {{
                            item.diagnoses
                                .map((row) => row.diagnosisText)
                                .join(', ') || 'No diagnosis recorded'
                        }}
                    </span>
                </div>
            </section>

            <div
                class="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 p-3 backdrop-blur md:left-64"
            >
                <div
                    class="mx-auto flex max-w-6xl items-center justify-between gap-3"
                >
                    <span class="text-xs text-muted-foreground">
                        <template v-if="form.processing"
                            >Saving clinical record…</template
                        >
                        <template v-else-if="form.recentlySuccessful"
                            >Saved.</template
                        >
                        <template v-else-if="form.isDirty"
                            >Unsaved changes</template
                        >
                        <template v-else
                            >Clinical record is up to date.</template
                        >
                    </span>
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                        />
                        <ClipboardCheck v-else class="size-4" />
                        {{
                            form.processing ? 'Saving…' : 'Save clinical record'
                        }}
                    </Button>
                </div>
            </div>
        </form>
    </main>
</template>
