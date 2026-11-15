<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import IssuePrintButton from '@/components/billing/IssuePrintButton.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { myr } from '@/types/billing';
import type { VisitHistoryPage } from '@/types/visitHistory';

/**
 * Version 2 patient Invoices page (shown only in V2; Classic is `pages/Visits/History.vue`, untouched). Read only: same
 * props, same routes, same permissions, nothing is calculated here. Every amount comes from `history.financial` and goes
 * through the same `myr()` formatter as Classic. The Print buttons are the same `IssuePrintButton` components with the
 * same urls, labels and slots (the Documents tab), so a change to printing touches one place.
 */
const props = defineProps<{ history: VisitHistoryPage }>();

usePageCrumbs(() => [
    { title: 'Registration', href: '/registration' },
    { title: props.history.visit.visitNumber },
]);

const page = usePage();
const tab = ref('all');
// BIL-5b: billing prints are recorded; the old print address only redirects to Issued copies (BIL-14).
const issueUrl = (url: string) => url.replace(/\/print$/, '/issue');
const tabs = computed(() => [
    { value: 'all', label: 'All' },
    { value: 'consultation', label: 'Consultation' },
    { value: 'items', label: `Items ${props.history.medicines.length}` },
    { value: 'services', label: `Services ${props.history.services.length}` },
    { value: 'documents', label: 'Documents' },
]);
const show = (name: 'consultation' | 'items' | 'services' | 'documents') =>
    tab.value === 'all' || tab.value === name;

const text = (value: number | string | null | undefined, suffix = '') =>
    value === null || value === undefined || value === ''
        ? 'Not recorded'
        : `${value}${suffix}`;
const medicineLine = (
    medicine: VisitHistoryPage['medicines'][number],
): string =>
    [medicine.dosage, medicine.frequency, medicine.duration, medicine.route]
        .filter(Boolean)
        .join(' | ');
const dispensedLine = (
    medicine: VisitHistoryPage['medicines'][number],
): string =>
    [
        medicine.dispensed?.dosage,
        medicine.dispensed?.frequency,
        medicine.dispensed?.duration,
        medicine.dispensed?.route,
    ]
        .filter(Boolean)
        .join(' | ');
const changeLabel = (state: string, source: string) =>
    state === 'removed'
        ? 'Removed by CA'
        : source === 'ca'
          ? 'Added by CA'
          : state === 'edited'
            ? 'Edited by CA'
            : '';
const statusLabel = (value: string | null) =>
    value ? value.replaceAll('_', ' ') : 'Not recorded';
const totals = computed(() => {
    const state = props.history.financial?.state;

    return state
        ? ([
              ['Total', state.total],
              ['Self-pay received', state.self_pay],
              ['Panel responsibility', state.panel],
              ['Pay later still owed', state.deferred],
          ] as Array<[string, number]>)
        : [];
});
const vitals = computed(() => {
    const v = props.history.consultation?.vitals;

    return v
        ? ([
              [
                  'Blood pressure',
                  v.systolicBp === null
                      ? 'Not recorded'
                      : `${v.systolicBp}/${v.diastolicBp ?? '–'} mmHg`,
              ],
              ['Pulse', text(v.pulseBpm, ' bpm')],
              ['Temperature', text(v.temperatureCelsius, ' °C')],
              ['SpO₂', text(v.spo2Percent, '%')],
              ['Weight', text(v.weightKg, ' kg')],
              ['Height', text(v.heightCm, ' cm')],
              ['BMI', text(v.bmi)],
          ] as Array<[string, string]>)
        : [];
});
const canSeeCertificates = computed(() =>
    page.props.auth.permissions.some((p) =>
        [
            'medical_certificates.view.own',
            'medical_certificates.view.branch',
        ].includes(p),
    ),
);
const hasDocuments = computed(
    () =>
        !!props.history.financial?.printUrl ||
        !!props.history.financial?.payments.some((p) => p.printUrl),
);

const card = 'rounded-[14px] border bg-card p-4 sm:p-5';
const linkButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
</script>

<template>
    <Head :title="`Visit ${history.visit.visitNumber}`" />
    <PageFrame>
        <V2WorkLayout
            eyebrow="Visit"
            :title="`Completed visit ${history.visit.visitNumber}`"
            rail-from="xl"
            rail-below="stack-bottom"
            rail-label="Invoice"
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <Link
                        v-if="history.consultation && canSeeCertificates"
                        :href="`/visits/${history.visit.visitNumber}/medical-certificates`"
                        :class="linkButton"
                        >Medical certificates</Link
                    >
                    <Link
                        v-if="history.links.consultation"
                        :href="history.links.consultation"
                        :class="linkButton"
                        >Consultation notes</Link
                    >
                    <Link
                        v-if="history.links.billing"
                        :href="history.links.billing"
                        :class="linkButton"
                        >Open billing</Link
                    >
                    <Link
                        :href="history.links.back"
                        :class="[
                            linkButton,
                            'max-sm:order-last max-sm:border-0 max-sm:bg-transparent max-sm:px-0 max-sm:text-primary',
                        ]"
                        >← {{ history.links.backLabel }}</Link
                    >
                </div>
            </template>

            <template #main>
                <div class="flex flex-wrap items-center gap-2">
                    <V2Chip tone="neutral" label="Completed · read only" />
                </div>

                <div
                    class="grid items-start gap-4 md:grid-cols-2"
                    data-testid="history-patient"
                >
                    <section :class="card">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold break-words">
                                    {{ history.patient.name }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ history.patient.patientNumber }}
                                    <template v-if="history.patient.age">
                                        · {{ history.patient.age }}
                                    </template>
                                    <template v-if="history.patient.sex">
                                        · {{ history.patient.sex }}
                                    </template>
                                </p>
                            </div>
                            <Link
                                v-if="history.patient.profileUrl"
                                :href="history.patient.profileUrl"
                                class="shrink-0 text-sm font-medium text-primary hover:underline"
                                >View profile</Link
                            >
                        </div>
                    </section>
                    <section :class="card">
                        <h2 class="text-base font-semibold">Visit</h2>
                        <dl
                            class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm"
                        >
                            <dt class="text-muted-foreground">Branch</dt>
                            <dd class="min-w-0 break-words">
                                {{ history.visit.branch }}
                            </dd>
                            <dt class="text-muted-foreground">Type</dt>
                            <dd class="capitalize">{{ history.visit.type }}</dd>
                            <dt class="text-muted-foreground">Doctor</dt>
                            <dd class="min-w-0 break-words">
                                {{ text(history.visit.doctor) }}
                            </dd>
                            <dt class="text-muted-foreground">Coverage</dt>
                            <dd class="min-w-0 break-words">
                                {{ history.visit.coverage }}
                            </dd>
                            <dt class="text-muted-foreground">Reason</dt>
                            <dd class="min-w-0 break-words">
                                {{ text(history.visit.reason) }}
                            </dd>
                            <dt class="text-muted-foreground">Registered</dt>
                            <dd>{{ history.visit.registeredAt }}</dd>
                            <dt class="text-muted-foreground">Completed</dt>
                            <dd>{{ text(history.visit.completedAt) }}</dd>
                        </dl>
                    </section>
                </div>

                <section :class="card">
                    <V2Tabs
                        v-model="tab"
                        :tabs="tabs"
                        ariaLabel="Visit history content"
                    />
                    <div
                        role="tabpanel"
                        aria-label="Visit history content"
                        class="mt-4 grid gap-6"
                    >
                        <div
                            v-if="show('consultation')"
                            data-testid="history-consultation"
                        >
                            <h2 class="mb-2 text-base font-semibold">
                                Consultation
                            </h2>
                            <p
                                v-if="!history.consultation"
                                class="text-sm text-muted-foreground"
                            >
                                No consultation record for this visit.
                            </p>
                            <div v-else class="grid gap-3 text-sm">
                                <p class="text-muted-foreground">
                                    {{ text(history.consultation.doctor) }} ·
                                    {{ history.consultation.startedAt }}
                                </p>
                                <dl
                                    class="grid grid-cols-2 gap-3 rounded-[10px] bg-muted/40 p-3 sm:grid-cols-4"
                                >
                                    <div
                                        v-for="[label, value] in vitals"
                                        :key="label"
                                    >
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ label }}
                                        </dt>
                                        <dd>{{ value }}</dd>
                                    </div>
                                </dl>
                                <div>
                                    <p
                                        class="text-xs font-semibold text-muted-foreground uppercase"
                                    >
                                        Diagnoses
                                    </p>
                                    <p
                                        v-if="
                                            !history.consultation.diagnoses
                                                .length
                                        "
                                        class="text-muted-foreground"
                                    >
                                        None recorded
                                    </p>
                                    <ul v-else class="list-disc pl-5">
                                        <li
                                            v-for="(diagnosis, index) in history
                                                .consultation.diagnoses"
                                            :key="index"
                                        >
                                            {{ diagnosis.text }}
                                            <span
                                                v-if="diagnosis.code"
                                                class="text-xs text-muted-foreground"
                                                >({{ diagnosis.code }})</span
                                            >
                                            <V2Chip
                                                v-if="diagnosis.isPrimary"
                                                tone="success"
                                                label="Primary"
                                            />
                                        </li>
                                    </ul>
                                </div>
                                <div>
                                    <p
                                        class="text-xs font-semibold text-muted-foreground uppercase"
                                    >
                                        Clinical note
                                    </p>
                                    <p class="whitespace-pre-wrap">
                                        {{ text(history.consultation.note) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div v-if="show('items')" data-testid="history-items">
                            <h2 class="mb-2 text-base font-semibold">Items</h2>
                            <p
                                v-if="!history.medicines.length"
                                class="text-sm text-muted-foreground"
                            >
                                No medicines were ordered or dispensed.
                            </p>
                            <ul v-else class="grid gap-3">
                                <li
                                    v-for="(
                                        medicine, index
                                    ) in history.medicines"
                                    :key="index"
                                    class="grid gap-2 rounded-[10px] border p-3 text-sm"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline justify-between gap-2"
                                    >
                                        <span class="font-medium break-words">
                                            {{ medicine.name }}
                                            <span
                                                v-if="medicine.strength"
                                                class="text-xs font-normal text-muted-foreground"
                                                >{{ medicine.strength }}</span
                                            >
                                        </span>
                                        <span
                                            v-if="
                                                changeLabel(
                                                    medicine.changeState,
                                                    medicine.source,
                                                )
                                            "
                                            data-testid="change-label"
                                            ><V2Chip
                                                tone="info"
                                                :label="
                                                    changeLabel(
                                                        medicine.changeState,
                                                        medicine.source,
                                                    )
                                                "
                                        /></span>
                                    </div>
                                    <div
                                        class="grid gap-2 sm:grid-cols-2"
                                        data-testid="ordered-vs-dispensed"
                                    >
                                        <div
                                            class="rounded-[8px] bg-muted/40 p-2 text-xs"
                                            data-testid="ordered-side"
                                        >
                                            <div class="font-medium">
                                                Ordered
                                            </div>
                                            <template
                                                v-if="
                                                    medicine.quantityOrdered !==
                                                    null
                                                "
                                            >
                                                <div>
                                                    {{
                                                        medicine.quantityOrdered
                                                    }}
                                                    {{ medicine.unit }}
                                                </div>
                                                <div
                                                    class="text-muted-foreground"
                                                >
                                                    {{ medicineLine(medicine) }}
                                                </div>
                                                <div
                                                    v-if="medicine.instruction"
                                                    class="text-muted-foreground"
                                                >
                                                    {{ medicine.instruction }}
                                                </div>
                                                <div
                                                    v-if="medicine.precaution"
                                                    class="text-muted-foreground"
                                                >
                                                    Precaution:
                                                    {{ medicine.precaution }}
                                                </div>
                                            </template>
                                            <div
                                                v-else
                                                class="text-muted-foreground"
                                            >
                                                Not ordered by a doctor
                                            </div>
                                        </div>
                                        <div
                                            class="rounded-[8px] bg-muted/40 p-2 text-xs"
                                            data-testid="dispensed-side"
                                        >
                                            <div class="font-medium">
                                                Dispensed
                                            </div>
                                            <template v-if="medicine.dispensed">
                                                <div>
                                                    {{
                                                        text(
                                                            medicine.dispensedQuantity,
                                                        )
                                                    }}
                                                    {{ medicine.unit }} ({{
                                                        statusLabel(
                                                            medicine.dispensedStatus,
                                                        )
                                                    }})
                                                </div>
                                                <div
                                                    class="text-muted-foreground"
                                                >
                                                    {{
                                                        dispensedLine(medicine)
                                                    }}
                                                </div>
                                                <div
                                                    v-if="
                                                        medicine.dispensed
                                                            .instruction
                                                    "
                                                    class="text-muted-foreground"
                                                >
                                                    {{
                                                        medicine.dispensed
                                                            .instruction
                                                    }}
                                                </div>
                                                <div
                                                    v-if="
                                                        medicine.dispensed
                                                            .precaution
                                                    "
                                                    class="text-muted-foreground"
                                                >
                                                    Precaution:
                                                    {{
                                                        medicine.dispensed
                                                            .precaution
                                                    }}
                                                </div>
                                            </template>
                                            <div
                                                v-else
                                                class="text-muted-foreground"
                                            >
                                                Not recorded
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="show('services')"
                            data-testid="history-services"
                        >
                            <h2 class="mb-2 text-base font-semibold">
                                Services
                            </h2>
                            <p
                                v-if="!history.services.length"
                                class="text-sm text-muted-foreground"
                            >
                                No services were ordered or performed.
                            </p>
                            <ul v-else class="grid gap-3">
                                <li
                                    v-for="(service, index) in history.services"
                                    :key="index"
                                    class="grid gap-1 rounded-[10px] border p-3 text-sm"
                                >
                                    <div
                                        class="flex flex-wrap items-baseline justify-between gap-2"
                                    >
                                        <span class="font-medium break-words">{{
                                            service.name
                                        }}</span>
                                        <span
                                            v-if="
                                                changeLabel(
                                                    service.changeState,
                                                    service.source,
                                                )
                                            "
                                            data-testid="service-change-label"
                                            ><V2Chip
                                                tone="info"
                                                :label="
                                                    changeLabel(
                                                        service.changeState,
                                                        service.source,
                                                    )
                                                "
                                        /></span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        <template
                                            v-if="
                                                service.quantityOrdered !== null
                                            "
                                            >Ordered
                                            {{ service.quantityOrdered }}
                                            {{ service.unit }} · </template
                                        ><template v-else
                                            >Not ordered by a doctor · </template
                                        >Performed
                                        {{ text(service.quantityPerformed) }}
                                        {{ service.unit }} ·
                                        {{ statusLabel(service.disposition) }}
                                        <template v-if="service.performedAt">
                                            · {{ service.performedAt }}
                                        </template>
                                    </div>
                                    <div
                                        v-if="
                                            service.finalInstruction ??
                                            service.instruction
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            service.finalInstruction ??
                                            service.instruction
                                        }}
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="show('documents')"
                            data-testid="history-documents"
                        >
                            <h2 class="mb-2 text-base font-semibold">
                                Documents
                            </h2>
                            <p
                                v-if="!hasDocuments"
                                class="text-sm text-muted-foreground"
                            >
                                No printable documents for your access.
                            </p>
                            <ul
                                v-else
                                class="grid gap-1 text-sm [&_button]:h-auto [&_button]:max-w-full [&_button]:text-left [&_button]:break-all [&_button]:whitespace-normal"
                            >
                                <li v-if="history.financial?.printUrl">
                                    <IssuePrintButton
                                        :url="
                                            issueUrl(history.financial.printUrl)
                                        "
                                        :label="`Print invoice ${history.financial.invoiceNumber}`"
                                        variant="ghost"
                                        return-to="history"
                                    />
                                </li>
                                <template
                                    v-for="payment in history.financial
                                        ?.payments ?? []"
                                    :key="payment.receiptNumber"
                                >
                                    <li v-if="payment.printUrl">
                                        <IssuePrintButton
                                            :url="issueUrl(payment.printUrl)"
                                            :label="`Print receipt ${payment.receiptNumber}`"
                                            variant="ghost"
                                            return-to="history"
                                        />
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </section>
            </template>

            <template #rail>
                <div data-testid="history-financial" class="grid min-w-0 gap-4">
                    <section v-if="history.financial" :class="card">
                        <div class="flex items-center justify-between gap-2">
                            <h2
                                class="min-w-0 text-base font-semibold break-all"
                            >
                                Invoice {{ history.financial.invoiceNumber }}
                            </h2>
                            <span
                                class="text-xs text-muted-foreground capitalize"
                                >{{ history.financial.status }}</span
                            >
                        </div>
                        <ul
                            v-if="history.financial.lines.length"
                            class="mt-2 divide-y text-sm"
                        >
                            <li
                                v-for="(line, index) in history.financial.lines"
                                :key="index"
                                class="flex justify-between gap-2 py-2 max-sm:flex-col max-sm:gap-0.5 sm:items-baseline"
                            >
                                <span class="min-w-0 break-words">
                                    {{ line.name }}
                                    <span
                                        class="text-xs whitespace-nowrap text-muted-foreground"
                                        >{{ line.quantity }} ×
                                        {{ myr(line.unitPriceSen) }}</span
                                    >
                                </span>
                                <span class="whitespace-nowrap tabular-nums">{{
                                    myr(line.totalSen)
                                }}</span>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-muted-foreground">
                            Totals only for your access.
                        </p>
                        <dl
                            class="mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-sm"
                        >
                            <template
                                v-for="[label, amount] in totals"
                                :key="label"
                            >
                                <dt class="text-muted-foreground">
                                    {{ label }}
                                </dt>
                                <dd class="whitespace-nowrap tabular-nums">
                                    {{ myr(amount) }}
                                </dd>
                            </template>
                            <dt class="font-semibold">Due now</dt>
                            <dd
                                class="font-semibold whitespace-nowrap tabular-nums"
                            >
                                {{ myr(history.financial.state.due_now) }}
                            </dd>
                        </dl>
                        <div
                            v-if="history.financial.payments.length"
                            class="mt-3 border-t pt-2 text-sm"
                        >
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                            >
                                Payments
                            </p>
                            <ul class="grid gap-1">
                                <li
                                    v-for="payment in history.financial
                                        .payments"
                                    :key="payment.receiptNumber"
                                    class="flex justify-between gap-2"
                                >
                                    <span class="min-w-0 break-words"
                                        >{{ payment.method }} ·
                                        {{ payment.receiptNumber }}</span
                                    >
                                    <span
                                        class="whitespace-nowrap tabular-nums"
                                        >{{ myr(payment.amountSen) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>
                    <section
                        v-else
                        :class="[card, 'text-sm text-muted-foreground']"
                    >
                        {{
                            history.canSeeFinancial
                                ? 'No invoice was issued for this visit.'
                                : 'Billing details are not shown for your role.'
                        }}
                    </section>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
