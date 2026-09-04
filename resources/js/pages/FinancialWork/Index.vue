<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    work: {
        mode: 'panel' | 'finance';
        branch: string;
        data: Array<{
            invoiceNumber: string;
            visitNumber: string;
            patientName: string;
            branch: string;
            status: string;
            reviewUrl: string;
            amountSen?: number;
            requestedAt?: string;
            state?: {
                total: number;
                self_pay: number;
                panel: number;
                deferred: number;
                due_now: number;
            };
        }>;
        total: number;
        currentPage: number;
        lastPage: number;
    };
}>();
const panel = computed(() => props.work.mode === 'panel');
const title = computed(() =>
    panel.value ? 'Panel responsibility' : 'Finance / Billing',
);
const money = (sen: number) =>
    new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency: 'MYR',
    }).format(sen / 100);
const turnPage = (page: number) =>
    router.get(panel.value ? '/panel-claims' : '/financial-work', { page });
</script>

<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-7xl space-y-4 p-4">
        <header>
            <h1 class="text-lg font-medium">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ work.branch }} · Current finalized invoices
            </p>
            <p v-if="panel" class="text-sm text-muted-foreground">
                Coverage / Panel responsibility approvals only. Claims
                submission is planned, not available here.
            </p>
        </header>
        <p
            v-if="work.data.length === 0"
            role="status"
            class="text-sm text-muted-foreground"
        >
            No authorized work in this branch.
        </p>
        <div
            v-else
            class="max-w-full overflow-x-auto"
            role="region"
            :aria-label="title"
            tabindex="0"
        >
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    {{
                        title
                    }}
                    for
                    {{
                        work.branch
                    }}
                </caption>
                <thead class="border-b text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="p-2">Patient / Visit</th>
                        <th scope="col" class="p-2">Invoice</th>
                        <th scope="col" class="p-2">Branch</th>
                        <template v-if="panel"
                            ><th scope="col" class="p-2">Panel amount</th>
                            <th scope="col" class="p-2">Requested</th></template
                        >
                        <template v-else
                            ><th scope="col" class="p-2">Total</th>
                            <th scope="col" class="p-2">Received</th>
                            <th scope="col" class="p-2">Panel</th>
                            <th scope="col" class="p-2">Approved pay later</th>
                            <th scope="col" class="p-2">Due now</th></template
                        >
                        <th scope="col" class="p-2">Status</th>
                        <th scope="col" class="p-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in work.data"
                        :key="row.invoiceNumber"
                        class="border-b"
                    >
                        <td class="p-2">
                            {{ row.patientName
                            }}<span
                                class="block text-xs text-muted-foreground"
                                >{{ row.visitNumber }}</span
                            >
                        </td>
                        <td class="p-2">{{ row.invoiceNumber }}</td>
                        <td class="p-2">{{ row.branch }}</td>
                        <template v-if="panel"
                            ><td class="p-2 whitespace-nowrap">
                                {{ money(row.amountSen!) }}
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                {{ row.requestedAt }}
                            </td></template
                        >
                        <template v-else-if="row.state"
                            ><td class="p-2 whitespace-nowrap">
                                {{ money(row.state.total) }}
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                {{ money(row.state.self_pay) }}
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                {{ money(row.state.panel) }}
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                {{ money(row.state.deferred) }}
                            </td>
                            <td class="p-2 whitespace-nowrap">
                                {{ money(row.state.due_now) }}
                            </td></template
                        >
                        <td class="p-2">{{ row.status }}</td>
                        <td class="p-2">
                            <Link
                                :href="row.reviewUrl"
                                :aria-label="`Review ${row.invoiceNumber}`"
                                class="rounded px-2 py-1 underline focus-visible:ring-2 focus-visible:ring-ring"
                                >Review</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav
            aria-label="Work queue pages"
            class="flex items-center gap-3 text-sm"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="work.currentPage <= 1"
                @click="turnPage(work.currentPage - 1)"
                >Previous</Button
            >
            <span
                >{{ work.total }} records · Page {{ work.currentPage }} of
                {{ work.lastPage }}</span
            >
            <Button
                variant="outline"
                size="sm"
                :disabled="work.currentPage >= work.lastPage"
                @click="turnPage(work.currentPage + 1)"
                >Next</Button
            >
        </nav>
    </main>
</template>
