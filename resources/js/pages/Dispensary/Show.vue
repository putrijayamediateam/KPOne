<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, LoaderCircle, RotateCcw } from '@lucide/vue';
import { reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dispensary', href: '/registration' }] },
});

type Availability = {
    locationPublicId: string;
    locationName: string;
    batchPublicId: string;
    batchNumber: string;
    expiryDate: string;
    quantity: string;
};
type Item = {
    publicId: string;
    lockVersion: number;
    name: string;
    code: string;
    strength: string | null;
    dosageForm: string | null;
    unit: string;
    quantityOrdered: string;
    quantityDispensed: string | null;
    status: 'pending' | 'dispensed' | 'partial' | 'not_dispensed';
    reason: string | null;
    dosage: string;
    frequency: string;
    duration: string | null;
    route: string | null;
    instruction: string | null;
    precaution: string | null;
    sku: { publicId: string; unit: string } | null;
    availability: Availability[];
    exception: {
        public_id: string;
        status: string;
        proposed_quantity_dispensed: string;
    } | null;
};
type Page = {
    publicId: string;
    status: 'pending' | 'dispensing';
    lockVersion: number;
    receivedAt: string;
    patient: { patientNumber: string; name: string };
    visit: { visitNumber: string };
    doctor: string | null;
    allergySafety: {
        status: string;
        profileVersion: number | null;
        isCurrent: boolean;
    };
    items: Item[];
    can: {
        start: boolean;
        update: boolean;
        complete: boolean;
        return: boolean;
    };
};
const props = defineProps<{ dispensary: Page }>();
const busy = ref(false);
const actionError = ref<string | null>(null);
const forms = reactive(
    Object.fromEntries(
        props.dispensary.items.map((item) => [
            item.publicId,
            {
                status: item.status,
                quantity: item.quantityDispensed ?? '',
                reason: item.reason ?? '',
                availabilityIndex: 0,
            },
        ]),
    ),
) as Record<
    string,
    {
        status: string;
        quantity: string;
        reason: string;
        availabilityIndex: number;
    }
>;
const page = usePage();
const branchId = () => page.props.branchContext?.active?.id ?? 0;
const showActionError = (errors: Record<string, string>) => {
    actionError.value =
        Object.values(errors)[0] ??
        'The Dispensary action could not be completed. Review current details and retry.';
};
const caseAction = (suffix: string) => {
    busy.value = true;
    actionError.value = null;
    router.post(
        `/dispensary/${props.dispensary.publicId}/${suffix}`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
const saveItem = (item: Item) => {
    const form = forms[item.publicId];
    const availability = item.availability[form.availabilityIndex];
    const allocations =
        form.quantity && Number(form.quantity) > 0 && availability && item.sku
            ? [
                  {
                      location_public_id: availability.locationPublicId,
                      sku_public_id: item.sku.publicId,
                      batch_public_id: availability.batchPublicId,
                      quantity: form.quantity,
                  },
              ]
            : [];
    busy.value = true;
    actionError.value = null;
    router.patch(
        `/dispensary/${props.dispensary.publicId}/items/${item.publicId}`,
        {
            expected_branch_id: branchId(),
            case_lock_version: props.dispensary.lockVersion,
            item_lock_version: item.lockVersion,
            status: form.status,
            quantity_dispensed:
                form.status === 'pending' ? null : form.quantity,
            reason: form.reason || null,
            allocations,
        },
        {
            preserveScroll: true,
            onError: showActionError,
            onFinish: () => (busy.value = false),
        },
    );
};
</script>

<template>
    <Head title="Dispensary" />
    <main class="mx-auto w-full max-w-[1500px] px-4 py-4 lg:px-6">
        <header
            class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b pb-3"
        >
            <div>
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    Dispensary
                </p>
                <h1 class="text-lg font-semibold">
                    {{ dispensary.patient.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ dispensary.patient.patientNumber }} ·
                    {{ dispensary.visit.visitNumber }} ·
                    {{ dispensary.doctor ?? 'No doctor' }}
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="dispensary.can.start"
                    :disabled="busy"
                    @click="caseAction('start')"
                    >Start Dispensing</Button
                ><Button
                    v-if="dispensary.can.return"
                    variant="outline"
                    :disabled="busy"
                    @click="caseAction('return-to-doctor')"
                    ><RotateCcw class="size-4" />Return to Doctor</Button
                ><Button
                    v-if="dispensary.can.complete"
                    :disabled="busy"
                    @click="caseAction('complete')"
                    ><CheckCircle2 class="size-4" />Complete Dispensary</Button
                >
            </div>
        </header>

        <p
            v-if="actionError"
            role="alert"
            class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
        >
            {{ actionError }}
        </p>
        <div
            class="mb-3 flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
            :class="
                dispensary.allergySafety.isCurrent
                    ? 'border-emerald-200 bg-emerald-50/50'
                    : 'border-amber-300 bg-amber-50/70'
            "
        >
            <span
                >Allergy safety:
                <strong>{{
                    dispensary.allergySafety.status.replaceAll('_', ' ')
                }}</strong></span
            ><span>{{
                dispensary.allergySafety.isCurrent
                    ? 'Current'
                    : 'Return to doctor required'
            }}</span>
        </div>
        <section class="divide-y rounded-lg border bg-background">
            <article
                v-for="item in dispensary.items"
                :key="item.publicId"
                class="grid gap-3 p-3 lg:grid-cols-[minmax(230px,1.2fr)_minmax(420px,2fr)_auto]"
            >
                <div>
                    <h2 class="text-sm font-medium">{{ item.name }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            [item.strength, item.dosageForm, item.code]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </p>
                    <p class="mt-2 text-xs">
                        Ordered
                        <strong
                            >{{ item.quantityOrdered }} {{ item.unit }}</strong
                        >
                    </p>
                </div>
                <div class="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <p>
                        <span class="text-muted-foreground">Dosage</span
                        ><br />{{ item.dosage }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Frequency</span
                        ><br />{{ item.frequency }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Duration</span
                        ><br />{{ item.duration ?? 'Not recorded' }}
                    </p>
                    <p>
                        <span class="text-muted-foreground">Route</span><br />{{
                            item.route ?? 'Not recorded'
                        }}
                    </p>
                    <p class="sm:col-span-2">
                        <span class="text-muted-foreground">Instruction</span
                        ><br />{{ item.instruction ?? 'Not recorded' }}
                    </p>
                    <p class="sm:col-span-2">
                        <span class="text-muted-foreground">Precaution</span
                        ><br />{{ item.precaution ?? 'Not recorded' }}
                    </p>
                </div>
                <div class="grid min-w-64 gap-2" v-if="dispensary.can.update">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-xs"
                            >Actual<input
                                v-model="forms[item.publicId].quantity"
                                class="mt-1 h-9 w-full rounded-md border px-2"
                                inputmode="decimal" /></label
                        ><label class="text-xs"
                            >State<select
                                v-model="forms[item.publicId].status"
                                class="mt-1 h-9 w-full rounded-md border px-2"
                            >
                                <option value="pending">Pending</option>
                                <option value="dispensed">Dispensed</option>
                                <option value="partial">Partial</option>
                                <option value="not_dispensed">
                                    Not dispensed
                                </option>
                            </select></label
                        >
                    </div>
                    <label
                        v-if="
                            forms[item.publicId].status === 'partial' ||
                            forms[item.publicId].status === 'not_dispensed'
                        "
                        class="text-xs"
                        >Reason<select
                            v-model="forms[item.publicId].reason"
                            class="mt-1 h-9 w-full rounded-md border px-2"
                        >
                            <option value="">Select</option>
                            <option value="patient_declined">
                                Patient declined
                            </option>
                            <option value="out_of_stock">Out of stock</option>
                            <option value="clarification_required">
                                Clarification required
                            </option>
                            <option value="other">Other</option>
                        </select></label
                    >
                    <label
                        v-if="Number(forms[item.publicId].quantity) > 0"
                        class="text-xs"
                        >Batch<select
                            v-model.number="
                                forms[item.publicId].availabilityIndex
                            "
                            class="mt-1 h-9 w-full rounded-md border px-2"
                        >
                            <option
                                v-for="(stock, index) in item.availability"
                                :key="stock.batchPublicId"
                                :value="index"
                            >
                                {{ stock.locationName }} ·
                                {{ stock.batchNumber }} · exp
                                {{ stock.expiryDate }} · {{ stock.quantity }}
                            </option>
                        </select></label
                    >
                    <p v-if="!item.sku" class="text-xs text-amber-700">
                        No approved inventory SKU mapping.
                    </p>
                    <p
                        v-else-if="!item.availability.length"
                        class="text-xs text-amber-700"
                    >
                        No eligible stock in permitted locations.
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="busy"
                        @click="saveItem(item)"
                        ><LoaderCircle
                            v-if="busy"
                            class="size-4 animate-spin"
                        />Save fulfilment</Button
                    >
                </div>
            </article>
        </section>
    </main>
</template>
