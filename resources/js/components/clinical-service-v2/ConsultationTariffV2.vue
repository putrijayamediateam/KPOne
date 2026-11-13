<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import { rmToSen } from '@/lib/money';

/**
 * Version 2 consultation tariff (Clinical Service Catalogue). Same props, same route
 * (`/clinical-services/consultation-tariffs`), same payload and expected versions as the Classic
 * `ConsultationTariffPanel`. It sits in a closed disclosure bar so it does not push the services table off the screen,
 * and its Save is an outline button (the page's one filled button is "Add clinical service").
 */
type PanelOverride = {
    panelId: string;
    panelName: string;
    amountRm: string | null;
    version: number;
};

type ConsultationTariff = {
    chargeExists: boolean;
    code: string;
    displayName: string;
    isActive: boolean;
    selfPayAmountRm: string | null;
    selfPayVersion: number;
    panelDefaultAmountRm: string | null;
    panelDefaultVersion: number;
    panelOverrides: PanelOverride[];
};

const props = defineProps<{
    tariff: ConsultationTariff;
    canManagePrices: boolean;
    canPublishPrices: boolean;
    activeBranchId: number | null;
    branches: { id: number; name: string }[];
    panels: { id: number; name: string }[];
}>();

const form = useForm({
    code: props.tariff.code,
    display_name: props.tariff.displayName,
    expected_branch_id: props.activeBranchId
        ? String(props.activeBranchId)
        : '',
    prices: {
        self_pay_rm: props.tariff.selfPayAmountRm ?? '',
        panel_default_rm: props.tariff.panelDefaultAmountRm ?? '',
        panel_overrides: props.tariff.panelOverrides.map((override) => ({
            panel_id: override.panelId,
            amount_rm: override.amountRm ?? '',
        })),
    },
    expected_versions: {
        self_pay: props.tariff.selfPayVersion,
        panel_default: props.tariff.panelDefaultVersion,
        panel_overrides: Object.fromEntries(
            props.tariff.panelOverrides.map((override) => [
                override.panelId,
                override.version,
            ]),
        ) as Record<string, number>,
    },
});
const formError = (key: string) => form.errors[key as keyof typeof form.errors];
const selectedNewPanel = ref('');
const saving = ref(false);
const savedNote = ref('');
const hasCharge = computed(() => props.tariff.chargeExists);
const addablePanels = computed(() => {
    const selected = new Set(
        form.prices.panel_overrides.map((override) => override.panel_id),
    );

    return props.panels.filter((panel) => !selected.has(String(panel.id)));
});

const syncFromTariff = (tariff: ConsultationTariff) => {
    form.code = tariff.code;
    form.display_name = tariff.displayName;
    form.prices.self_pay_rm = tariff.selfPayAmountRm ?? '';
    form.prices.panel_default_rm = tariff.panelDefaultAmountRm ?? '';
    form.prices.panel_overrides = tariff.panelOverrides.map((override) => ({
        panel_id: override.panelId,
        amount_rm: override.amountRm ?? '',
    }));
    form.expected_versions.self_pay = tariff.selfPayVersion;
    form.expected_versions.panel_default = tariff.panelDefaultVersion;
    form.expected_versions.panel_overrides = Object.fromEntries(
        tariff.panelOverrides.map((override) => [
            override.panelId,
            override.version,
        ]),
    ) as Record<string, number>;
};

// New props adopt the saved rates, except while our own Save is in flight: a refused Save (for example "changed since
// this page was opened") must keep what was typed and keep the old expected versions, so a retry is refused again
// until the person reloads and reviews. A successful Save adopts the new rates in `onSuccess`.
watch(
    () => props.tariff,
    (tariff) => {
        if (!saving.value) {
            syncFromTariff(tariff);
        }
    },
    { deep: true },
);

const panelOptions = computed(() =>
    addablePanels.value.map((panel) => ({
        id: String(panel.id),
        label: panel.name,
    })),
);
const branchOptions = computed(() =>
    props.branches.map((branch) => ({
        id: String(branch.id),
        label: branch.name,
    })),
);

const addPanelOverride = () => {
    if (!selectedNewPanel.value) {
        return;
    }

    form.prices.panel_overrides.push({
        panel_id: selectedNewPanel.value,
        amount_rm: '',
    });
    form.expected_versions.panel_overrides[selectedNewPanel.value] = 0;
    selectedNewPanel.value = '';
};

const rm = (amount: string | null) =>
    amount === null || amount === '' ? 'no price' : `RM ${amount}`;
// The closed bar shows the saved rates (not what is typed), so they can be read without opening it.
const barTitle = computed(() =>
    props.tariff.chargeExists
        ? `Consultation tariff · Self-pay ${rm(props.tariff.selfPayAmountRm)} · Default Panel ${rm(props.tariff.panelDefaultAmountRm)}`
        : 'Consultation tariff · not set up yet',
);

const panelName = (panelId: string) =>
    props.panels.find((panel) => String(panel.id) === panelId)?.name ??
    props.tariff.panelOverrides.find((saved) => saved.panelId === panelId)
        ?.panelName ??
    `Panel ${panelId}`;

const submit = () => {
    saving.value = true;
    form.transform((data) => ({
        ...data,
        expected_branch_id: data.expected_branch_id || null,
        prices: {
            self_pay_sen: rmToSen(data.prices.self_pay_rm),
            panel_default_sen: rmToSen(data.prices.panel_default_rm),
            panel_overrides: data.prices.panel_overrides.map((override) => ({
                panel_id: Number(override.panel_id),
                amount_sen: rmToSen(override.amount_rm),
            })),
        },
    }));
    savedNote.value = '';
    form.post('/clinical-services/consultation-tariffs', {
        preserveScroll: true,
        onSuccess: () => {
            syncFromTariff(props.tariff);
            savedNote.value = `Saved. Published now: Self-pay ${rm(form.prices.self_pay_rm)}, Default Panel ${rm(form.prices.panel_default_rm)}${form.prices.panel_overrides.length ? `, and ${form.prices.panel_overrides.length} Panel-specific ${form.prices.panel_overrides.length === 1 ? 'rate' : 'rates'}` : ''}.`;
        },
        onFinish: () => {
            saving.value = false;
            form.transform((data) => data);
        },
    });
};

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 max-sm:w-full lg:h-9';
</script>

<template>
    <V2DisclosureBar :title="barTitle" keep-mounted>
        <div class="flex flex-col gap-4" data-test="consultation-tariff">
            <p class="text-sm text-muted-foreground">
                These rates are used for the dedicated Consultation line on
                billing. This is not a separately ordered Clinical Service.
            </p>
            <p
                v-if="!canManagePrices"
                class="rounded-[10px] border border-dashed p-3 text-sm text-muted-foreground"
            >
                You can view the Clinical Service Catalogue but do not have
                permission to manage consultation tariffs.
            </p>
            <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
                <div
                    v-if="!hasCharge"
                    class="grid gap-3 rounded-[10px] bg-muted/30 p-3 sm:grid-cols-2"
                >
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Charge code"
                        required
                        :error="form.errors.code"
                    >
                        <input
                            :id="id"
                            v-model="form.code"
                            maxlength="64"
                            required
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Charge name"
                        required
                        :error="form.errors.display_name"
                    >
                        <input
                            :id="id"
                            v-model="form.display_name"
                            maxlength="500"
                            required
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <div
                    v-else
                    class="flex flex-wrap items-center justify-between gap-2 rounded-[10px] bg-muted/30 p-3 text-sm"
                >
                    <div>
                        <p class="font-medium">{{ tariff.displayName }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ tariff.code }} · Dedicated consultation charge
                        </p>
                    </div>
                    <V2Chip
                        :tone="tariff.isActive ? 'success' : 'warning'"
                        :label="
                            tariff.isActive
                                ? 'Active'
                                : 'Will reactivate on save'
                        "
                    />
                </div>

                <div class="flex flex-col gap-3">
                    <div>
                        <h3 class="text-sm font-medium">Default pricing</h3>
                        <p class="text-xs text-muted-foreground">
                            These organisation-wide prices are shared across
                            branches. Panel-specific rates can be set below.
                        </p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <V2MoneyInput
                            v-model="form.prices.self_pay_rm"
                            label="Self-pay price"
                            hint="Patients paying directly"
                            :disabled="!canPublishPrices"
                            :error="formError('prices.self_pay_sen')"
                        />
                        <V2MoneyInput
                            v-model="form.prices.panel_default_rm"
                            label="Default Panel price"
                            hint="Default for Panels without a custom rate"
                            :disabled="!canPublishPrices"
                            :error="formError('prices.panel_default_sen')"
                        />
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <div>
                        <h3 class="text-sm font-medium">
                            Panel-specific price overrides
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            Existing published rates are retained as history;
                            update them by publishing a new amount.
                        </p>
                    </div>
                    <div
                        v-if="canPublishPrices && addablePanels.length"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <div class="min-w-0 flex-1 basis-56">
                            <V2Picker
                                :model-value="selectedNewPanel || null"
                                label="Choose a Panel to add"
                                placeholder="Select a Panel"
                                :options="panelOptions"
                                required
                                @update:model-value="
                                    selectedNewPanel = $event ?? ''
                                "
                            />
                        </div>
                        <button
                            type="button"
                            :class="outlineButton"
                            :disabled="!selectedNewPanel"
                            aria-describedby="add-override-why"
                            @click="addPanelOverride"
                        >
                            Add override
                        </button>
                        <p
                            v-if="!selectedNewPanel"
                            id="add-override-why"
                            class="basis-full text-xs text-muted-foreground"
                        >
                            Choose a Panel first.
                        </p>
                    </div>
                    <div
                        v-if="form.prices.panel_overrides.length"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <V2MoneyInput
                            v-for="(override, index) in form.prices
                                .panel_overrides"
                            :key="override.panel_id"
                            v-model="override.amount_rm"
                            :label="`${panelName(override.panel_id)} price`"
                            :disabled="!canPublishPrices"
                            :error="
                                formError(
                                    `prices.panel_overrides.${index}.amount_sen`,
                                )
                            "
                        />
                    </div>
                    <p
                        v-else
                        class="rounded-[10px] bg-muted/30 p-3 text-sm text-muted-foreground"
                    >
                        No Panel-specific rates. Panel visits will use the
                        default Panel price.
                    </p>
                    <p
                        v-if="
                            form.errors.prices || form.errors.expected_versions
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            form.errors.prices || form.errors.expected_versions
                        }}
                    </p>
                </div>

                <div class="sm:max-w-sm">
                    <V2Picker
                        :model-value="form.expected_branch_id || null"
                        label="Branch for this price publication"
                        :selected-label="
                            branches.find(
                                (branch) =>
                                    String(branch.id) ===
                                    form.expected_branch_id,
                            )?.name
                        "
                        :options="branchOptions"
                        :disabled="!canPublishPrices"
                        :error="form.errors.expected_branch_id"
                        required
                        @update:model-value="
                            form.expected_branch_id = $event ?? ''
                        "
                    />
                </div>

                <p
                    v-if="savedNote && !form.hasErrors"
                    role="status"
                    class="rounded-[10px] border border-success/40 bg-success/10 px-3 py-2 text-sm"
                    data-test="tariff-saved"
                >
                    {{ savedNote }}
                </p>
                <p
                    v-if="form.hasErrors"
                    role="alert"
                    class="rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                    data-test="tariff-refused"
                >
                    Not saved. Nothing was published, and what you typed is
                    still here. Check the messages above, or reload if the rates
                    changed elsewhere.
                </p>
                <div
                    v-if="canPublishPrices"
                    class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
                >
                    <p class="max-w-2xl text-xs text-muted-foreground">
                        Saving publishes new immutable price versions. The
                        invoice flow reads these rates automatically; no
                        separate Pricing menu visit is needed.
                    </p>
                    <button
                        type="submit"
                        :class="outlineButton"
                        :disabled="saving || form.processing"
                    >
                        {{
                            saving || form.processing
                                ? 'Saving…'
                                : 'Save consultation tariffs'
                        }}
                    </button>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Your account can manage catalogue references but does not
                    have price-publishing authority. Ask an authorised pricing
                    user to save changes.
                </p>
            </form>
        </div>
    </V2DisclosureBar>
</template>
