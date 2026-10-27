<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

type Row = {
    id: number;
    public_id: string;
    sku_code: string;
    stock_unit: string;
    purchase_unit: string;
    dispensing_unit: string;
    unit_verification_id: number | null;
    mapped: boolean;
    ambiguous: boolean;
    opening_count: number;
    signed_count: number;
};
type Opening = {
    public_id: string;
    inventory_sku_id: number;
    inventory_batch_id: number;
    batch_number: string;
    expiry_date: string;
    quantity: string;
    location_code: string;
    canVerify: boolean;
};
const props = defineProps<{
    readiness: {
        branchId: number;
        branchName: string;
        rows: Row[];
        q: string;
        page: number;
        lastPage: number;
        total: number;
        pending: Opening[];
        pendingLimited: boolean;
        openingPage: number;
        openingLastPage: number;
        canVerifyUnits: boolean;
    };
}>();
const search = ref(props.readiness.q);
const busy = ref(false);
const retryKeys = ref<Record<string, string>>({});
const page = usePage();
const run = (number = 1) =>
    router.get(
        '/inventory/readiness',
        { q: search.value, page: number },
        { preserveState: false },
    );
const openingPage = (number: number) =>
    router.get(
        '/inventory/readiness',
        { q: search.value, page: props.readiness.page, opening_page: number },
        { preserveState: false },
    );
const verify = (kind: 'unit' | 'opening', key: string, base?: string) => {
    busy.value = true;
    router.post(
        `/inventory/readiness/${kind}/${key}`,
        {
            expected_branch_id: props.readiness.branchId,
            lock_version: 0,
            retry_key: (retryKeys.value[`${kind}/${key}`] ??=
                crypto.randomUUID()),
            ...(base ? { base_unit: base } : {}),
        },
        {
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
watch(
    () => props.readiness.branchId,
    () => {
        search.value = '';
        retryKeys.value = {};
        busy.value = false;
    },
);
</script>
<template>
    <Head title="Inventory pilot readiness" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-5 p-4 md:p-7"
    >
        <div>
            <a href="/inventory" class="text-sm underline">Inventory</a>
            <h1 class="mt-3 text-2xl font-semibold">Pilot readiness</h1>
            <p class="text-sm text-muted-foreground">
                {{ readiness.branchName }} · Review only. No import, balance
                correction or pilot go-live approval.
            </p>
        </div>
        <section class="space-y-2 rounded-lg border bg-card p-4 text-sm">
            <p>
                One base stock unit per SKU. Quantities use exactly up to three
                decimal places. Input → base uses factor 1.000 only. Non-base
                units, unsupported conversions, precision loss and overflow are
                refused; stored purchase/conversion labels are never guessed.
            </p>
            <p>
                Reference managers verify the existing base unit. An independent
                checker must sign each opening; the recorder cannot sign their
                own count. This page never changes a recorded count.
            </p>
            <p>
                Transfers and treatment-room consumption are outside the pilot.
                Responsible clinician, FEFO and regulated-item questions remain
                open.
            </p>
        </section>
        <p
            v-for="(error, key) in page.props.errors"
            :key="key"
            role="alert"
            class="rounded-md border border-destructive p-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <form class="flex min-w-0 flex-wrap gap-2" @submit.prevent="run()">
            <label class="grid min-w-0 flex-1 gap-1 text-sm"
                >Search SKU<input
                    v-model="search"
                    maxlength="100"
                    class="min-h-11 min-w-0 rounded-md border bg-background px-3" /></label
            ><button
                class="min-h-11 self-end rounded-md border px-4"
                type="submit"
            >
                Search
            </button>
        </form>
        <p class="text-sm text-muted-foreground">
            {{ readiness.total }} SKUs · 25 per page. Search includes items
            beyond this page. Mapping problems are flagged, never automatically
            repaired.
        </p>
        <div class="grid min-w-0 gap-4 lg:grid-cols-2">
            <article
                v-for="row in readiness.rows"
                :key="row.id"
                class="min-w-0 space-y-3 rounded-lg border bg-card p-4"
            >
                <h2 class="font-semibold break-words">{{ row.sku_code }}</h2>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>Base stock unit</dt>
                    <dd class="break-words">
                        {{ row.stock_unit || 'Not defined' }}
                    </dd>
                    <dt>Unit verified</dt>
                    <dd>
                        {{ row.unit_verification_id ? 'Yes' : 'Not verified' }}
                    </dd>
                    <dt>Medicine mapping</dt>
                    <dd>
                        {{
                            row.ambiguous
                                ? 'Ambiguous — refused'
                                : row.mapped
                                  ? 'Unambiguous'
                                  : 'Not mapped'
                        }}
                    </dd>
                    <dt>Opening recorded</dt>
                    <dd>{{ row.opening_count }}</dd>
                    <dt>Openings signed off</dt>
                    <dd>{{ row.signed_count }} / {{ row.opening_count }}</dd>
                </dl>
                <p
                    v-if="row.stock_unit !== row.dispensing_unit"
                    class="text-sm text-amber-700 dark:text-amber-300"
                >
                    Dispensing uses a different unit. Conversion is unsupported;
                    review the references before dispensing.
                </p>
                <button
                    v-if="readiness.canVerifyUnits && !row.unit_verification_id"
                    type="button"
                    :disabled="busy"
                    class="min-h-11 rounded-md border px-3 text-sm disabled:opacity-50"
                    @click="verify('unit', row.public_id, row.stock_unit)"
                >
                    Verify base unit: {{ row.stock_unit }}
                </button>
            </article>
        </div>
        <p v-if="readiness.rows.length === 0" class="rounded-lg border p-5">
            No SKUs match this review.
        </p>
        <nav
            aria-label="Readiness pages"
            class="flex flex-wrap items-center gap-3"
        >
            <button
                class="min-h-11 rounded-md border px-3 disabled:opacity-40"
                :disabled="readiness.page <= 1"
                @click="run(readiness.page - 1)"
            >
                Previous</button
            ><span class="text-sm"
                >{{ readiness.page }} / {{ readiness.lastPage }}</span
            ><button
                class="min-h-11 rounded-md border px-3 disabled:opacity-40"
                :disabled="readiness.page >= readiness.lastPage"
                @click="run(readiness.page + 1)"
            >
                Next
            </button>
        </nav>
        <section class="space-y-3">
            <h2 class="text-lg font-semibold">
                Opening counts awaiting independent check
            </h2>
            <p class="text-sm text-muted-foreground">
                For the SKUs on this page. Verify batch, location and the
                physical count independently before signing. Self-sign-off is
                unavailable.
            </p>
            <p v-if="readiness.pendingLimited" class="text-sm">
                Results are limited to 25 pending openings per page. Use the
                opening pages to review more.
            </p>
            <article
                v-for="opening in readiness.pending"
                :key="opening.public_id"
                class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
            >
                <div class="min-w-0 text-sm">
                    <p class="break-words">
                        {{
                            readiness.rows.find(
                                (r) => r.id === opening.inventory_sku_id,
                            )?.sku_code
                        }}
                        · {{ opening.location_code }}
                    </p>
                    <p>
                        Batch {{ opening.batch_number }} · expires
                        {{ opening.expiry_date }} · {{ opening.quantity }}
                        {{
                            readiness.rows.find(
                                (r) => r.id === opening.inventory_sku_id,
                            )?.stock_unit
                        }}
                    </p>
                </div>
                <button
                    v-if="opening.canVerify"
                    :disabled="busy"
                    class="min-h-11 rounded-md border px-3 text-sm disabled:opacity-50"
                    @click="verify('opening', opening.public_id)"
                >
                    Sign off opening</button
                ><span v-else class="text-sm text-muted-foreground"
                    >Independent authorised checker required</span
                >
            </article>
            <p v-if="readiness.pending.length === 0" class="text-sm">
                No pending openings on this page.
            </p>
            <nav
                aria-label="Opening verification pages"
                class="flex flex-wrap items-center gap-3"
            >
                <button
                    class="min-h-11 rounded-md border px-3 disabled:opacity-40"
                    :disabled="readiness.openingPage <= 1"
                    @click="openingPage(readiness.openingPage - 1)"
                >
                    Previous openings
                </button>
                <span class="text-sm"
                    >{{ readiness.openingPage }} /
                    {{ readiness.openingLastPage }}</span
                >
                <button
                    class="min-h-11 rounded-md border px-3 disabled:opacity-40"
                    :disabled="
                        readiness.openingPage >= readiness.openingLastPage
                    "
                    @click="openingPage(readiness.openingPage + 1)"
                >
                    Next openings
                </button>
            </nav>
        </section>
    </main>
</template>
