<script setup lang="ts">
/**
 * DSP-25 (owner decision 4; answers P5, 15 and 16): the Director's read-only view of a dispensary case, at any branch.
 * The server recorded this view before building the page. Nothing here is editable: no buttons except Back, no inputs,
 * no action bar, and the data carries no URL or version a form could post (Daniel: render nothing disabled).
 */
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { PageHeader } from '@/components/ui/page-header';

type Side = {
    quantity: string | null;
    // DSP-24: "2 bottles (120 ml)" for a line dispensed in whole packs.
    packText?: string | null;
    dosage: string | null;
    frequency: string | null;
    duration: string | null;
    instruction: string | null;
};

const props = defineProps<{
    review: {
        status: string;
        caseType: string;
        branch: string;
        patient: { name: string; patientNumber: string };
        visit: { visitNumber: string };
        doctor: string | null;
        receivedAt: string | null;
        completedAt: string | null;
        allergies: {
            status: string;
            records: {
                allergen: string;
                reaction: string | null;
                severity: string | null;
            }[];
        };
        medicines: {
            name: string;
            strength: string | null;
            unit: string | null;
            source: string | null;
            changeState: string | null;
            status: string;
            ordered: Side;
            dispensed: Side;
            batches: {
                batchNumber: string | null;
                expiryDate: string | null;
                quantity: string;
            }[];
        }[];
        services: {
            name: string;
            unit: string | null;
            source: string | null;
            changeState: string | null;
            quantityOrdered: string | null;
            quantityPerformed: string | null;
        }[];
        returns: {
            medicine: string | null;
            unit: string | null;
            quantity: string;
            reason: string;
            condition: string;
            outcome: string | null;
            recordedAt: string | null;
        }[];
        returnsTotal: number;
    };
}>();

const statusLabel: Record<string, string> = {
    pending: 'Waiting to start',
    dispensing: 'Dispensing',
    returned_to_doctor: 'Returned to doctor',
    awaiting_doctor_check: "Waiting for doctor's check",
    completed: 'Completed',
};
const allergyLabel: Record<string, string> = {
    unknown: 'Allergies not recorded',
    no_known_allergies: 'No known allergies',
    has_allergies: 'Has allergies',
};
const outcomeLabel: Record<string, string> = {
    dispose: 'Disposed',
    quarantine: 'Quarantined',
    refuse: 'Refused',
};
const reasonLabel: Record<string, string> = {
    not_used: 'Patient did not use it',
    wrong_medicine_or_strength: 'Wrong medicine or strength',
    patient_reaction: 'Patient reaction',
    dispensing_error: 'Dispensing error',
    other: 'Other',
};
const changeLabel = (
    source: string | null,
    changeState: string | null,
): string | null => {
    if (source === 'ca') {
        return 'Added by CA';
    }

    if (changeState === 'removed') {
        return 'Removed by CA';
    }

    return changeState === 'edited' ? 'Edited by CA' : null;
};
const qty = (value: string | null): string =>
    value === null ? '—' : String(Number(value));
const directions = (side: Side): string =>
    [side.dosage, side.frequency, side.duration, side.instruction]
        .filter((part) => part)
        .join(', ') || '—';
const visitUrl = `/visits/${props.review.visit.visitNumber}`;
</script>

<template>
    <Head title="Dispensary case (read only)" />
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
            title="Dispensary case"
            :description="`${review.patient.name} · ${review.patient.patientNumber} · ${review.visit.visitNumber} · ${review.branch}`"
        />

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-case"
        >
            <h2 id="review-case" class="font-semibold">Case</h2>
            <p class="flex items-center gap-2" data-testid="review-status">
                <span
                    class="size-2 rounded-full bg-foreground/60"
                    aria-hidden="true"
                />
                <span class="font-medium">{{
                    statusLabel[review.status] ?? review.status
                }}</span>
                <span class="text-muted-foreground"
                    >·
                    {{
                        review.caseType === 'otc'
                            ? 'Medicine only (OTC)'
                            : 'Consultation'
                    }}</span
                >
            </p>
            <p v-if="review.doctor" class="text-muted-foreground">
                Doctor: {{ review.doctor }}
            </p>
            <p v-if="review.receivedAt" class="text-muted-foreground">
                Received {{ review.receivedAt }}
            </p>
            <p v-if="review.completedAt" class="text-muted-foreground">
                Completed {{ review.completedAt }}
            </p>
        </section>

        <section
            class="grid gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            aria-labelledby="review-allergies"
        >
            <h2 id="review-allergies" class="font-semibold">Allergies</h2>
            <p class="flex items-center gap-2">
                <span
                    class="size-2 rounded-full bg-foreground/60"
                    aria-hidden="true"
                />
                {{
                    allergyLabel[review.allergies.status] ??
                    review.allergies.status
                }}
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
                        · {{ allergy.severity }}</template
                    >
                </li>
            </ul>
        </section>

        <section class="grid gap-3" aria-labelledby="review-medicines">
            <h2 id="review-medicines" class="font-semibold">Medicines</h2>
            <p
                v-if="!review.medicines.length"
                class="text-sm text-muted-foreground"
            >
                No medicines on this case.
            </p>
            <article
                v-for="(medicine, index) in review.medicines"
                :key="index"
                class="grid gap-3 rounded-md border p-4 text-sm dark:border-foreground/20"
                data-testid="review-medicine"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-medium">
                        {{ medicine.name
                        }}<span
                            v-if="medicine.strength"
                            class="ml-1 font-normal text-muted-foreground"
                            >{{ medicine.strength }}</span
                        >
                    </h3>
                    <span
                        v-if="
                            changeLabel(medicine.source, medicine.changeState)
                        "
                        class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs dark:border-foreground/30"
                        ><span
                            class="size-1.5 rounded-full bg-foreground/60"
                            aria-hidden="true"
                        />{{
                            changeLabel(medicine.source, medicine.changeState)
                        }}</span
                    >
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium text-muted-foreground">
                            Doctor ordered
                        </p>
                        <p>
                            {{
                                medicine.source === 'ca'
                                    ? 'Not ordered by the doctor'
                                    : `${qty(medicine.ordered.quantity)} ${medicine.unit ?? ''} · ${directions(medicine.ordered)}`
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-muted-foreground">
                            Dispensed
                        </p>
                        <p>
                            <template v-if="medicine.dispensed.packText"
                                >{{ medicine.dispensed.packText }} ·</template
                            >
                            <template v-else
                                >{{ qty(medicine.dispensed.quantity) }}
                                {{ medicine.unit ?? '' }} ·</template
                            >
                            {{ directions(medicine.dispensed) }}
                        </p>
                    </div>
                </div>
                <ul
                    v-if="medicine.batches.length"
                    class="grid gap-1 text-muted-foreground"
                >
                    <li
                        v-for="(batch, batchIndex) in medicine.batches"
                        :key="batchIndex"
                    >
                        Batch {{ batch.batchNumber ?? '—' }} · exp
                        {{ batch.expiryDate ?? '—' }} ·
                        {{ qty(batch.quantity) }} {{ medicine.unit ?? '' }}
                    </li>
                </ul>
            </article>
        </section>

        <section
            v-if="review.services.length"
            class="grid gap-3"
            aria-labelledby="review-services"
        >
            <h2 id="review-services" class="font-semibold">Services</h2>
            <article
                v-for="(service, index) in review.services"
                :key="index"
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-4 text-sm dark:border-foreground/20"
            >
                <span class="font-medium">{{ service.name }}</span>
                <span class="text-muted-foreground"
                    >Ordered {{ qty(service.quantityOrdered) }} · Performed
                    {{ qty(service.quantityPerformed) }}</span
                >
            </article>
        </section>

        <section
            v-if="review.returns.length"
            class="grid gap-3"
            aria-labelledby="review-returns"
        >
            <h2 id="review-returns" class="font-semibold">Medicine returns</h2>
            <article
                v-for="(item, index) in review.returns"
                :key="index"
                class="grid gap-1 rounded-md border p-4 text-sm dark:border-foreground/20"
            >
                <p class="font-medium">
                    {{ item.medicine ?? 'Medicine' }} · {{ qty(item.quantity) }}
                    {{ item.unit ?? '' }}
                </p>
                <p
                    class="flex items-center gap-2"
                    data-testid="review-return-outcome"
                >
                    <span
                        class="size-2 rounded-full bg-foreground/60"
                        aria-hidden="true"
                    />
                    {{
                        item.outcome
                            ? (outcomeLabel[item.outcome] ?? item.outcome)
                            : 'Waiting for a check'
                    }}
                </p>
                <p class="text-muted-foreground">
                    {{ reasonLabel[item.reason] ?? item.reason }}
                    <template v-if="item.recordedAt">
                        · {{ item.recordedAt }}</template
                    >
                </p>
            </article>
            <p
                v-if="review.returnsTotal > review.returns.length"
                class="text-sm text-muted-foreground"
                data-testid="review-returns-cap"
            >
                Showing the latest {{ review.returns.length }} of
                {{ review.returnsTotal }}
            </p>
        </section>
    </main>
</template>
