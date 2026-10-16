<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { myr } from '@/types/billing';
import type { InvoiceAdjustmentSummary } from '@/types/billing';

const props = defineProps<{
    branch: { id: number; today: string };
    allocation: {
        publicId: string;
        lockVersion: number;
        invoiceLockVersion: number;
        panelName: string;
        amountSen: number;
        patientName: string;
        invoiceNumber: string;
        invoiceTotalSen: number;
        invoiceBreakdown: InvoiceAdjustmentSummary;
        visitDate: string;
        visitDateTime: string;
        lines: {
            id: number;
            name: string;
            quantity: string;
            unitPriceSen: number;
            totalSen: number;
        }[];
    };
}>();
const form = useForm({
    expected_branch_id: props.branch.id,
    allocation_lock_version: props.allocation.lockVersion,
    invoice_lock_version: props.allocation.invoiceLockVersion,
    idempotency_key: crypto.randomUUID(),
    submitted_date: props.branch.today,
    remarks: '',
    photos: [] as File[],
});
const page = ref(1);
const pages = computed(() =>
    Math.max(1, Math.ceil(props.allocation.lines.length / 10)),
);
const lines = computed(() =>
    props.allocation.lines.slice((page.value - 1) * 10, page.value * 10),
);
const photoError = ref('');
const selectPhotos = (files: FileList | null) => {
    if (!files) {
        return;
    }

    const selected = [...form.photos, ...Array.from(files)];

    if (
        selected.length > 10 ||
        selected.some(
            (file) =>
                !['image/jpeg', 'image/png'].includes(file.type) ||
                file.size > 5 * 1024 * 1024 ||
                file.size === 0,
        )
    ) {
        photoError.value =
            'Select up to 10 JPG or PNG photos, at most 5 MB each.';

        return;
    }

    photoError.value = '';
    form.photos = selected;
};
const createClaim = () =>
    form.post(
        `/panel-claims/allocations/${encodeURIComponent(props.allocation.publicId)}`,
        { forceFormData: true },
    );
const errors = computed(() => Object.entries(form.errors));
</script>

<template>
    <Head title="Create panel claim" />
    <main class="mx-auto w-full max-w-[1400px] space-y-6 px-4 py-6">
        <form class="space-y-6" @submit.prevent="createClaim">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Link
                        href="/panel-claims"
                        aria-label="Back to Panel Claims"
                        class="rounded p-2 hover:bg-muted"
                        >←</Link
                    >
                    <h1 class="text-2xl font-semibold">Create panel claim</h1>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/panel-claims" class="rounded border px-4 py-2"
                        >Cancel</Link
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        form.processing ? 'Creating…' : 'Create claim'
                    }}</Button>
                </div>
            </header>
            <div
                v-if="errors.length"
                role="alert"
                class="rounded-lg border border-destructive p-4 text-sm text-destructive"
            >
                <p v-for="[field, message] in errors" :key="field">
                    {{ message }}
                </p>
            </div>
            <div
                class="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
            >
                <section
                    class="min-w-0 rounded-xl border bg-card p-6 shadow-sm"
                >
                    <h2 class="text-lg font-semibold">Invoice details</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Below are the details of the selected invoice.
                    </p>
                    <div class="my-6 grid gap-6 sm:grid-cols-3">
                        <div>
                            <h3 class="mb-3 font-semibold">Billing details</h3>
                            <p>{{ allocation.patientName }}</p>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Staff ID: N/A
                            </p>
                        </div>
                        <div>
                            <h3 class="mb-3 font-semibold">Patient details</h3>
                            <p>{{ allocation.patientName }}</p>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Chit no: N/A
                            </p>
                        </div>
                        <div>
                            <h3 class="mb-3 font-semibold">Invoice details</h3>
                            <p class="text-sm">
                                Invoice no.: {{ allocation.invoiceNumber }}
                            </p>
                            <p class="mt-2 text-sm">
                                Visit date and time:
                                {{ allocation.visitDateTime }}
                            </p>
                        </div>
                    </div>
                    <h3 class="mb-3 font-semibold">Treatment items</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-y bg-muted/50">
                                <tr>
                                    <th class="p-3">Item name</th>
                                    <th class="p-3 text-right">Rate</th>
                                    <th class="p-3 text-right">Qty</th>
                                    <th class="p-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="line in lines"
                                    :key="line.id"
                                    class="border-b"
                                >
                                    <td class="p-3">{{ line.name }}</td>
                                    <td
                                        class="p-3 text-right whitespace-nowrap"
                                    >
                                        {{ myr(line.unitPriceSen) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ Number(line.quantity) }}
                                    </td>
                                    <td
                                        class="p-3 text-right whitespace-nowrap"
                                    >
                                        {{ myr(line.totalSen) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm"
                    >
                        <div class="flex items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="page === 1"
                                @click="page--"
                                >Previous</Button
                            ><span>Page {{ page }} of {{ pages }}</span
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="page === pages"
                                @click="page++"
                                >Next</Button
                            >
                        </div>
                        <p class="font-semibold">
                            Total: {{ myr(allocation.invoiceTotalSen) }} ({{
                                allocation.lines.length
                            }}
                            items)
                        </p>
                    </div>
                    <dl
                        class="mt-3 ml-auto grid max-w-sm gap-2 text-xs"
                        aria-label="Invoice amount breakdown"
                    >
                        <div class="flex justify-between gap-3">
                            <dt>Original subtotal</dt>
                            <dd>
                                {{
                                    myr(allocation.invoiceBreakdown.subtotalSen)
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Invoice adjustment</dt>
                            <dd>
                                {{
                                    allocation.invoiceBreakdown.adjustmentSen <
                                    0
                                        ? '−'
                                        : '+'
                                }}{{
                                    myr(
                                        Math.abs(
                                            allocation.invoiceBreakdown
                                                .adjustmentSen,
                                        ),
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3 font-semibold">
                            <dt>Adjusted total</dt>
                            <dd>{{ myr(allocation.invoiceTotalSen) }}</dd>
                        </div>
                    </dl>
                </section>
                <section
                    class="min-w-0 space-y-5 rounded-xl border bg-card p-6 shadow-sm"
                >
                    <div>
                        <h2 class="text-lg font-semibold">
                            Panel claim details
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Key information related to the submitted panel
                            claim.
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-between gap-3 rounded-lg bg-muted p-4"
                    >
                        <span class="font-medium">{{
                            allocation.panelName
                        }}</span
                        ><span class="font-semibold whitespace-nowrap">{{
                            myr(allocation.amountSen)
                        }}</span>
                    </div>
                    <div>
                        <label
                            for="submitted-date"
                            class="mb-2 block text-sm font-medium"
                            >Submitted date</label
                        ><input
                            id="submitted-date"
                            v-model="form.submitted_date"
                            type="date"
                            required
                            :min="allocation.visitDate"
                            :max="branch.today"
                            class="w-full rounded-md border bg-background px-3 py-2"
                        /><InputError :message="form.errors.submitted_date" />
                    </div>
                    <div>
                        <label
                            for="remarks"
                            class="mb-2 block text-sm font-medium"
                            >Remarks</label
                        ><textarea
                            id="remarks"
                            v-model="form.remarks"
                            maxlength="500"
                            rows="4"
                            class="w-full rounded-md border bg-background px-3 py-2"
                        /><InputError :message="form.errors.remarks" />
                        <p
                            class="mt-1 text-right text-xs text-muted-foreground"
                        >
                            {{ form.remarks.length }}/500
                        </p>
                    </div>
                    <div>
                        <label
                            for="photos"
                            class="mb-2 block text-sm font-medium"
                            >Upload photo</label
                        >
                        <div
                            class="rounded-lg border-2 border-dashed p-5"
                            @dragover.prevent
                            @drop.prevent="
                                selectPhotos($event.dataTransfer?.files ?? null)
                            "
                        >
                            <p class="mb-3 text-sm text-muted-foreground">
                                Drop photos here or choose files. JPG and PNG,
                                max 5 MB each; up to 10 photos.
                            </p>
                            <input
                                id="photos"
                                type="file"
                                accept="image/jpeg,image/png"
                                multiple
                                class="w-full text-sm"
                                @change="
                                    selectPhotos(
                                        ($event.target as HTMLInputElement)
                                            .files,
                                    )
                                "
                            />
                        </div>
                        <InputError
                            :message="photoError || form.errors.photos"
                        />
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="(file, index) in form.photos"
                                :key="index"
                                class="flex items-center justify-between gap-2 text-sm"
                            >
                                <span class="truncate">{{ file.name }}</span
                                ><button
                                    type="button"
                                    class="underline"
                                    :aria-label="`Remove ${file.name}`"
                                    @click="form.photos.splice(index, 1)"
                                >
                                    Remove
                                </button>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </form>
    </main>
</template>
