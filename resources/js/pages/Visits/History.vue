<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { myr } from '@/types/billing';
import type { VisitHistoryPage } from '@/types/visitHistory';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});

const props = defineProps<{ history: VisitHistoryPage }>();

const tab = ref('all');
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
              ['Approved pay later', state.deferred],
          ] as Array<[string, number]>)
        : [];
});
</script>

<template>
    <Head :title="`Visit ${history.visit.visitNumber}`" />
    <main class="flex flex-1 flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-center gap-3">
            <ActionLink :href="history.links.registration">
                <ArrowLeft class="size-3.5" />
                Registration
            </ActionLink>
            <h1 class="text-xl font-semibold tracking-tight">
                Invoice · Visit {{ history.visit.visitNumber }}
            </h1>
            <StatusBadge
                status="completed"
                label="Completed · read only"
                tone="neutral"
            />
            <div class="ml-auto flex flex-wrap gap-2 text-sm">
                <ActionLink
                    v-if="history.links.consultation"
                    :href="history.links.consultation"
                    >Consultation notes</ActionLink
                >
                <ActionLink
                    v-if="history.links.billing"
                    :href="history.links.billing"
                    >Open billing</ActionLink
                >
            </div>
        </header>

        <div
            class="grid gap-4 lg:grid-cols-[minmax(0,280px)_minmax(0,1fr)_minmax(0,300px)]"
        >
            <aside class="flex flex-col gap-4" data-testid="history-patient">
                <section class="rounded-xl border bg-card p-4 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold break-words">
                                {{ history.patient.name }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ history.patient.patientNumber }}
                                <template v-if="history.patient.age">
                                    · {{ history.patient.age }}
                                </template>
                                <template v-if="history.patient.sex">
                                    · {{ history.patient.sex }}
                                </template>
                            </div>
                        </div>
                        <ActionLink
                            v-if="history.patient.profileUrl"
                            :href="history.patient.profileUrl"
                            >View profile</ActionLink
                        >
                    </div>
                </section>
                <section class="rounded-xl border bg-card p-4 text-sm">
                    <h2
                        class="mb-2 text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Visit
                    </h2>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                        <dt class="text-muted-foreground">Branch</dt>
                        <dd>{{ history.visit.branch }}</dd>
                        <dt class="text-muted-foreground">Type</dt>
                        <dd class="capitalize">{{ history.visit.type }}</dd>
                        <dt class="text-muted-foreground">Doctor</dt>
                        <dd>{{ text(history.visit.doctor) }}</dd>
                        <dt class="text-muted-foreground">Coverage</dt>
                        <dd>{{ history.visit.coverage }}</dd>
                        <dt class="text-muted-foreground">Reason</dt>
                        <dd>{{ text(history.visit.reason) }}</dd>
                        <dt class="text-muted-foreground">Registered</dt>
                        <dd>{{ history.visit.registeredAt }}</dd>
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd>{{ text(history.visit.completedAt) }}</dd>
                    </dl>
                </section>
            </aside>

            <section class="min-w-0 rounded-xl border bg-card p-4">
                <OperationalTabs
                    v-model="tab"
                    :tabs="tabs"
                    label="Visit history content"
                    class="mb-3"
                />

                <div
                    v-if="show('consultation')"
                    data-testid="history-consultation"
                    class="mb-5"
                >
                    <h2 class="mb-2 text-sm font-semibold">Consultation</h2>
                    <p
                        v-if="!history.consultation"
                        class="text-sm text-muted-foreground"
                    >
                        No consultation record for this visit.
                    </p>
                    <div v-else class="grid gap-3 text-sm">
                        <div class="text-xs text-muted-foreground">
                            {{ text(history.consultation.doctor) }} ·
                            {{ history.consultation.startedAt }}
                        </div>
                        <dl
                            class="grid grid-cols-2 gap-2 rounded-lg bg-muted/40 p-3 sm:grid-cols-4"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Blood pressure
                                </dt>
                                <dd>
                                    {{
                                        history.consultation.vitals
                                            .systolicBp === null
                                            ? 'Not recorded'
                                            : `${history.consultation.vitals.systolicBp}/${history.consultation.vitals.diastolicBp ?? '–'} mmHg`
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Pulse
                                </dt>
                                <dd>
                                    {{
                                        text(
                                            history.consultation.vitals
                                                .pulseBpm,
                                            ' bpm',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Temperature
                                </dt>
                                <dd>
                                    {{
                                        text(
                                            history.consultation.vitals
                                                .temperatureCelsius,
                                            ' °C',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    SpO₂
                                </dt>
                                <dd>
                                    {{
                                        text(
                                            history.consultation.vitals
                                                .spo2Percent,
                                            '%',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Weight
                                </dt>
                                <dd>
                                    {{
                                        text(
                                            history.consultation.vitals
                                                .weightKg,
                                            ' kg',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Height
                                </dt>
                                <dd>
                                    {{
                                        text(
                                            history.consultation.vitals
                                                .heightCm,
                                            ' cm',
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    BMI
                                </dt>
                                <dd>
                                    {{ text(history.consultation.vitals.bmi) }}
                                </dd>
                            </div>
                        </dl>
                        <div>
                            <div
                                class="text-xs font-semibold text-muted-foreground uppercase"
                            >
                                Diagnoses
                            </div>
                            <p
                                v-if="!history.consultation.diagnoses.length"
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
                                    <span
                                        v-if="diagnosis.isPrimary"
                                        class="ml-1 text-xs font-medium text-emerald-700"
                                        >Primary</span
                                    >
                                </li>
                            </ul>
                        </div>
                        <div>
                            <div
                                class="text-xs font-semibold text-muted-foreground uppercase"
                            >
                                Clinical note
                            </div>
                            <p class="whitespace-pre-wrap">
                                {{ text(history.consultation.note) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="show('items')"
                    data-testid="history-items"
                    class="mb-5"
                >
                    <h2 class="mb-2 text-sm font-semibold">Items</h2>
                    <p
                        v-if="!history.medicines.length"
                        class="text-sm text-muted-foreground"
                    >
                        No medicines were ordered or dispensed.
                    </p>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="(medicine, index) in history.medicines"
                            :key="index"
                            class="grid gap-1 p-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-2"
                            >
                                <span class="font-medium">
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
                                    class="rounded bg-pink-100 px-1.5 py-0.5 text-xs text-pink-900"
                                    data-testid="change-label"
                                    >{{
                                        changeLabel(
                                            medicine.changeState,
                                            medicine.source,
                                        )
                                    }}</span
                                >
                            </div>
                            <div
                                class="grid gap-2 sm:grid-cols-2"
                                data-testid="ordered-vs-dispensed"
                            >
                                <div
                                    class="rounded-md bg-muted/40 p-2 text-xs"
                                    data-testid="ordered-side"
                                >
                                    <div class="font-medium">Ordered</div>
                                    <template
                                        v-if="medicine.quantityOrdered !== null"
                                    >
                                        <div>
                                            {{ medicine.quantityOrdered }}
                                            {{ medicine.unit }}
                                        </div>
                                        <div class="text-muted-foreground">
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
                                    <div v-else class="text-muted-foreground">
                                        Not ordered by a doctor
                                    </div>
                                </div>
                                <div
                                    class="rounded-md bg-muted/40 p-2 text-xs"
                                    data-testid="dispensed-side"
                                >
                                    <div class="font-medium">Dispensed</div>
                                    <template v-if="medicine.dispensed">
                                        <div>
                                            {{
                                                text(medicine.dispensedQuantity)
                                            }}
                                            {{ medicine.unit }} ({{
                                                statusLabel(
                                                    medicine.dispensedStatus,
                                                )
                                            }})
                                        </div>
                                        <div class="text-muted-foreground">
                                            {{ dispensedLine(medicine) }}
                                        </div>
                                        <div
                                            v-if="
                                                medicine.dispensed.instruction
                                            "
                                            class="text-muted-foreground"
                                        >
                                            {{ medicine.dispensed.instruction }}
                                        </div>
                                        <div
                                            v-if="medicine.dispensed.precaution"
                                            class="text-muted-foreground"
                                        >
                                            Precaution:
                                            {{ medicine.dispensed.precaution }}
                                        </div>
                                    </template>
                                    <div v-else class="text-muted-foreground">
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
                    class="mb-5"
                >
                    <h2 class="mb-2 text-sm font-semibold">Services</h2>
                    <p
                        v-if="!history.services.length"
                        class="text-sm text-muted-foreground"
                    >
                        No services were ordered or performed.
                    </p>
                    <ul v-else class="divide-y rounded-lg border">
                        <li
                            v-for="(service, index) in history.services"
                            :key="index"
                            class="grid gap-1 p-3 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-2"
                            >
                                <span class="font-medium">{{
                                    service.name
                                }}</span>
                                <span
                                    v-if="
                                        changeLabel(
                                            service.changeState,
                                            service.source,
                                        )
                                    "
                                    class="rounded bg-pink-100 px-1.5 py-0.5 text-xs text-pink-900"
                                    data-testid="service-change-label"
                                    >{{
                                        changeLabel(
                                            service.changeState,
                                            service.source,
                                        )
                                    }}</span
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                <template
                                    v-if="service.quantityOrdered !== null"
                                    >Ordered {{ service.quantityOrdered }}
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

                <div v-if="show('documents')" data-testid="history-documents">
                    <h2 class="mb-2 text-sm font-semibold">Documents</h2>
                    <p
                        v-if="
                            !history.financial?.printUrl &&
                            !history.financial?.payments.some((p) => p.printUrl)
                        "
                        class="text-sm text-muted-foreground"
                    >
                        No printable documents for your access.
                    </p>
                    <ul v-else class="grid gap-1 text-sm">
                        <li v-if="history.financial?.printUrl">
                            <ActionLink :href="history.financial.printUrl"
                                >Invoice
                                {{
                                    history.financial.invoiceNumber
                                }}</ActionLink
                            >
                        </li>
                        <li
                            v-for="payment in history.financial?.payments ?? []"
                            :key="payment.receiptNumber"
                        >
                            <ActionLink
                                v-if="payment.printUrl"
                                :href="payment.printUrl"
                                >Receipt {{ payment.receiptNumber }}</ActionLink
                            >
                        </li>
                    </ul>
                </div>
            </section>

            <aside class="flex flex-col gap-4" data-testid="history-financial">
                <section
                    v-if="history.financial"
                    class="rounded-xl border bg-card p-4 text-sm"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            class="text-xs font-semibold text-muted-foreground uppercase"
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
                        class="mt-2 divide-y"
                    >
                        <li
                            v-for="(line, index) in history.financial.lines"
                            :key="index"
                            class="flex items-baseline justify-between gap-2 py-1.5"
                        >
                            <span class="min-w-0 break-words">
                                {{ line.name }}
                                <span class="text-xs text-muted-foreground"
                                    >{{ line.quantity }} ×
                                    {{ myr(line.unitPriceSen) }}</span
                                >
                            </span>
                            <span class="tabular-nums">{{
                                myr(line.totalSen)
                            }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-xs text-muted-foreground">
                        Totals only for your access.
                    </p>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1">
                        <template
                            v-for="[label, amount] in totals"
                            :key="label"
                        >
                            <dt class="text-muted-foreground">{{ label }}</dt>
                            <dd class="tabular-nums">{{ myr(amount) }}</dd>
                        </template>
                        <dt class="font-semibold">Due now</dt>
                        <dd class="font-semibold tabular-nums">
                            {{ myr(history.financial.state.due_now) }}
                        </dd>
                    </dl>
                    <div
                        v-if="history.financial.payments.length"
                        class="mt-3 border-t pt-2"
                    >
                        <div
                            class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                        >
                            Payments
                        </div>
                        <ul class="grid gap-1">
                            <li
                                v-for="payment in history.financial.payments"
                                :key="payment.receiptNumber"
                                class="flex justify-between gap-2"
                            >
                                <span
                                    >{{ payment.method }} ·
                                    {{ payment.receiptNumber }}</span
                                >
                                <span class="tabular-nums">{{
                                    myr(payment.amountSen)
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </section>
                <section
                    v-else
                    class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                >
                    {{
                        history.canSeeFinancial
                            ? 'No invoice was issued for this visit.'
                            : 'Billing details are not shown for your role.'
                    }}
                </section>
            </aside>
        </div>
    </main>
</template>
