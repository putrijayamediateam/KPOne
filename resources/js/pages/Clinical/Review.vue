<script setup lang="ts">
/**
 * The Director's read-only view of a consultation, at any branch (owner decision P4; 15 and 16). The server recorded this
 * view before building the page. Nothing here is editable: no buttons except Back, no inputs, no action bar, and the data
 * carries no URL or version a form could post.
 */
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { PageHeader } from '@/components/ui/page-header';

type Medicine = {
    name: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string | null;
    quantity: string | null;
    dosage: string | null;
    frequency: string | null;
    duration: string | null;
    route: string | null;
    instruction: string | null;
    indication: string | null;
    precaution: string | null;
};

const props = defineProps<{
    review: {
        branch: string;
        timezone: string;
        patient: { name: string; patientNumber: string };
        visit: { visitNumber: string };
        doctor: string;
        status: string;
        startedAt: string;
        vitals: {
            observedAt: string | null;
            systolicBp: number | null;
            diastolicBp: number | null;
            pulseBpm: number | null;
            temperatureCelsius: string | number | null;
            spo2Percent: number | null;
            weightKg: string | number | null;
            heightCm: string | number | null;
            bmi: number | null;
        };
        clinicalNote: string | null;
        diagnoses: { text: string; code: string | null; isPrimary: boolean }[];
        allergies: {
            status: string;
            records: {
                allergen: string;
                reaction: string | null;
                severity: string | null;
            }[];
        };
        medicines: Medicine[];
        services: {
            name: string;
            unit: string | null;
            quantity: string | null;
            instruction: string | null;
        }[];
        withdrawn: { medicines: string[]; services: string[] };
    };
}>();

const statusLabel: Record<string, string> = {
    in_progress: 'In progress',
};
const severityLabel = (value: string | null): string | null =>
    value === null ? null : value.replace(/_/g, ' ');
const allergyLabel: Record<string, string> = {
    unknown: 'Allergies not recorded',
    no_known_allergies: 'No known allergies',
    has_allergies: 'Has allergies',
};
const qty = (value: string | null): string =>
    value === null ? '—' : String(Number(value));
const shown = (value: string | number | null, unit: string): string =>
    value === null ? '—' : `${Number(value)} ${unit}`;
const visitUrl = `/visits/${props.review.visit.visitNumber}`;
const withdrawnCount =
    props.review.withdrawn.medicines.length +
    props.review.withdrawn.services.length;
const hasVitals = Object.entries(props.review.vitals).some(
    ([key, value]) => key !== 'observedAt' && value !== null,
);
</script>

<template>
    <Head title="Consultation (read only)" />
    <main class="mx-auto grid w-full max-w-5xl gap-4 px-4 py-6">
        <ReadOnlyBanner>
            <a
                :href="visitUrl"
                class="inline-flex h-11 items-center gap-2 rounded-md border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                data-testid="review-back"
                ><ArrowLeft class="size-4" aria-hidden="true" />Back to visit</a
            >
        </ReadOnlyBanner>

        <PageHeader
            title="Consultation"
            :description="`${review.patient.name} · ${review.patient.patientNumber} · ${review.visit.visitNumber} · ${review.branch}`"
        />

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-consultation"
        >
            <h2 id="review-consultation" class="font-semibold">Consultation</h2>
            <p class="flex items-center gap-2" data-testid="review-status">
                <span
                    class="size-2 rounded-full bg-foreground/60"
                    aria-hidden="true"
                />
                <span class="font-medium">{{
                    statusLabel[review.status] ?? review.status
                }}</span>
            </p>
            <p class="text-muted-foreground">Doctor: {{ review.doctor }}</p>
            <p class="text-muted-foreground">Started {{ review.startedAt }}</p>
        </section>

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-allergies"
        >
            <h2 id="review-allergies" class="font-semibold">Allergies</h2>
            <p
                v-if="review.allergies.status !== 'has_allergies'"
                class="rounded-md border border-warning/25 bg-warning/10 px-3 py-2 text-warning-strong"
                data-testid="review-allergies-warning"
            >
                {{
                    review.allergies.status === 'unknown'
                        ? 'Allergy profile on record: not recorded or not reviewed.'
                        : 'Allergy profile on record: no known allergies.'
                }}
            </p>
            <p v-else class="flex items-center gap-2">
                <span
                    class="size-2 rounded-full bg-foreground/60"
                    aria-hidden="true"
                />
                {{ allergyLabel[review.allergies.status] }}
            </p>
            <ul v-if="review.allergies.records.length" class="list-disc pl-5">
                <li
                    v-for="(allergy, index) in review.allergies.records"
                    :key="index"
                >
                    {{ allergy.allergen
                    }}<template v-if="allergy.reaction">
                        · {{ allergy.reaction }}</template
                    ><template v-if="allergy.severity">
                        · {{ severityLabel(allergy.severity) }}</template
                    >
                </li>
            </ul>
        </section>

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-vitals"
        >
            <h2 id="review-vitals" class="font-semibold">Vital signs</h2>
            <p v-if="!hasVitals" class="text-muted-foreground">
                No vital signs recorded.
            </p>
            <dl
                v-else
                class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4"
                data-testid="review-vitals"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Blood pressure
                    </dt>
                    <dd>
                        {{
                            review.vitals.systolicBp === null
                                ? '—'
                                : `${review.vitals.systolicBp}/${review.vitals.diastolicBp} mmHg`
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Pulse</dt>
                    <dd>{{ shown(review.vitals.pulseBpm, 'bpm') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Temperature</dt>
                    <dd>{{ shown(review.vitals.temperatureCelsius, '°C') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">SpO₂</dt>
                    <dd>{{ shown(review.vitals.spo2Percent, '%') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Weight</dt>
                    <dd>{{ shown(review.vitals.weightKg, 'kg') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Height</dt>
                    <dd>{{ shown(review.vitals.heightCm, 'cm') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">BMI</dt>
                    <dd>{{ review.vitals.bmi ?? '—' }}</dd>
                </div>
            </dl>
            <p v-if="review.vitals.observedAt" class="text-muted-foreground">
                Recorded {{ review.vitals.observedAt }}
            </p>
        </section>

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-note"
        >
            <h2 id="review-note" class="font-semibold">Clinical note</h2>
            <p
                v-if="review.clinicalNote"
                class="break-words whitespace-pre-wrap"
                data-testid="review-note"
            >
                {{ review.clinicalNote }}
            </p>
            <p v-else class="text-muted-foreground">
                No clinical note recorded.
            </p>
        </section>

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-diagnoses"
        >
            <h2 id="review-diagnoses" class="font-semibold">Diagnoses</h2>
            <p v-if="!review.diagnoses.length" class="text-muted-foreground">
                No diagnosis recorded.
            </p>
            <ul v-else class="grid gap-1">
                <li
                    v-for="(diagnosis, index) in review.diagnoses"
                    :key="index"
                    class="flex flex-wrap items-center gap-x-2"
                >
                    <span class="break-words">{{ diagnosis.text }}</span>
                    <span v-if="diagnosis.code" class="text-muted-foreground"
                        >· {{ diagnosis.code }}</span
                    >
                    <span
                        v-if="diagnosis.isPrimary"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                        ><span
                            class="size-1.5 rounded-full bg-foreground/60"
                            aria-hidden="true"
                        />Primary</span
                    >
                </li>
            </ul>
        </section>

        <section class="grid gap-3" aria-labelledby="review-medicines">
            <h2 id="review-medicines" class="font-semibold">
                Medicines ordered
            </h2>
            <p
                v-if="!review.medicines.length"
                class="text-sm text-muted-foreground"
            >
                No medicines ordered.
            </p>
            <article
                v-for="(medicine, index) in review.medicines"
                :key="index"
                class="grid gap-3 rounded-md border p-4 text-sm dark:border-foreground/20"
                data-testid="review-medicine"
            >
                <h3 class="font-medium">
                    {{ medicine.name
                    }}<span
                        v-if="medicine.strength"
                        class="ml-1 font-normal text-muted-foreground"
                        >{{ medicine.strength }}</span
                    >
                </h3>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-muted-foreground">Quantity</dt>
                        <dd>
                            {{ qty(medicine.quantity) }}
                            {{ medicine.unit ?? '' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Dosage</dt>
                        <dd>{{ medicine.dosage || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Frequency</dt>
                        <dd>{{ medicine.frequency || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Duration</dt>
                        <dd>{{ medicine.duration || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Route</dt>
                        <dd>{{ medicine.route || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Instruction
                        </dt>
                        <dd class="break-words">
                            {{ medicine.instruction || '—' }}
                        </dd>
                    </div>
                </dl>
                <p v-if="medicine.indication" class="text-muted-foreground">
                    For: {{ medicine.indication }}
                </p>
                <p v-if="medicine.precaution" class="text-muted-foreground">
                    Precaution: {{ medicine.precaution }}
                </p>
            </article>
        </section>

        <section class="grid gap-3" aria-labelledby="review-services">
            <h2 id="review-services" class="font-semibold">Services ordered</h2>
            <p
                v-if="!review.services.length"
                class="text-sm text-muted-foreground"
            >
                No services ordered.
            </p>
            <article
                v-for="(service, index) in review.services"
                :key="index"
                class="grid gap-3 rounded-md border p-4 text-sm dark:border-foreground/20"
                data-testid="review-service"
            >
                <h3 class="font-medium">{{ service.name }}</h3>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-muted-foreground">Quantity</dt>
                        <dd>
                            {{ qty(service.quantity) }} {{ service.unit ?? '' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">
                            Instruction
                        </dt>
                        <dd class="break-words">
                            {{ service.instruction || '—' }}
                        </dd>
                    </div>
                </dl>
            </article>
        </section>

        <details
            v-if="withdrawnCount"
            class="rounded-md border p-4 text-sm text-muted-foreground dark:border-foreground/20"
            data-testid="review-withdrawn"
        >
            <summary class="cursor-pointer font-medium text-foreground">
                Withdrawn lines ({{ withdrawnCount }})
            </summary>
            <ul class="mt-2 list-disc pl-5">
                <li
                    v-for="name in [
                        ...review.withdrawn.medicines,
                        ...review.withdrawn.services,
                    ]"
                    :key="name"
                >
                    {{ name }}
                </li>
            </ul>
        </details>
    </main>
</template>
