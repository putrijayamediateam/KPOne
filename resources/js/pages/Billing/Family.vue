<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { PageHeader } from '@/components/ui/page-header';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import type { FamilyBillPage } from '@/types/billing';
import { myr } from '@/types/billing';

// BIL-39: the family bill. Read only here: each member keeps their own invoice, coverage and receipts, and every
// money action other than the family payment stays on the member's own Billing page.
const props = defineProps<{ family: FamilyBillPage }>();

const names = computed(() =>
    props.family.members.map((member) => member.patientName).join(' and '),
);
const anchorBillingUrl = computed(
    () =>
        props.family.members.find(
            (member) => member.visitNumber === props.family.anchorVisitNumber,
        )?.billingUrl ??
        `/visits/${encodeURIComponent(props.family.anchorVisitNumber)}/billing`,
);
</script>

<template>
    <Head title="Family bill" />
    <main
        class="mx-auto w-full max-w-6xl space-y-4 px-4 py-6 md:px-6 lg:px-8"
        data-testid="family-bill"
    >
        <PageHeader
            title="Family bill"
            :description="`${family.branch} · ${names}`"
        >
            <template #actions>
                <ActionLink
                    :href="anchorBillingUrl"
                    variant="outline"
                    data-testid="family-bill-back"
                    ><ArrowLeft class="size-3.5" aria-hidden="true" />Back to
                    billing</ActionLink
                >
            </template>
        </PageHeader>

        <p
            class="rounded-xl border bg-card-quiet px-4 py-3 text-sm text-muted-foreground"
        >
            Each person keeps their own invoice and receipt. Panel shares, pay
            later, adjustments and printing stay on each person's billing page.
        </p>

        <EmptyState
            v-if="family.members.length === 0"
            title="No open family invoices"
            :description="`No family member has a finalised invoice waiting at ${family.branch}.`"
        />

        <div
            v-else
            class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]"
        >
            <div class="grid min-w-0 gap-4" data-testid="family-bill-members">
                <section
                    v-for="member in family.members"
                    :key="member.visitNumber"
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    :aria-label="`${member.patientName}, ${member.invoice.number ?? 'invoice'}`"
                    data-testid="family-bill-member"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[15px] font-semibold break-words">
                                {{ member.patientName }}
                            </h2>
                            <p class="text-[13px] text-muted-foreground">
                                {{ member.patientNumber }} ·
                                {{ member.invoice.number }} ·
                                {{ member.coverage ?? 'Self-pay' }}
                            </p>
                        </div>
                        <ActionLink :href="member.billingUrl" variant="outline"
                            >Open billing</ActionLink
                        >
                    </div>
                    <p v-if="member.invoice.correctionHold" class="mt-2">
                        <StatusBadge
                            status="review_required"
                            label="Review required"
                            tone="neutral"
                        /><span class="ml-2 text-xs text-muted-foreground"
                            >Not in the family total until the bill is
                            corrected.</span
                        >
                    </p>
                    <ul
                        v-if="family.linesShown && member.invoice.lines.length"
                        class="mt-3 divide-y border-t text-sm"
                        :aria-label="`Items for ${member.patientName}`"
                    >
                        <li
                            v-for="(line, index) in member.invoice.lines"
                            :key="index"
                            class="flex min-h-10 items-center justify-between gap-3 py-2"
                        >
                            <span class="min-w-0 break-words">{{
                                line.name
                            }}</span>
                            <span class="whitespace-nowrap tabular-nums">{{
                                myr(line.totalSen)
                            }}</span>
                        </li>
                    </ul>
                    <dl class="mt-2 grid gap-1 border-t pt-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Invoice total</dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ myr(member.invoice.state.total) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 font-semibold">
                            <dt>Due now</dt>
                            <dd class="whitespace-nowrap tabular-nums">
                                {{ myr(member.invoice.state.due_now) }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <aside
                class="grid min-w-0 gap-4 rounded-xl border bg-card p-4 sm:p-5"
                aria-label="Family total"
                data-testid="family-bill-total"
            >
                <div>
                    <p class="text-[13px] text-muted-foreground">
                        Family due now
                    </p>
                    <p
                        class="text-[34px] leading-tight font-bold tracking-[-0.035em] whitespace-nowrap tabular-nums"
                    >
                        {{ myr(family.totalDueNowSen) }}
                    </p>
                </div>
                <dl class="grid gap-1 border-t pt-2 text-sm">
                    <div
                        v-for="member in family.members"
                        :key="member.visitNumber"
                        class="flex justify-between gap-3"
                    >
                        <dt class="min-w-0 break-words">
                            {{ member.patientName }}
                        </dt>
                        <dd class="whitespace-nowrap tabular-nums">
                            {{
                                member.invoice.correctionHold
                                    ? '–'
                                    : myr(member.invoice.state.due_now)
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="text-xs text-muted-foreground">
                    Oldest invoice first: a family payment pays each invoice in
                    this order until the money runs out.
                </p>
                <p
                    v-if="family.paused"
                    class="rounded-xl border border-warning/25 bg-warning-wash p-3 text-xs text-warning-strong"
                    role="status"
                >
                    Today's cash closing is waiting for approval, so payments
                    are paused.
                </p>
            </aside>
        </div>
    </main>
</template>
