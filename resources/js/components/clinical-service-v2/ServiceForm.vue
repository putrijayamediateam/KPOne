<script setup lang="ts">
import { computed } from 'vue';
import CatalogueOptionPicker from '@/components/catalogue/CatalogueOptionPicker.vue';
import PanelTariffEditor from '@/components/catalogue/PanelTariffEditor.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type {
    SetupOptions,
    useServiceForm,
} from '@/composables/clinical-service/useServiceForm';

/**
 * Version 2 Add and Edit clinical service: one full page, one column of form cards. Same fields and the same payload as
 * the Classic dialog (the state and the submit come from `useServiceForm`, a copy of Classic's script).
 */
const props = defineProps<{
    c: ReturnType<typeof useServiceForm>;
    setup: SetupOptions;
}>();
defineEmits<{ cancel: [] }>();

const form = props.c.form;
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const field = (invalid?: boolean) =>
    invalid
        ? inputClass.replace('border-input', 'border-destructive')
        : inputClass;
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';

const err = (key: string) => props.c.formError(key);
const saveLabel = computed(() =>
    props.c.editing.value ? 'Save' : 'Add clinical service',
);
const saveClass = computed(() =>
    props.c.editing.value && !props.c.isDirty.value
        ? outlineButton
        : filledButton,
);
const saveDisabled = computed(
    () =>
        form.processing ||
        props.c.editLoading.value ||
        !!props.c.editLoadError.value,
);
const branchOptions = computed(() =>
    props.setup.branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    })),
);
</script>

<template>
    <p
        v-if="c.editLoading.value"
        class="py-10 text-center text-sm text-muted-foreground"
        role="status"
    >
        Loading the saved clinical service and price details…
    </p>
    <form
        v-else
        class="flex min-w-0 flex-col gap-4"
        data-test="service-form"
        @submit.prevent="c.submit()"
    >
        <p
            v-if="c.editLoadError.value"
            class="rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
            role="alert"
        >
            {{ c.editLoadError.value }}
        </p>

        <V2FormCard
            :step="1"
            title="Service details"
            description="Identify and classify the clinical service that staff can select when creating an order."
        >
            <div class="grid gap-4 md:grid-cols-2">
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Catalogue code"
                    required
                    :error="form.errors.code"
                >
                    <input
                        :id="id"
                        v-model="form.code"
                        autocomplete="off"
                        maxlength="64"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Display name"
                    required
                    :error="form.errors.display_name"
                >
                    <input
                        :id="id"
                        v-model="form.display_name"
                        autocomplete="off"
                        maxlength="500"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="field(invalid)"
                    />
                </V2Field>
                <V2Picker
                    :model-value="form.category || null"
                    label="Category (optional)"
                    hint="Use the clinic's invoice category for this service."
                    placeholder="No category"
                    :selected-label="form.category || undefined"
                    :options="
                        c.categoryOptions.value
                            .filter((o) => o.value !== '')
                            .map((o) => ({
                                id: String(o.value),
                                label: o.label,
                            }))
                    "
                    :error="form.errors.category"
                    @update:model-value="form.category = $event ?? ''"
                />
                <!-- The shared picker draws its own small label; match the V2 label and mark it required until V2Picker lands. -->
                <div
                    class="min-w-0 [&_label]:text-sm [&_label]:font-medium [&_label]:text-foreground [&_label]:after:text-destructive [&_label]:after:content-['_*']"
                >
                    <CatalogueOptionPicker
                        v-model="form.order_unit"
                        type="service_unit"
                        label="Order unit"
                        :error="form.errors.order_unit"
                    />
                </div>
            </div>
        </V2FormCard>

        <V2FormCard
            v-if="setup.canManagePrices"
            :step="2"
            title="Pricing"
            :description="
                c.editing.value
                    ? 'Update default self-pay and Panel prices. Existing published prices stay in the version history.'
                    : 'Set default self-pay and Panel prices. Published prices are kept as versioned records.'
            "
        >
            <template v-if="setup.canPublishPrices">
                <div class="grid gap-4 md:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Self-pay default price (RM)"
                        hint="Patients paying directly."
                        :error="err('prices.self_pay_sen')"
                    >
                        <input
                            :id="id"
                            v-model="form.prices.self_pay_rm"
                            aria-label="Self-pay default price in RM"
                            type="number"
                            min="0"
                            step="0.01"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="field(invalid)"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Default Panel price (RM)"
                        hint="For Panels without a custom price."
                        :error="err('prices.panel_default_sen')"
                    >
                        <input
                            :id="id"
                            v-model="form.prices.panel_default_rm"
                            aria-label="Default Panel price in RM"
                            type="number"
                            min="0"
                            step="0.01"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="field(invalid)"
                        />
                    </V2Field>
                </div>
                <div class="grid gap-2">
                    <div>
                        <h3 class="text-sm font-medium">
                            Panel-specific prices
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Add a Panel only when its price differs from the
                            default Panel price.
                        </p>
                    </div>
                    <PanelTariffEditor
                        v-model="form.prices.panel_overrides"
                        :panels="setup.panels"
                    />
                    <p
                        v-if="c.editing.value"
                        class="text-sm text-muted-foreground"
                    >
                        Published Panel prices are versioned. Removing a row
                        here does not erase its price history or switch that
                        Panel price off.
                    </p>
                </div>
                <V2Picker
                    :model-value="form.expected_branch_id || null"
                    label="Branch used for price publication"
                    :selected-label="
                        branchOptions.find(
                            (o) =>
                                String(o.value) ===
                                String(form.expected_branch_id),
                        )?.label
                    "
                    :options="
                        branchOptions.map((o) => ({
                            id: String(o.value),
                            label: o.label,
                        }))
                    "
                    :error="form.errors.expected_branch_id"
                    required
                    @update:model-value="form.expected_branch_id = $event ?? ''"
                />
            </template>
            <p v-else class="text-sm text-muted-foreground">
                Your account can manage catalogue references but cannot publish
                prices. An authorised pricing user can publish them later.
            </p>
        </V2FormCard>

        <!-- One action bar at every width for this form, so the filled button is always in reach. -->
        <div
            class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] lg:-mx-8 lg:px-8"
            data-test="service-form-actions"
        >
            <button
                type="button"
                :class="[outlineButton, 'max-sm:hidden']"
                :disabled="form.processing || c.editLoading.value"
                @click="$emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                :class="[saveClass, 'max-sm:w-full']"
                :disabled="saveDisabled"
            >
                {{ saveLabel }}
            </button>
        </div>
    </form>
</template>
