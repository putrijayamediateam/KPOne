<script setup lang="ts">
// DSP-23: "Medicine ready for your check" on the consultation page. Each line shows what the doctor ordered beside
// what the CA prepared, the batch and expiry, and the patient's allergy status line (Daniel, note 3). CA changes show
// as a dot and a word, never colour alone. At 390 px the lines stack and both buttons are full width (note 9).
import { ClipboardCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import DoctorCheckActions from '@/components/dispensary/DoctorCheckActions.vue';
import type { DoctorDispensaryCheck } from '@/types';

const props = defineProps<{ check: DoctorDispensaryCheck }>();

const changeLabels: Record<string, string> = {
    edited: 'Edited by CA',
    added: 'Added by CA',
    removed: 'Removed by CA',
};
// Daniel and B.E.R.N.I.S.S: name the source, because the consultation's own banner speaks about this consultation's
// review, which isn't loaded once the case is with Dispensary. A missing profile is a warning, never "none".
const allergyRecorded = computed(
    () =>
        props.check.allergies.records.length > 0 ||
        props.check.allergies.status === 'no_known_allergies',
);
const allergyLine = computed(() => {
    const allergies = props.check.allergies;
    const what = allergies.records.length
        ? allergies.records.join(', ')
        : 'No known allergies';
    const when = allergies.updatedOn ? ` (updated ${allergies.updatedOn})` : '';

    return `Allergy profile on record: ${what}${when}`;
});
const quantity = (value: string | null) =>
    value === null ? '—' : String(Number(value));
const directions = (line: {
    dosage: string | null;
    frequency: string | null;
    duration: string | null;
}) =>
    [line.dosage ? `Dose ${line.dosage}` : null, line.frequency, line.duration]
        .filter(Boolean)
        .join(' · ') || '—';
</script>

<template>
    <section
        aria-labelledby="dispensary-check-heading"
        class="rounded-lg border bg-card text-sm"
        data-testid="dispensary-check-panel"
    >
        <header
            class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b px-3 py-2"
        >
            <ClipboardCheck class="size-4 shrink-0 text-brand-strong" />
            <h2 id="dispensary-check-heading" class="font-semibold">
                Medicine ready for your check
            </h2>
            <p class="text-xs text-muted-foreground">
                Sent{{ check.sentBy ? ` by ${check.sentBy}` : '' }}
                {{ check.sentAt ? `at ${check.sentAt}` : '' }}
            </p>
        </header>
        <p
            v-if="allergyRecorded"
            class="border-b px-3 py-2 text-xs"
            data-testid="dispensary-check-allergies"
        >
            {{ allergyLine }}
        </p>
        <p
            v-else
            class="flex items-start gap-2 border-b border-warning/40 bg-warning/10 px-3 py-2 text-xs text-warning-strong"
            data-testid="dispensary-check-allergies"
        >
            <TriangleAlert
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            Allergies not recorded. Ask the patient before you verify.
        </p>
        <ul class="divide-y">
            <li
                v-for="(line, index) in check.lines"
                :key="index"
                class="grid gap-2 px-3 py-2.5"
            >
                <div class="min-w-0">
                    <p class="font-medium break-words">
                        {{ line.name }}
                        <span
                            v-if="line.strength"
                            class="font-normal text-muted-foreground"
                            >{{ line.strength }}</span
                        >
                    </p>
                    <span
                        v-if="changeLabels[line.changeState]"
                        class="mt-1 inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs"
                        data-testid="dispensary-check-change"
                    >
                        <span
                            class="size-1.5 rounded-full bg-foreground/70"
                            aria-hidden="true"
                        />{{ changeLabels[line.changeState] }}
                    </span>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs">
                    <dt class="text-muted-foreground">Ordered</dt>
                    <dd>
                        <template v-if="line.source === 'ca'"
                            >Not ordered</template
                        >
                        <template v-else
                            >Quantity {{ quantity(line.ordered.quantity) }}
                            {{ line.unit }} ·
                            {{ directions(line.ordered) }}</template
                        >
                    </dd>
                    <dt class="text-muted-foreground">Prepared</dt>
                    <dd class="font-medium">
                        <template v-if="line.prepared.packText"
                            >{{ line.prepared.packText }} ·</template
                        >
                        <template v-else
                            >Quantity {{ quantity(line.prepared.quantity) }}
                            {{ line.unit }} ·</template
                        >
                        {{ directions(line.prepared) }}
                    </dd>
                </dl>
                <div class="text-xs">
                    <p class="text-muted-foreground">Batch</p>
                    <p v-if="!line.batches.length">No batch (not dispensed)</p>
                    <p v-for="(batch, b) in line.batches" :key="b">
                        {{ batch.batchNumber ?? '—' }} · exp
                        {{ batch.expiryDate ?? '—' }} ·
                        {{ quantity(batch.quantity) }} {{ line.unit }}
                    </p>
                </div>
            </li>
        </ul>
        <div class="border-t px-3 py-3">
            <DoctorCheckActions
                :verify-url="check.verifyUrl"
                :send-back-url="check.sendBackUrl"
                :case-lock-version="check.caseLockVersion"
                :expected-branch-id="check.expectedBranchId"
            />
        </div>
    </section>
</template>
