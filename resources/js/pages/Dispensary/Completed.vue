<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import type { DispensaryReturnsPanel } from '@/lib/dispensary-returns';
import MedicineReturnsSection from './Partials/MedicineReturnsSection.vue';

defineProps<{
    dispensary: {
        status: 'completed';
        patient: { name: string; patientNumber: string };
        visit: { visitNumber: string };
        completedAt: string | null;
        billingUrl?: string;
        labelUrl?: string;
    };
    // DSP-7: a separate prop, so the completed-case payload keeps its keys.
    returns?: DispensaryReturnsPanel;
}>();
</script>

<template>
    <Head title="Dispensary completed" />
    <main
        class="mx-auto w-full max-w-5xl px-4 py-6 max-lg:[&_[data-slot=button]]:min-h-11 max-lg:[&_[data-slot=select-trigger]]:min-h-11 max-lg:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11 max-lg:[&_select]:min-h-11 max-lg:[&_textarea]:min-h-11"
    >
        <PageHeader
            title="Dispensary completed"
            :description="`${dispensary.patient.name} · ${dispensary.patient.patientNumber} · ${dispensary.visit.visitNumber}`"
        >
            <template #actions>
                <Button
                    v-if="dispensary.labelUrl"
                    as-child
                    variant="outline"
                    size="sm"
                    class="lg:h-9"
                    ><a
                        :href="dispensary.labelUrl"
                        target="_blank"
                        rel="noopener"
                        >Print current labels</a
                    ></Button
                >
                <Button as-child variant="outline" size="sm" class="lg:h-9">
                    <Link href="/registration">Return to Registration</Link>
                </Button>
                <Button
                    v-if="dispensary.billingUrl"
                    as-child
                    size="sm"
                    class="lg:h-9"
                    ><Link :href="dispensary.billingUrl"
                        >Continue to Billing</Link
                    ></Button
                >
            </template>
        </PageHeader>
        <!-- UI-RESTYLE D2 (GUIDE section 19): the status and the read-only note sit in one calm card. Layout only. -->
        <div
            class="mt-5 grid gap-1 rounded-[14px] border bg-card p-4"
            data-testid="completed-summary"
        >
            <p
                v-if="dispensary.completedAt"
                class="text-[15px] font-semibold tabular-nums"
            >
                Completed {{ dispensary.completedAt }}
            </p>
            <p class="text-[13px] text-muted-foreground">
                This case is read-only. Fulfilment has finished and it cannot be
                returned to the doctor.
                <template v-if="returns?.lines.length"
                    >If the patient brings medicine back, record it under
                    Medicine returns.</template
                >
            </p>
        </div>
        <MedicineReturnsSection
            v-if="returns && returns.lines.length"
            :returns="returns"
        />
    </main>
</template>
