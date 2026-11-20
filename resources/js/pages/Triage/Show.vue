<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import {
    READING_NAMES,
    loweringRefusal,
    rank,
    suggest,
    zoneToConfirm,
} from '@/lib/triage-zone';
import type { AgeBand, TriageLimit, TriageZone } from '@/lib/triage-zone';

// TRI-1b: the Classic triage form. The server decides every save; this page shows the CA the zone the approved
// guideline suggests as the readings are typed, and why a lower zone may not be possible yet (owner rule 1).
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Registration', href: '/registration?tab=triage' },
            { title: 'Triage', href: '/registration?tab=triage' },
        ],
    },
});

type Option = { value: string; label: string };
type Sign = { code: string; label: string };
const props = defineProps<{
    visit: {
        visitNumber: string;
        patientName: string;
        patientNumber: string;
        queueNumber: string | null;
        queueStatus: string | null;
        priority: string;
    };
    branchId: number;
    readOnly: string | null;
    expected: {
        visitLockVersion: number;
        queueLockVersion: number | null;
        latestTriageId: number | null;
        guidelineVersionId: number | null;
    };
    ageBand: AgeBand | null;
    guideline: {
        versionNumber: number;
        childAgeLimitYears: number;
        redFlags: Sign[];
        yellowSigns: Sign[];
        limits: TriageLimit[];
    } | null;
    latest: {
        sequence: number;
        readings: Record<string, string | null>;
        chosenZone: TriageZone | null;
        recordedAt: string;
        recordedBy: string | null;
    } | null;
    lowering: {
        previousZone: TriageZone | null;
        hasEarlierTriage: boolean;
        hadRedFlag: boolean;
        readingsToRetake: string[];
    };
    lowerReasons: Option[];
    higherReasons: Option[];
}>();

const fields = [
    { key: 'systolic_bp', label: 'Systolic BP', unit: 'mmHg', decimal: false },
    {
        key: 'diastolic_bp',
        label: 'Diastolic BP',
        unit: 'mmHg',
        decimal: false,
    },
    { key: 'pulse_bpm', label: 'Pulse', unit: 'bpm', decimal: false },
    {
        key: 'temperature_celsius',
        label: 'Temperature',
        unit: '°C',
        decimal: true,
    },
    { key: 'spo2_percent', label: 'SpO2', unit: '%', decimal: true },
    { key: 'weight_kg', label: 'Weight', unit: 'kg', decimal: true },
    { key: 'height_cm', label: 'Height', unit: 'cm', decimal: true },
] as const;
type Field = (typeof fields)[number]['key'];

const form = useForm({
    expected_branch_id: props.branchId,
    visit_lock_version: props.expected.visitLockVersion,
    queue_lock_version: props.expected.queueLockVersion,
    expected_latest_triage_id: props.expected.latestTriageId,
    expected_guideline_version_id: props.expected.guidelineVersionId,
    systolic_bp: '',
    diastolic_bp: '',
    pulse_bpm: '',
    temperature_celsius: '',
    spo2_percent: '',
    weight_kg: '',
    height_cm: '',
    red_flag_codes: [] as string[],
    yellow_sign_codes: [] as string[],
    chosen_zone: null as TriageZone | null,
    override_reason_code: null as string | null,
    idempotency_key: crypto.randomUUID(),
});
// A refused save comes back with the current versions; the readings the CA typed stay in the form.
watch(
    () => props.expected,
    (expected) => {
        form.visit_lock_version = expected.visitLockVersion;
        form.queue_lock_version = expected.queueLockVersion;
        form.expected_latest_triage_id = expected.latestTriageId;
        form.expected_guideline_version_id = expected.guidelineVersionId;
    },
);

const readings = computed<Record<string, string>>(() =>
    Object.fromEntries(fields.map((f) => [f.key, form[f.key]])),
);
const assessment = computed(() =>
    props.guideline && props.ageBand
        ? suggest(
              readings.value,
              form.red_flag_codes,
              form.yellow_sign_codes,
              props.ageBand,
              props.guideline.limits,
          )
        : null,
);
const context = computed(() =>
    assessment.value
        ? {
              suggested: assessment.value.zone,
              crossed: assessment.value.crossed,
              readings: readings.value,
              redFlags: form.red_flag_codes,
              ...props.lowering,
          }
        : null,
);
const offered = computed(() =>
    context.value ? zoneToConfirm(context.value) : null,
);
const zoneTouched = ref(false);
watch(
    offered,
    (value) => {
        if (value && !zoneTouched.value) {
            form.chosen_zone = value.zone;
        }
    },
    { immediate: true },
);
const choose = (zone: TriageZone) => {
    zoneTouched.value = true;
    form.chosen_zone = zone;
};
const resetZone = () => {
    zoneTouched.value = false;
    form.chosen_zone = offered.value?.zone ?? null;
};
const direction = computed(() => {
    if (!assessment.value || !form.chosen_zone) {
        return 0;
    }

    return Math.sign(rank(form.chosen_zone) - rank(assessment.value.zone));
});
const reasonOptions = computed(() =>
    direction.value < 0
        ? props.lowerReasons
        : direction.value > 0
          ? props.higherReasons
          : [],
);
watch(direction, () => {
    if (
        !reasonOptions.value.some((o) => o.value === form.override_reason_code)
    ) {
        form.override_reason_code = null;
    }
});
const refusal = computed(() =>
    context.value && form.chosen_zone
        ? loweringRefusal(form.chosen_zone, context.value)
        : null,
);
const tellDoctor = computed(
    () =>
        props.ageBand === 'child' &&
        assessment.value?.crossed.temperature_celsius?.direction ===
            'very_high',
);
const missingNames = computed(() =>
    fields
        .filter((f) => readings.value[f.key].trim() === '')
        .map((f) => READING_NAMES[f.key]),
);
const zoneLabel = (zone: TriageZone) =>
    zone.charAt(0).toUpperCase() + zone.slice(1);
const zoneTone = (zone: TriageZone) =>
    ({ green: 'success', yellow: 'warning', red: 'danger' })[zone] as
        'success' | 'warning' | 'danger';
const crossedText = (key: Field) => {
    const c = assessment.value?.crossed[key];

    if (!c) {
        return null;
    }

    return `${c.direction === 'very_high' ? 'Very high' : 'Very low'} · past the ${c.zone} limit`;
};
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const generalError = computed(
    () =>
        errorFor('lock_version') ||
        errorFor('triage') ||
        errorFor('expected_guideline_version_id') ||
        errorFor('expected_branch_id') ||
        errorFor('idempotency_key') ||
        errorFor('red_flag_codes') ||
        errorFor('yellow_sign_codes'),
);
const submit = () => {
    form.transform((data) => ({
        ...data,
        ...Object.fromEntries(
            fields.map((f) => [
                f.key,
                data[f.key].trim() === '' ? null : data[f.key].trim(),
            ]),
        ),
    })).post(`/visits/${props.visit.visitNumber}/triage`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Triage" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4 pb-28 md:px-8 md:pt-6"
    >
        <PageHeader
            title="Triage"
            :description="`${visit.patientName} · ${visit.patientNumber}${visit.queueNumber ? ` · ${visit.queueNumber}` : ''}`"
        >
            <template #actions>
                <Button as-child variant="outline" size="sm">
                    <Link href="/registration?tab=triage"
                        >Back to Triage tab</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <p
            v-if="readOnly"
            role="status"
            class="rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-sm text-warning-strong"
        >
            {{ readOnly }}
        </p>

        <section
            v-if="latest"
            aria-labelledby="latest-triage"
            class="rounded-lg border bg-card px-4 py-3 text-sm"
        >
            <h2 id="latest-triage" class="font-medium">
                Latest triage (save {{ latest.sequence }})
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ latest.recordedAt
                }}<span v-if="latest.recordedBy">
                    · {{ latest.recordedBy }}</span
                >
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                <StatusBadge
                    v-if="latest.chosenZone"
                    status="zone"
                    :tone="zoneTone(latest.chosenZone)"
                    :label="`${zoneLabel(latest.chosenZone)} zone`"
                />
                <span
                    v-for="f in fields.filter((f) => latest?.readings[f.key])"
                    :key="f.key"
                    class="tabular-nums"
                    >{{ f.label }} {{ latest.readings[f.key] }}
                    {{ f.unit }}</span
                >
            </div>
        </section>

        <form
            v-if="!readOnly"
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]"
            novalidate
            @submit.prevent="submit"
        >
            <div class="space-y-4">
                <p
                    v-if="!guideline"
                    class="rounded-lg border px-3 py-2 text-sm text-muted-foreground"
                >
                    No triage guideline is approved yet, so triage records the
                    readings only and suggests no zone.
                </p>
                <fieldset class="rounded-lg border bg-card p-4">
                    <legend class="px-1 text-sm font-medium">Readings</legend>
                    <p class="mb-3 text-xs text-muted-foreground">
                        No reading is required. A blank reading never counts as
                        normal.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="f in fields"
                            :key="f.key"
                            class="grid gap-1"
                        >
                            <label :for="`triage-${f.key}`" class="text-sm"
                                >{{ f.label }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ f.unit }})</span
                                ></label
                            >
                            <input
                                :id="`triage-${f.key}`"
                                v-model="form[f.key]"
                                :inputmode="f.decimal ? 'decimal' : 'numeric'"
                                autocomplete="off"
                                class="h-11 rounded-md border bg-background px-3 text-sm tabular-nums outline-none focus:border-ring focus:ring-2 focus:ring-ring/30 lg:h-9"
                                :class="
                                    crossedText(f.key)
                                        ? 'border-foreground font-semibold'
                                        : ''
                                "
                                :aria-invalid="
                                    errorFor(f.key) ? 'true' : undefined
                                "
                                :aria-describedby="`triage-${f.key}-note`"
                            />
                            <p
                                :id="`triage-${f.key}-note`"
                                class="min-h-4 text-xs"
                                :class="
                                    crossedText(f.key)
                                        ? 'font-semibold text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                <template v-if="crossedText(f.key)">{{
                                    crossedText(f.key)
                                }}</template>
                                <template
                                    v-else-if="
                                        lowering.readingsToRetake.includes(
                                            f.key,
                                        )
                                    "
                                    >Crossed a limit earlier: retake
                                    it</template
                                >
                            </p>
                            <InputError :message="errorFor(f.key)" />
                        </div>
                    </div>
                </fieldset>

                <fieldset
                    v-if="guideline && guideline.redFlags.length"
                    class="rounded-lg border bg-card p-4"
                >
                    <legend class="px-1 text-sm font-medium">Red flags</legend>
                    <p class="mb-2 text-xs text-muted-foreground">
                        Any red flag puts the patient in the red zone.
                    </p>
                    <label
                        v-for="sign in guideline.redFlags"
                        :key="sign.code"
                        class="flex min-h-11 items-start gap-3 py-1 text-sm lg:min-h-9"
                        ><input
                            v-model="form.red_flag_codes"
                            type="checkbox"
                            :value="sign.code"
                            class="mt-0.5 size-4 accent-primary"
                        /><span>{{ sign.label }}</span></label
                    >
                </fieldset>
                <fieldset
                    v-if="guideline && guideline.yellowSigns.length"
                    class="rounded-lg border bg-card p-4"
                >
                    <legend class="px-1 text-sm font-medium">
                        Yellow signs
                    </legend>
                    <label
                        v-for="sign in guideline.yellowSigns"
                        :key="sign.code"
                        class="flex min-h-11 items-start gap-3 py-1 text-sm lg:min-h-9"
                        ><input
                            v-model="form.yellow_sign_codes"
                            type="checkbox"
                            :value="sign.code"
                            class="mt-0.5 size-4 accent-primary"
                        /><span>{{ sign.label }}</span></label
                    >
                </fieldset>
            </div>

            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                <section
                    class="rounded-lg border bg-card p-4"
                    aria-labelledby="triage-zone-heading"
                >
                    <h2 id="triage-zone-heading" class="text-sm font-medium">
                        Zone
                    </h2>
                    <template v-if="assessment && form.chosen_zone">
                        <p
                            class="mt-2 flex flex-wrap items-center gap-2 text-sm"
                            aria-live="polite"
                        >
                            Suggested:
                            <StatusBadge
                                status="zone"
                                :tone="zoneTone(assessment.zone)"
                                :label="`${zoneLabel(assessment.zone)} zone`"
                            />
                        </p>
                        <p
                            v-if="offered?.heldBy"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Held at {{ zoneLabel(offered.zone) }}:
                            {{ offered.heldBy }}
                        </p>
                        <fieldset class="mt-3">
                            <legend class="text-xs text-muted-foreground">
                                Zone to save
                            </legend>
                            <div class="mt-1 grid grid-cols-3 gap-2">
                                <button
                                    v-for="zone in [
                                        'green',
                                        'yellow',
                                        'red',
                                    ] as TriageZone[]"
                                    :key="zone"
                                    type="button"
                                    class="min-h-11 rounded-md border px-2 text-sm lg:min-h-9"
                                    :class="
                                        form.chosen_zone === zone
                                            ? 'border-foreground bg-muted font-semibold'
                                            : 'text-muted-foreground'
                                    "
                                    :aria-pressed="form.chosen_zone === zone"
                                    @click="choose(zone)"
                                >
                                    {{ zoneLabel(zone) }}
                                </button>
                            </div>
                        </fieldset>
                        <button
                            v-if="zoneTouched"
                            type="button"
                            class="mt-2 text-xs underline"
                            @click="resetZone"
                        >
                            Use the suggested zone
                        </button>
                        <div
                            v-if="reasonOptions.length"
                            class="mt-3 grid gap-1"
                        >
                            <label for="triage-reason" class="text-xs">
                                {{
                                    direction < 0
                                        ? 'Reason for a lower zone (required)'
                                        : 'Reason for a higher zone (optional)'
                                }}
                            </label>
                            <select
                                id="triage-reason"
                                v-model="form.override_reason_code"
                                class="h-11 rounded-md border bg-background px-2 text-sm lg:h-9"
                            >
                                <option :value="null">Choose a reason</option>
                                <option
                                    v-for="o in reasonOptions"
                                    :key="o.value"
                                    :value="o.value"
                                >
                                    {{ o.label }}
                                </option>
                            </select>
                        </div>
                        <p
                            v-if="refusal"
                            role="alert"
                            class="mt-3 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                        >
                            {{ refusal }}
                        </p>
                        <InputError
                            :message="
                                errorFor('chosen_zone') ||
                                errorFor('override_reason_code')
                            "
                        />
                    </template>
                    <p v-else class="mt-2 text-sm text-muted-foreground">
                        Readings only (no guideline approved).
                    </p>
                    <p
                        v-if="tellDoctor"
                        role="alert"
                        class="mt-3 rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm font-semibold text-destructive"
                    >
                        Tell the doctor now
                    </p>
                    <p
                        v-if="missingNames.length"
                        class="mt-3 text-xs text-muted-foreground"
                    >
                        Not entered: {{ missingNames.join(', ') }}
                    </p>
                    <InputError class="mt-2" :message="generalError" />
                    <Button
                        type="submit"
                        class="mt-4 h-11 w-full lg:h-9"
                        :disabled="form.processing || Boolean(refusal)"
                    >
                        {{ form.processing ? 'Saving…' : 'Save triage' }}
                    </Button>
                </section>
            </aside>
        </form>
    </main>
</template>
