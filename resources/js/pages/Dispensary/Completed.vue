<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Eye } from '@lucide/vue';
import { ref } from 'vue';
import SupervisorCheckNote from '@/components/dispensary/SupervisorCheckNote.vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import type { DispensaryReturnsPanel } from '@/lib/dispensary-returns';
import type { DispensarySupervisorCheck } from '@/types';
import MedicineReturnsSection from './Partials/MedicineReturnsSection.vue';

const showHelpfulInfo = ref(false);

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
            class="[&_p]:break-words"
            :description="`${dispensary.patient.name} · ${dispensary.patient.patientNumber} · ${dispensary.visit.visitNumber}`"
        >
            <template #actions>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-11 lg:min-h-9"
                    :aria-expanded="showHelpfulInfo"
                    aria-controls="completed-helpful-info"
                    @click="showHelpfulInfo = !showHelpfulInfo"
                >
                    {{
                        showHelpfulInfo
                            ? 'Hide helpful info'
                            : 'Show helpful info'
                    }}
                </Button>
                <Button
                    v-if="dispensary.labelUrl"
                    as-child
                    variant="outline"
                    size="sm"
                    class="min-h-11 lg:min-h-9"
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
                <Button
                    as-child
                    variant="outline"
                    size="sm"
                    class="min-h-11 lg:min-h-9"
                >
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
                <Button
                    v-if="dispensary.billingUrl"
                    as-child
                    size="sm"
                    class="min-h-11 lg:min-h-9"
                    ><Link :href="dispensary.billingUrl"
                        >Continue to Billing</Link
                    ></Button
                >
            </template>
        </PageHeader>
        <!-- DSP-26 (Daniel): one muted view-only line; the back link is the header's outline button. -->
        <ReadOnlyBanner
            v-if="dispensary.viewOnly"
            class="my-4"
            message="View only. You cannot change this case."
        />
        <SupervisorCheckNote
            v-if="supervisorCheck"
            class="mb-4"
            :check="supervisorCheck"
        />
        <section
            v-if="dispensary.completedAt || !dispensary.viewOnly"
            aria-label="Completion status"
            class="my-5 flex flex-wrap items-start gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm"
            data-testid="completion-context"
        >
            <p
                v-if="dispensary.completedAt"
                class="max-w-full rounded-full bg-success-wash px-3 py-1.5 text-sm font-medium break-words text-success"
            >
                Completed {{ dispensary.completedAt }}
            </p>
            <p
                v-if="!dispensary.viewOnly"
                class="min-w-0 flex-1 basis-72 text-sm leading-6 text-muted-foreground"
            >
                This case is read-only. Fulfilment has finished and it cannot be
                returned to the doctor.
                <template v-if="returns?.lines.length"
                    >If the patient brings medicine back, record it under
                    Medicine returns.</template
                >
            </p>
        </section>
        <MedicineReturnsSection
            v-if="returns && returns.lines.length"
            :returns="returns"
            :view-only="dispensary.viewOnly"
        />
        <section
            v-show="showHelpfulInfo"
            id="completed-helpful-info"
            aria-label="Helpful info"
            class="mt-5 space-y-3"
        >
            <details class="rounded-2xl border border-border bg-card" open>
                <summary
                    class="min-h-11 cursor-pointer px-5 py-3 text-sm font-semibold lg:min-h-9"
                >
                    About this page
                </summary>
                <p class="px-5 pb-5 text-sm leading-6 text-muted-foreground">
                    A finished dispensary case with its medicine return records.
                    <template v-if="dispensary.viewOnly">
                        You can review the information here. Changes and
                        printing are handled by authorized staff.
                    </template>
                    <template v-else>
                        Use the available actions for current labels and the
                        visit's billing page.
                    </template>
                </p>
            </details>
            <details class="rounded-2xl border border-border bg-card">
                <summary
                    class="min-h-11 cursor-pointer px-5 py-3 text-sm font-semibold lg:min-h-9"
                >
                    Good to know
                </summary>
                <ul
                    class="list-disc space-y-2 px-5 pb-5 pl-10 text-sm leading-6 text-muted-foreground"
                >
                    <li>This case cannot be returned to the doctor.</li>
                    <li>
                        Recording a medicine return does not issue a refund.
                        Refunds follow the separate billing approval process.
                    </li>
                    <li>
                        An authorized checker who did not record the return
                        checks it separately. Controlled medicines cannot be
                        quarantined.
                    </li>
                </ul>
            </details>
        </section>
    </main>
</template>
