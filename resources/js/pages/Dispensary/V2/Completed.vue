<script setup lang="ts">
/**
 * Version 2 look for the completed Dispensary case (owner: "start the V2 Completed page", 2026-11-15). Look only: the
 * props, routes and the Medicine returns section are Classic's (`Dispensary/Completed.vue`), kept in step by
 * tests/Frontend/v2-dispensary-completed.test.mjs.
 */
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Printer } from '@lucide/vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type { DispensaryReturnsPanel } from '@/lib/dispensary-returns';
import MedicineReturnsSection from '@/pages/Dispensary/Partials/MedicineReturnsSection.vue';

const props = defineProps<{
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

// V2: crumbs above the title (PageFrame / usePageCrumbs), as on the open case.
usePageCrumbs(() => [
    { title: 'Registration', href: '/registration' },
    { title: props.dispensary.visit.visitNumber },
    { title: 'Dispensary completed' },
]);

// V2: one filled button, the next step (Continue to Billing); the rest are outline. 44 px below 1024, 36 px from 1024.
const filledButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9';
const outlineButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9 dark:border-foreground/20';
</script>

<template>
    <Head title="Dispensary completed" />
    <PageFrame>
        <V2WorkLayout
            title="Dispensary completed"
            eyebrow="Completed case"
            description="This case is read only. Fulfilment has finished and it cannot be returned to the doctor."
        >
            <template #actions>
                <!-- Phones: one column, full width, the filled next step on top (column-reverse keeps the DOM order). -->
                <div
                    class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row sm:flex-wrap"
                    data-testid="completed-actions"
                >
                    <a
                        v-if="dispensary.labelUrl"
                        :href="dispensary.labelUrl"
                        target="_blank"
                        rel="noopener"
                        :class="outlineButton"
                        data-testid="completed-print-labels"
                        ><Printer class="size-4" aria-hidden="true" />Print
                        current labels</a
                    >
                    <Link
                        href="/registration"
                        :class="outlineButton"
                        data-testid="completed-registration"
                        >Return to Registration</Link
                    >
                    <Link
                        v-if="dispensary.billingUrl"
                        :href="dispensary.billingUrl"
                        :class="filledButton"
                        data-testid="completed-billing"
                        >Continue to Billing</Link
                    >
                </div>
            </template>

            <template #main>
                <section
                    class="rounded-[14px] border bg-card p-4 dark:border-foreground/20"
                    data-testid="completed-patient"
                >
                    <p class="text-[13px] font-medium text-muted-foreground">
                        Patient
                    </p>
                    <h2
                        class="text-[17px] leading-snug font-semibold break-words"
                    >
                        {{ dispensary.patient.name }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ dispensary.patient.patientNumber }} ·
                        {{ dispensary.visit.visitNumber }}
                    </p>
                    <p
                        v-if="dispensary.completedAt"
                        class="mt-3 flex items-center gap-2 text-sm font-medium"
                        data-testid="completed-at"
                    >
                        <CheckCircle2
                            class="size-4 shrink-0 text-success"
                            aria-hidden="true"
                        />Completed {{ dispensary.completedAt }}
                    </p>
                    <p
                        v-if="returns?.lines.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        If the patient brings medicine back, record it under
                        Medicine returns.
                    </p>
                </section>

                <!-- Hakim: the Classic returns section relies on Classic's page rule for 44 px controls below 1024; keep it. -->
                <div
                    v-if="returns && returns.lines.length"
                    class="max-lg:[&_[data-slot=button]]:min-h-11 max-lg:[&_[data-slot=select-trigger]]:min-h-11 max-lg:[&_input:not([type=radio]):not([type=checkbox])]:min-h-11 max-lg:[&_select]:min-h-11 max-lg:[&_textarea]:min-h-11"
                    data-testid="completed-returns"
                >
                    <MedicineReturnsSection
                        v-if="returns && returns.lines.length"
                        :returns="returns"
                    />
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
