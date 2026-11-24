<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Eye } from '@lucide/vue';
import SupervisorCheckNote from '@/components/dispensary/SupervisorCheckNote.vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import type { DispensaryReturnsPanel } from '@/lib/dispensary-returns';
import type { DispensarySupervisorCheck } from '@/types';
import MedicineReturnsSection from './Partials/MedicineReturnsSection.vue';

defineProps<{
    dispensary: {
        status: 'completed';
        patient: { name: string; patientNumber: string };
        visit: { visitNumber: string };
        completedAt: string | null;
        billingUrl?: string;
        labelUrl?: string;
        // DSP-26: the Resident Doctor sees this page view only, with the label preview and a back link.
        viewOnly?: boolean;
        backLink?: { label: string; href: string };
    };
    // DSP-7: a separate prop, so the completed-case payload keeps its keys.
    returns?: DispensaryReturnsPanel;
    // DSP-23: set when a CA Supervisor checked the case instead of the doctor.
    supervisorCheck?: DispensarySupervisorCheck | null;
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
                    ><a
                        :href="dispensary.labelUrl"
                        target="_blank"
                        rel="noopener"
                        :data-testid="
                            dispensary.viewOnly ? 'view-labels' : undefined
                        "
                        ><template v-if="dispensary.viewOnly"
                            ><Eye class="size-4" />View labels</template
                        ><template v-else>Print current labels</template></a
                    ></Button
                >
                <!-- DSP-26: for the doctor this is the back link (to the consultation or Registration). -->
                <Button as-child variant="outline" size="sm">
                    <Link
                        v-if="dispensary.backLink"
                        :href="dispensary.backLink.href"
                        ><ArrowLeft class="size-4" />{{
                            dispensary.backLink.label
                        }}</Link
                    >
                    <Link v-else href="/registration"
                        >Return to Registration</Link
                    >
                </Button>
                <Button v-if="dispensary.billingUrl" as-child size="sm"
                    ><Link :href="dispensary.billingUrl"
                        >Continue to Billing</Link
                    ></Button
                >
            </template>
        </PageHeader>
        <!-- DSP-26 (Daniel): one muted view-only line; the back link is the header's outline button. -->
        <ReadOnlyBanner
            v-if="dispensary.viewOnly"
            class="mb-4"
            message="View only. You cannot change this case."
        />
        <SupervisorCheckNote
            v-if="supervisorCheck"
            class="mb-4"
            :check="supervisorCheck"
        />
        <p v-if="dispensary.completedAt" class="mt-3 text-sm">
            Completed {{ dispensary.completedAt }}
        </p>
        <p
            v-if="!dispensary.viewOnly"
            class="mt-1 text-sm text-muted-foreground"
        >
            This case is read-only. Fulfilment has finished and it cannot be
            returned to the doctor.
            <template v-if="returns?.lines.length"
                >If the patient brings medicine back, record it under Medicine
                returns.</template
            >
        </p>
        <MedicineReturnsSection
            v-if="returns && returns.lines.length"
            :returns="returns"
        />
    </main>
</template>
