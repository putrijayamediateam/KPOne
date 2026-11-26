<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import StockBatchHoldList from '@/components/inventory/StockBatchHoldList.vue';
import { ActionLink } from '@/components/ui/action-link';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PageHeader } from '@/components/ui/page-header';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate } from '@/lib/presentation';
type Row = {
    public_id: string;
    batch_number: string;
    expiry_date: string;
    sku_code: string;
    status: string;
    reason: string | null;
    lock_version: number;
    canPlace: boolean;
    canRelease: boolean;
};
const props = defineProps<{
    holds: {
        branchId: number;
        branchName: string;
        q: string;
        rows: Row[];
        page: number;
        lastPage: number;
        total: number;
    };
}>();
const holdsList = ref<{ restoreFocus: (event: Event) => void } | null>(null);
const query = ref(props.holds.q);
const open = ref(false);
const selected = ref<Row | null>(null);
const action = ref<'place' | 'release'>('place');
const page = usePage();
const form = useForm({
    expected_branch_id: props.holds.branchId,
    lock_version: 0,
    retry_key: '',
    reason: '',
});
const placeReasons = [
    'suspected_quality_issue',
    'recall',
    'damaged',
    'other_safety',
];
const releaseReasons = [
    'cleared_by_supplier',
    'inspection_passed',
    'other_approved',
];
const label = (value: string) => value.replaceAll('_', ' ');
const show = (row: Row, next: 'place' | 'release') => {
    selected.value = row;
    action.value = next;
    form.clearErrors();
    form.expected_branch_id = props.holds.branchId;
    form.lock_version = row.lock_version;
    form.retry_key = crypto.randomUUID();
    form.reason = '';
    open.value = true;
};
const search = (number = 1) =>
    router.get('/inventory/batch-holds', { q: query.value, page: number });
const submit = () => {
    if (!selected.value) {
        return;
    }

    form.post(
        `/inventory/batch-holds/${selected.value.public_id}/${action.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                selected.value = null;
            },
        },
    );
};
watch(
    () => page.props.branchContext?.active?.id,
    () => {
        open.value = false;
        selected.value = null;
        query.value = '';
        form.reset();
    },
);
</script>

<template>
    <Head title="Batch safety holds" />
    <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-7">
        <ActionLink
            href="/inventory"
            variant="ghost"
            class="-ml-3 min-h-11 self-start"
            ><ArrowLeft class="size-4" aria-hidden="true" />
            Inventory</ActionLink
        >
        <PageHeader
            title="Batch safety holds"
            :description="`${holds.branchName} · A hold applies to this batch at this branch only. Multi-branch recalls need one hold per branch.`"
        />
        <form
            class="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card p-4"
            @submit.prevent="search()"
        >
            <label class="min-w-[min(100%,16rem)] flex-1"
                >Search SKU or batch<input
                    v-model="query"
                    maxlength="100"
                    class="mt-1 h-11 w-full rounded-md border bg-background px-3"
            /></label>
            <button class="min-h-11 rounded-md border px-4 lg:min-h-9">
                Search
            </button>
        </form>
        <p class="text-sm text-muted-foreground">
            {{ holds.total }} batches · 25 per page. Search includes batches
            beyond this page.
        </p>
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_17rem]">
            <div class="min-w-0 space-y-4">
                <StockBatchHoldList
                    ref="holdsList"
                    :rows="holds.rows"
                    :branch-id="holds.branchId"
                    :active-batch-id="selected?.public_id ?? null"
                    :busy="form.processing"
                >
                    <template #expiry="{ row }"
                        ><span class="whitespace-nowrap">{{
                            formatDate(row.expiry_date)
                        }}</span></template
                    >
                    <template #status="{ row }">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Batch status
                            </p>
                            <p class="mt-1 text-sm">{{ label(row.status) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Branch hold
                            </p>
                            <p
                                v-if="row.reason"
                                class="mt-1 text-sm font-medium text-warning-strong"
                            >
                                Held: {{ formatInventoryReason(row.reason) }}
                            </p>
                            <p v-else class="mt-1 text-sm">
                                No active branch hold
                            </p>
                        </div>
                    </template>
                    <template #actions="{ row }">
                        <button
                            v-if="row.canPlace"
                            class="min-h-11 rounded-md border px-4"
                            @click="show(row, 'place')"
                        >
                            Place safety hold
                        </button>
                        <button
                            v-if="row.canRelease"
                            class="min-h-11 rounded-md border px-4"
                            @click="show(row, 'release')"
                        >
                            Release hold
                        </button>
                        <p
                            v-else-if="row.reason"
                            class="text-sm text-muted-foreground"
                        >
                            A different authorised checker is required for
                            release.
                        </p>
                    </template>
                </StockBatchHoldList>
                <nav
                    aria-label="Batch hold pages"
                    class="flex items-center gap-3"
                >
                    <button
                        class="min-h-11 rounded-md border px-4"
                        :disabled="holds.page <= 1"
                        @click="search(holds.page - 1)"
                    >
                        Previous</button
                    ><span>{{ holds.page }} / {{ holds.lastPage }}</span
                    ><button
                        class="min-h-11 rounded-md border px-4"
                        :disabled="holds.page >= holds.lastPage"
                        @click="search(holds.page + 1)"
                    >
                        Next
                    </button>
                </nav>
            </div>
            <aside
                class="space-y-3 rounded-2xl border border-border bg-muted/30 p-4"
                aria-label="About batch safety holds"
            >
                <h2 class="text-sm font-medium">About these holds</h2>
                <p class="text-sm">Current branch: {{ holds.branchName }}</p>
                <p class="text-sm text-muted-foreground">
                    Held stock cannot be allocated or dispensed. Placing or
                    releasing a hold changes no quantity and creates no
                    movement. A different authorised person must release the
                    hold. No disposal, patient tracing or notifications.
                </p>
                <p class="text-xs text-muted-foreground">
                    Batch status and a hold at this branch are separate records.
                    This list does not show quantities or change stock levels.
                </p>
            </aside>
        </div>
        <Dialog v-model:open="open"
            ><DialogContent
                class="max-h-[90dvh] overflow-y-auto"
                @close-auto-focus="holdsList?.restoreFocus($event)"
                ><DialogHeader
                    ><DialogTitle>{{
                        action === 'place'
                            ? 'Place safety hold'
                            : 'Release hold'
                    }}</DialogTitle
                    ><DialogDescription
                        >{{ selected?.sku_code }} ·
                        {{ selected?.batch_number }} · {{ holds.branchName }}.
                        No stock quantity will change.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="submit">
                    <label class="grid gap-2"
                        >Controlled reason<select
                            v-model="form.reason"
                            required
                            class="h-11 w-full rounded-md border bg-background px-3"
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option
                                v-for="reason in action === 'place'
                                    ? placeReasons
                                    : releaseReasons"
                                :key="reason"
                                :value="reason"
                            >
                                {{ formatInventoryReason(reason) }}
                            </option>
                        </select></label
                    ><InputError
                        v-for="(error, key) in form.errors"
                        :key="key"
                        :message="error"
                    />
                    <div class="flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            class="min-h-11 rounded-md border px-4"
                            @click="open = false"
                        >
                            Cancel</button
                        ><button
                            class="min-h-11 rounded-md bg-primary px-4 text-primary-foreground"
                            :disabled="form.processing"
                        >
                            {{
                                action === 'place'
                                    ? 'Place hold'
                                    : 'Release hold'
                            }}
                        </button>
                    </div>
                </form></DialogContent
            ></Dialog
        >
    </main>
</template>
