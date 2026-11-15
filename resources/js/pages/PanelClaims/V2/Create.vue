<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2LeaveGuard from '@/components/v2/V2LeaveGuard.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Stat from '@/components/v2/V2Stat.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePanelClaimCreate } from '@/composables/panel-claims/usePanelClaimCreate';
import type { Props } from '@/composables/panel-claims/usePanelClaimCreate';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { displayFromIso } from '@/lib/v2-date';
import { myr } from '@/types/billing';

/**
 * Version 2 Create claim page (shown only in V2; Classic is `pages/PanelClaims/Create.vue`, untouched). The same form,
 * the same request (`usePanelClaimCreate` is a copy of Classic's script), the same rules: look only. The one new thing
 * is read-only: the Panel's own due rule and due date (`allocation.dueRule`, `allocation.dueBy`).
 */
const props = defineProps<Props>();
usePageCrumbs(() => [
    { title: 'Panel claims', href: '/panel-claims' },
    { title: 'Create claim' },
]);
const {
    createClaim,
    errors,
    fieldAnnouncedErrors,
    form,
    lines,
    page,
    pages,
    photoError,
    selectPhotos,
} = usePanelClaimCreate(props);

const MAX_PHOTOS = 10;
const allocation = computed(() => props.allocation);
const dateInvalid = ref(false);
const dateTried = ref(false);
const dateError = computed(
    () =>
        form.errors.submitted_date ??
        (dateTried.value && (dateInvalid.value || form.submitted_date === '')
            ? 'Enter the submitted date, day first, such as 13 Nov 2026.'
            : undefined),
);
const aboveInvoiceSen = computed(() =>
    Math.max(0, allocation.value.amountSen - allocation.value.invoiceTotalSen),
);
const figures = computed(() => [
    { label: 'Panel share to claim', value: myr(allocation.value.amountSen) },
    { label: 'Invoice total', value: myr(allocation.value.invoiceTotalSen) },
]);

// What the press will record, kept in step with the date box.
const sentence = computed(() => {
    const when = displayFromIso(form.submitted_date);

    return when
        ? `Records a Submitted claim of ${myr(allocation.value.amountSen)} to ${allocation.value.panelName}, dated ${when}. You submit it on the Panel's portal yourself; KPOne sends nothing.`
        : null;
});

const submit = () => {
    if (form.processing) {
        return;
    }

    if (dateInvalid.value || form.submitted_date === '') {
        dateTried.value = true;

        return;
    }

    createClaim();
};

const sizeLabel = (bytes: number) =>
    bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
const photoStatus = computed(() =>
    form.photos.length >= MAX_PHOTOS
        ? `Full: ${MAX_PHOTOS} of ${MAX_PHOTOS} photos. JPG or PNG, up to 5 MB each.`
        : `${form.photos.length} of ${MAX_PHOTOS} photos. JPG or PNG, up to 5 MB each.`,
);
const details = computed(() => [
    // Classic prints the same name under "Billed to" and "Patient details"; one row says both.
    {
        label: 'Billed to and patient details',
        value: allocation.value.patientName,
    },
    { label: 'Invoice no.', value: allocation.value.invoiceNumber },
    { label: 'Visit date and time', value: allocation.value.visitDateTime },
]);
const outline =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
const filled =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-progress disabled:opacity-70 lg:h-9';
</script>

<template>
    <Head title="Create panel claim" />
    <PageFrame width="wide">
        <V2LeaveGuard :dirty="form.isDirty && !form.processing" />
        <V2WorkLayout
            title="Create panel claim"
            rail-from="2xl"
            rail-label="Claim figures"
            :description="`${allocation.panelName} · invoice ${allocation.invoiceNumber} · ${allocation.patientName}`"
        >
            <template #actions>
                <Link href="/panel-claims" :class="outline">Cancel</Link>
                <button
                    type="submit"
                    form="v2-create-claim-form"
                    :class="[filled, 'max-sm:hidden']"
                    :disabled="form.processing"
                    :aria-busy="form.processing ? 'true' : undefined"
                >
                    {{ form.processing ? 'Creating…' : 'Create claim' }}
                </button>
            </template>

            <template #rail-collapsed>
                <section
                    class="grid grid-cols-1 gap-3 rounded-[14px] border bg-card p-4 min-[420px]:grid-cols-2"
                    aria-label="Claim figures"
                >
                    <V2Stat
                        v-for="item in figures"
                        :key="item.label"
                        :label="item.label"
                        :value="item.value"
                    />
                    <p
                        v-if="aboveInvoiceSen > 0"
                        class="text-sm text-muted-foreground min-[420px]:col-span-2"
                    >
                        Above the invoice by {{ myr(aboveInvoiceSen) }}. The
                        extra is kept on the claim only; the invoice stays the
                        same.
                    </p>
                </section>
            </template>

            <template #main>
                <h2 class="sr-only">Create panel claim</h2>
                <div
                    v-if="errors.length"
                    class="rounded-[10px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    <p
                        v-for="[field, message] in errors"
                        :key="field"
                        class="wrap-anywhere"
                        :role="
                            fieldAnnouncedErrors.has(field)
                                ? undefined
                                : 'alert'
                        "
                    >
                        {{ message }}
                    </p>
                </div>

                <form
                    id="v2-create-claim-form"
                    class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:grid-rows-[auto_1fr] xl:items-start"
                    @submit.prevent="submit"
                >
                    <section
                        class="grid gap-3 rounded-[14px] border bg-card p-4 xl:col-start-1 xl:row-start-1"
                    >
                        <div>
                            <h2 class="text-base leading-[22px] font-semibold">
                                Invoice details
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Below are the details of the selected invoice.
                            </p>
                        </div>
                        <V2KeyValueList :items="details" empty-text="N/A" />
                    </section>

                    <section
                        class="grid gap-4 rounded-[14px] border bg-card p-4 xl:col-start-2 xl:row-span-2 xl:row-start-1"
                    >
                        <div>
                            <h2 class="text-base leading-[22px] font-semibold">
                                Panel claim details
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Key information related to the submitted panel
                                claim.
                            </p>
                        </div>
                        <div
                            class="flex items-center justify-between gap-3 rounded-[10px] bg-muted p-3"
                        >
                            <span class="min-w-0 font-medium wrap-anywhere">{{
                                allocation.panelName
                            }}</span>
                            <span
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ myr(allocation.amountSen) }}</span
                            >
                        </div>
                        <p
                            v-if="aboveInvoiceSen > 0"
                            class="text-sm text-muted-foreground 2xl:hidden"
                        >
                            Above the invoice by {{ myr(aboveInvoiceSen) }}. The
                            extra is kept on the claim only; the invoice stays
                            the same.
                        </p>
                        <div class="grid gap-1">
                            <V2DateField
                                v-model="form.submitted_date"
                                label="Submitted date"
                                subject="Submitted date"
                                required
                                :min="allocation.visitDate"
                                :max="branch.today"
                                :error="dateError"
                                @update:invalid="
                                    (value) => (dateInvalid = value)
                                "
                            />
                            <p
                                v-if="allocation.dueRule"
                                class="text-[13px] text-muted-foreground"
                            >
                                This Panel's rule: {{ allocation.dueRule }}.
                                <template v-if="allocation.dueBy"
                                    >Due {{ allocation.dueBy }}.</template
                                >
                            </p>
                        </div>
                        <V2Field label="Remarks" :error="form.errors.remarks">
                            <template #default="{ id, describedBy }">
                                <textarea
                                    :id="id"
                                    v-model="form.remarks"
                                    :aria-describedby="describedBy"
                                    maxlength="500"
                                    rows="4"
                                    class="w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]"
                                />
                                <p
                                    class="text-right text-xs text-muted-foreground"
                                >
                                    {{ form.remarks.length }}/500
                                </p>
                            </template>
                        </V2Field>
                        <div class="grid gap-2">
                            <p class="text-sm font-medium">Upload photo</p>
                            <div
                                class="grid gap-2 rounded-[10px] border-2 border-dashed p-3"
                                @dragover.prevent
                                @drop.prevent="
                                    selectPhotos(
                                        $event.dataTransfer?.files ?? null,
                                    )
                                "
                            >
                                <p
                                    class="text-sm text-muted-foreground max-lg:hidden"
                                >
                                    Drop photos here or choose files.
                                </p>
                                <FileChooseButton
                                    id="photos"
                                    accept="image/jpeg,image/png"
                                    multiple
                                    label="Choose photos"
                                    :status="photoStatus"
                                    status-id="photos-status"
                                    @change="
                                        selectPhotos(
                                            ($event.target as HTMLInputElement)
                                                .files,
                                        )
                                    "
                                />
                            </div>
                            <p
                                v-if="photoError || form.errors.photos"
                                role="alert"
                                class="text-sm wrap-anywhere text-destructive"
                            >
                                {{ photoError || form.errors.photos }}
                            </p>
                            <ul
                                v-if="form.photos.length > 0"
                                class="divide-y rounded-[10px] border"
                            >
                                <li
                                    v-for="(file, index) in form.photos"
                                    :key="`${file.name}-${index}`"
                                    class="flex items-center justify-between gap-2 p-2 pl-3 text-sm"
                                >
                                    <span class="min-w-0 truncate">{{
                                        file.name
                                    }}</span>
                                    <span
                                        class="shrink-0 text-xs text-muted-foreground"
                                        >{{ sizeLabel(file.size) }}</span
                                    >
                                    <button
                                        type="button"
                                        :class="[outline, 'shrink-0']"
                                        :aria-label="`Remove ${file.name}`"
                                        @click="form.photos.splice(index, 1)"
                                    >
                                        Remove
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p
                            v-if="sentence"
                            class="text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {{ sentence }}
                        </p>
                    </section>

                    <V2DisclosureBar
                        class="xl:hidden"
                        :title="`Treatment items (${allocation.lines.length})`"
                    >
                        <div class="grid gap-3">
                            <V2Table
                                label="Treatment items"
                                :columns="4"
                                cards="lg"
                                min-width="520px"
                            >
                                <template #head>
                                    <tr>
                                        <th scope="col">Item name</th>
                                        <th scope="col" class="text-right">
                                            Rate
                                        </th>
                                        <th scope="col" class="text-right">
                                            Qty
                                        </th>
                                        <th scope="col" class="text-right">
                                            Total
                                        </th>
                                    </tr>
                                </template>
                                <template #body>
                                    <tr v-for="line in lines" :key="line.id">
                                        <td
                                            data-label="Item name"
                                            data-primary
                                            data-wrap
                                        >
                                            {{ line.name }}
                                        </td>
                                        <td
                                            data-label="Rate"
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{ myr(line.unitPriceSen) }}
                                        </td>
                                        <td data-label="Qty" class="text-right">
                                            {{ Number(line.quantity) }}
                                        </td>
                                        <td
                                            data-label="Total"
                                            class="text-right whitespace-nowrap"
                                        >
                                            {{ myr(line.totalSen) }}
                                        </td>
                                    </tr>
                                </template>
                            </V2Table>
                            <V2Pager
                                v-if="pages > 1"
                                :current-page="page"
                                :last-page="pages"
                                :total="allocation.lines.length"
                                :per-page="10"
                                @change="(value) => (page = value)"
                            />
                            <p class="text-sm font-semibold">
                                Total: {{ myr(allocation.invoiceTotalSen) }} ({{
                                    allocation.lines.length
                                }}
                                items)
                            </p>
                            <dl
                                class="ml-auto grid w-full max-w-sm gap-2 text-xs"
                                aria-label="Invoice amount breakdown"
                            >
                                <div class="flex justify-between gap-3">
                                    <dt>Original subtotal</dt>
                                    <dd class="tabular-nums">
                                        {{
                                            myr(
                                                allocation.invoiceBreakdown
                                                    .subtotalSen,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt>Invoice adjustment</dt>
                                    <dd class="tabular-nums">
                                        {{
                                            allocation.invoiceBreakdown
                                                .adjustmentSen < 0
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
                                <div
                                    class="flex justify-between gap-3 font-semibold"
                                >
                                    <dt>Adjusted total</dt>
                                    <dd class="tabular-nums">
                                        {{ myr(allocation.invoiceTotalSen) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </V2DisclosureBar>

                    <section
                        class="hidden gap-3 rounded-[14px] border bg-card p-4 xl:col-start-1 xl:row-start-2 xl:grid"
                    >
                        <h2 class="text-base leading-[22px] font-semibold">
                            Treatment items
                        </h2>
                        <V2Table
                            label="Treatment items"
                            :columns="4"
                            min-width="520px"
                        >
                            <template #head>
                                <tr>
                                    <th scope="col">Item name</th>
                                    <th scope="col" class="text-right">Rate</th>
                                    <th scope="col" class="text-right">Qty</th>
                                    <th scope="col" class="text-right">
                                        Total
                                    </th>
                                </tr>
                            </template>
                            <template #body>
                                <tr v-for="line in lines" :key="line.id">
                                    <td data-wrap>{{ line.name }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        {{ myr(line.unitPriceSen) }}
                                    </td>
                                    <td class="text-right">
                                        {{ Number(line.quantity) }}
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        {{ myr(line.totalSen) }}
                                    </td>
                                </tr>
                            </template>
                        </V2Table>
                        <V2Pager
                            v-if="pages > 1"
                            :current-page="page"
                            :last-page="pages"
                            :total="allocation.lines.length"
                            :per-page="10"
                            @change="(value) => (page = value)"
                        />
                        <p class="text-sm font-semibold">
                            Total: {{ myr(allocation.invoiceTotalSen) }} ({{
                                allocation.lines.length
                            }}
                            items)
                        </p>
                    </section>

                    <V2StickyBar hide-from="sm" class="xl:col-span-2">
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-progress disabled:opacity-70"
                            :disabled="form.processing"
                            :aria-busy="form.processing ? 'true' : undefined"
                        >
                            {{ form.processing ? 'Creating…' : 'Create claim' }}
                        </button>
                    </V2StickyBar>
                </form>
            </template>

            <template #rail>
                <div class="grid gap-3 rounded-[14px] border bg-card p-4">
                    <h2 class="text-base leading-[22px] font-semibold">
                        Claim figures
                    </h2>
                    <V2Stat
                        v-for="item in figures"
                        :key="item.label"
                        :label="item.label"
                        :value="item.value"
                    />
                    <p
                        v-if="aboveInvoiceSen > 0"
                        class="text-sm text-muted-foreground"
                    >
                        Above the invoice by {{ myr(aboveInvoiceSen) }}. The
                        extra is kept on the claim only; the invoice stays the
                        same.
                    </p>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
