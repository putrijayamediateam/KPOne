<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { myr } from '@/types/billing';

type PageSet<T> = {
    data: T[];
    total: number;
    currentPage: number;
    lastPage: number;
};

const props = defineProps<{
    work: {
        branch: { id: number; name: string };
        eligible: PageSet<{
            allocationId: string;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            panelName: string;
            amountSen: number;
            invoiceTotalSen: number;
        }>;
        claims: PageSet<{
            publicId: string;
            patientName: string;
            visitNumber: string;
            invoiceNumber: string;
            panelName: string;
            claimAmountSen: number;
            status: string;
            createdAt: string | null;
            showUrl: string;
        }>;
    };
}>();

const error = ref('');
const prepare = (allocationId: string) => {
    error.value = '';
    router.post(
        `/panel-claims/allocations/${encodeURIComponent(allocationId)}/prepare`,
        { expected_branch_id: props.work.branch.id },
        {
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The claim pack could not be prepared.';
            },
        },
    );
};
const turnPage = (key: 'allocations_page' | 'claims_page', value: number) =>
    router.get('/panel-claims', { [key]: value }, { preserveState: true });
</script>

<template>
    <Head title="Panel Claims" />
    <main class="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6">
        <header
            class="flex flex-wrap items-start justify-between gap-4 border-b pb-4"
        >
            <div>
                <p class="text-sm text-muted-foreground">Panel Department</p>
                <h1 class="text-2xl font-semibold text-foreground">
                    Panel Claims
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ work.branch.name }} · Prepare claim packs for manual
                    submission and track Panel decisions and remittances.
                </p>
            </div>
            <ActionLink href="/workspace" variant="secondary">
                Return to Workspace
            </ActionLink>
        </header>

        <p
            class="rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground"
        >
            This workspace creates one standard claim summary for use in the
            Panel’s portal. Submission is manual. It includes invoice lines,
            patient name, member reference, visit date, branch and invoice
            number; it does not include clinical notes, diagnoses, identity
            documents or attachments.
        </p>

        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Ready to prepare</h2>
                <p class="text-sm text-muted-foreground">
                    Current approved Panel allocations without a claim pack.
                </p>
            </div>
            <EmptyState
                v-if="work.eligible.data.length === 0"
                title="No approved allocations to prepare"
                description="Approved Panel allocations will appear here when they are available in this branch."
            />
            <OperationalTable
                v-else
                label="Approved Panel allocations ready to prepare"
                :columns="6"
                min-width="900px"
            >
                <template #head>
                    <tr>
                        <th scope="col">Patient / Visit</th>
                        <th scope="col">Invoice</th>
                        <th scope="col">Panel</th>
                        <th scope="col" class="text-right">Approved</th>
                        <th scope="col" class="text-right">Invoice total</th>
                        <th scope="col" class="text-right">Action</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="row in work.eligible.data"
                        :key="row.allocationId"
                    >
                        <td>
                            <span class="block font-medium text-foreground">
                                {{ row.patientName }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ row.visitNumber }}
                            </span>
                        </td>
                        <td class="font-mono text-xs">
                            {{ row.invoiceNumber }}
                        </td>
                        <td>{{ row.panelName }}</td>
                        <td class="text-right tabular-nums">
                            {{ myr(row.amountSen) }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{ myr(row.invoiceTotalSen) }}
                        </td>
                        <td class="text-right">
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="prepare(row.allocationId)"
                            >
                                Prepare claim
                            </Button>
                        </td>
                    </tr>
                </template>
            </OperationalTable>
            <CompactPagination
                v-if="work.eligible.total > 0"
                :current-page="work.eligible.currentPage"
                :last-page="work.eligible.lastPage"
                :total="work.eligible.total"
                @change="(value) => turnPage('allocations_page', value)"
            />
        </section>

        <section class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold">Claims in progress</h2>
                <p class="text-sm text-muted-foreground">
                    Prepared packs, submissions, Panel responses and recorded
                    remittances.
                </p>
            </div>
            <EmptyState
                v-if="work.claims.data.length === 0"
                title="No claim packs yet"
                description="Prepare a claim pack from an approved allocation to begin tracking it."
            />
            <OperationalTable
                v-else
                label="Panel claims in progress"
                :columns="7"
                min-width="1000px"
            >
                <template #head>
                    <tr>
                        <th scope="col">Patient / Visit</th>
                        <th scope="col">Invoice</th>
                        <th scope="col">Panel</th>
                        <th scope="col" class="text-right">Claim amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">Prepared</th>
                        <th scope="col" class="text-right">Action</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="row in work.claims.data" :key="row.publicId">
                        <td>
                            <span class="block font-medium text-foreground">
                                {{ row.patientName }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ row.visitNumber }}
                            </span>
                        </td>
                        <td class="font-mono text-xs">
                            {{ row.invoiceNumber }}
                        </td>
                        <td>{{ row.panelName }}</td>
                        <td class="text-right tabular-nums">
                            {{ myr(row.claimAmountSen) }}
                        </td>
                        <td><StatusBadge :status="row.status" /></td>
                        <td class="whitespace-nowrap">{{ row.createdAt }}</td>
                        <td class="text-right">
                            <ActionLink
                                :href="row.showUrl"
                                variant="secondary"
                                size="sm"
                            >
                                View
                            </ActionLink>
                        </td>
                    </tr>
                </template>
            </OperationalTable>
            <CompactPagination
                v-if="work.claims.total > 0"
                :current-page="work.claims.currentPage"
                :last-page="work.claims.lastPage"
                :total="work.claims.total"
                @change="(value) => turnPage('claims_page', value)"
            />
        </section>
    </main>
</template>
