<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Eye, Printer, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import OperationalTabs from '@/components/ui/tabs/OperationalTabs.vue';

/**
 * DSP-27 (owner, 25 Nov 2026, Clean Look gap list B1 and B2): the branch's Dispensary list in three status tabs with
 * counts. Read only: each row opens its case, where the work happens. Done today offers the label reprint.
 */
type Tab = 'prepare' | 'check' | 'done';
type Row = {
    publicId: string;
    queueNumber: string | null;
    patientName: string;
    patientNumber: string;
    visitNumber: string;
    caseType: 'consultation' | 'otc';
    status: string;
    doctorName: string | null;
    medicineCount: number;
    receivedAt: string;
    waitingMinutes: number | null;
    completedAt: string | null;
    caseUrl: string;
    labelUrl: string | null;
    labelAction: 'reprint' | 'view' | null;
};

const props = defineProps<{
    list: {
        branchName: string;
        tab: Tab;
        search: string;
        counts: Record<Tab, number>;
        canPrintLabels: boolean;
        rows: Row[];
        total: number;
        page: number;
        lastPage: number;
    };
}>();

const tabs = computed(() => [
    { value: 'prepare', label: 'To prepare', count: props.list.counts.prepare },
    {
        value: 'check',
        label: "Waiting for doctor's check",
        count: props.list.counts.check,
    },
    { value: 'done', label: 'Done today', count: props.list.counts.done },
]);
const search = ref(props.list.search);

const visit = (params: { tab?: Tab; page?: number; search?: string }) =>
    router.get(
        '/dispensary',
        {
            tab: params.tab ?? props.list.tab,
            page: params.page ?? 1,
            search: (params.search ?? props.list.search) || undefined,
        },
        { preserveScroll: params.page === undefined, preserveState: true },
    );
const selectTab = (value: string) => visit({ tab: value as Tab });
const submitSearch = () => visit({ search: search.value.trim() });

const medicines = (n: number) => (n === 1 ? '1 medicine' : `${n} medicines`);
const from = (row: Row) =>
    row.caseType === 'otc' ? 'Medicine only' : (row.doctorName ?? 'Doctor');
const waiting = (row: Row) => {
    const minutes = row.waitingMinutes;

    if (minutes === null) {
        return '';
    }

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `Waiting ${minutes} min`;
    }

    if (minutes < 24 * 60) {
        return `Waiting ${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    const days = Math.floor(minutes / (24 * 60));

    return `Waiting ${days} ${days === 1 ? 'day' : 'days'}`;
};
const emptyText = computed(
    () =>
        ({
            prepare: 'No case is waiting to be prepared.',
            check: "No case is waiting for the doctor's check.",
            done: 'No case has been completed today.',
        })[props.list.tab],
);

// The list refreshes itself every 15 seconds while the tab is visible, as the Registration board does.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            router.reload({ only: ['list'] });
        }
    }, 15000);
});
onBeforeUnmount(() => timer && clearInterval(timer));
</script>

<template>
    <Head title="Dispensary" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 px-4 py-6 md:px-6"
    >
        <PageHeader title="Dispensary" :description="list.branchName" />

        <OperationalTabs
            :model-value="list.tab"
            label="Dispensary cases by status"
            :tabs="tabs"
            @update:model-value="selectTab"
        />

        <form
            class="flex max-w-md gap-2"
            role="search"
            @submit.prevent="submitSearch"
        >
            <label for="dispensary-search" class="sr-only"
                >Search patient name, KP number or visit number</label
            >
            <Input
                id="dispensary-search"
                v-model="search"
                type="search"
                placeholder="Name, KP or visit no."
                class="max-lg:min-h-11"
            />
            <Button type="submit" variant="outline" class="max-lg:min-h-11">
                <Search class="size-4" aria-hidden="true" />Search
            </Button>
        </form>

        <p
            v-if="list.tab === 'check'"
            class="text-xs text-muted-foreground"
            data-testid="dispensary-check-note"
        >
            The doctor checks these on the consultation page. Do not hand over
            the medicine until the doctor has checked it.
        </p>

        <EmptyState
            v-if="list.rows.length === 0"
            :title="list.search ? 'No match' : 'Nothing here'"
            :description="
                list.search
                    ? `No case matches “${list.search}” in this tab.`
                    : emptyText
            "
        />

        <template v-else>
            <div class="hidden md:block">
                <OperationalTable
                    label="Dispensary cases"
                    :columns="6"
                    min-width="820px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">No.</th>
                            <th scope="col">Patient</th>
                            <th scope="col">Items</th>
                            <th scope="col">From</th>
                            <th scope="col" class="text-right">
                                {{
                                    list.tab === 'done'
                                        ? 'Completed'
                                        : 'Received'
                                }}
                            </th>
                            <th scope="col" class="text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="row in list.rows"
                            :key="row.publicId"
                            data-testid="dispensary-list-row"
                        >
                            <td class="font-mono whitespace-nowrap">
                                {{ row.queueNumber ?? '—' }}
                            </td>
                            <td>
                                <span
                                    class="block font-medium text-foreground"
                                    >{{ row.patientName }}</span
                                >
                                <span
                                    class="block font-mono text-xs text-muted-foreground"
                                    >{{ row.patientNumber }} ·
                                    {{ row.visitNumber }}</span
                                >
                            </td>
                            <td class="whitespace-nowrap">
                                {{ medicines(row.medicineCount) }}
                            </td>
                            <td>{{ from(row) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <span class="block tabular-nums">{{
                                    row.completedAt ?? row.receivedAt
                                }}</span>
                                <span
                                    v-if="row.waitingMinutes !== null"
                                    class="block text-xs text-muted-foreground"
                                    >{{ waiting(row) }}</span
                                >
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        v-if="row.labelUrl"
                                        as-child
                                        variant="outline"
                                        size="sm"
                                        class="max-lg:min-h-11"
                                        ><a
                                            :href="row.labelUrl"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            data-testid="dispensary-list-labels"
                                            :aria-label="`${row.labelAction === 'reprint' ? 'Reprint labels' : 'View labels'} for ${row.patientName}, ${row.visitNumber} (opens in a new tab)`"
                                        >
                                            <Printer
                                                v-if="
                                                    row.labelAction ===
                                                    'reprint'
                                                "
                                                class="size-4"
                                                aria-hidden="true"
                                            /><Eye
                                                v-else
                                                class="size-4"
                                                aria-hidden="true"
                                            />{{
                                                row.labelAction === 'reprint'
                                                    ? 'Reprint labels'
                                                    : 'View labels'
                                            }}</a
                                        ></Button
                                    >
                                    <ActionLink
                                        :href="row.caseUrl"
                                        variant="outline"
                                        size="sm"
                                        class="max-lg:min-h-11"
                                        :aria-label="`Open case for ${row.patientName}, ${row.visitNumber}`"
                                        >Open case</ActionLink
                                    >
                                </div>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </div>

            <ul
                class="flex flex-col gap-3 md:hidden"
                aria-label="Dispensary cases"
            >
                <li
                    v-for="row in list.rows"
                    :key="row.publicId"
                    class="rounded-xl border bg-card p-4 text-sm"
                    data-testid="dispensary-list-card"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-foreground">
                                <span class="mr-2 font-mono">{{
                                    row.queueNumber ?? '—'
                                }}</span
                                >{{ row.patientName }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ row.patientNumber }} · {{ row.visitNumber }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{
                            row.completedAt ?? waiting(row)
                        }}</span>
                    </div>
                    <p class="mt-2 text-muted-foreground">
                        {{ medicines(row.medicineCount) }} · {{ from(row) }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <ActionLink
                            :href="row.caseUrl"
                            variant="outline"
                            size="sm"
                            class="min-h-11"
                            :aria-label="`Open case for ${row.patientName}, ${row.visitNumber}`"
                            >Open case</ActionLink
                        >
                        <Button
                            v-if="row.labelUrl"
                            as-child
                            variant="outline"
                            size="sm"
                            class="min-h-11"
                            ><a
                                :href="row.labelUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{
                                    row.labelAction === 'reprint'
                                        ? 'Reprint labels'
                                        : 'View labels'
                                }}<span class="sr-only">
                                    (opens in a new tab)</span
                                ></a
                            ></Button
                        >
                    </div>
                </li>
            </ul>

            <CompactPagination
                :current-page="list.page"
                :last-page="list.lastPage"
                :total="list.total"
                @change="(page: number) => visit({ page })"
            />
        </template>
    </main>
</template>
