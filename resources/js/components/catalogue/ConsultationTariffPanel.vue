<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CatalogueFormSectionHeading from '@/components/catalogue/CatalogueFormSectionHeading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';

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

const toSen = (amount: string) =>
    amount.trim() === '' ? null : Math.round(Number(amount) * 100);
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
const hasCharge = computed(() => props.tariff.chargeExists);
const addablePanels = computed(() => {
    const selected = new Set(
        form.prices.panel_overrides.map((override) => override.panel_id),
    );

    return props.panels.filter((panel) => !selected.has(String(panel.id)));
});

watch(
    () => props.tariff,
    (tariff) => {
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
    },
    { deep: true },
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

const submit = () => {
    saving.value = true;
    form.transform((data) => ({
        ...data,
        expected_branch_id: data.expected_branch_id || null,
        prices: {
            self_pay_sen: toSen(data.prices.self_pay_rm),
            panel_default_sen: toSen(data.prices.panel_default_rm),
            panel_overrides: data.prices.panel_overrides.map((override) => ({
                panel_id: Number(override.panel_id),
                amount_sen: toSen(override.amount_rm),
            })),
        },
    }));
    form.post('/clinical-services/consultation-tariffs', {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            form.transform((data) => data);
        },
    });
};
</script>

<template>
    <section
        class="grid gap-4 rounded-lg border bg-background p-4 shadow-sm sm:p-5"
    >
        <CatalogueFormSectionHeading
            number="01"
            title="Consultation tariff"
            description="These rates are used for the dedicated Consultation line on billing. This is not a separately ordered Clinical Service."
        />

        <div
            v-if="!canManagePrices"
            class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
        >
            You can view the Clinical Service Catalogue but do not have
            permission to manage consultation tariffs.
        </div>
        <form v-else class="grid gap-4" @submit.prevent="submit">
            <div
                v-if="!hasCharge"
                class="grid gap-3 rounded-md bg-muted/30 p-3 sm:grid-cols-2"
            >
                <label class="grid gap-1 text-xs">
                    Charge code
                    <Input v-model="form.code" maxlength="64" required />
                    <InputError :message="form.errors.code" />
                </label>
                <label class="grid gap-1 text-xs">
                    Charge name
                    <Input
                        v-model="form.display_name"
                        maxlength="500"
                        required
                    />
                    <InputError :message="form.errors.display_name" />
                </label>
            </div>
            <div
                v-else
                class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/30 p-3 text-sm"
            >
                <div>
                    <p class="font-medium">{{ tariff.displayName }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ tariff.code }} · Dedicated consultation charge
                    </p>
                </div>
                <span
                    class="rounded-full px-2 py-1 text-xs font-medium"
                    :class="
                        tariff.isActive
                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                            : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                    "
                >
                    {{ tariff.isActive ? 'Active' : 'Will reactivate on save' }}
                </span>
            </div>

            <div class="grid gap-2">
                <div>
                    <h4 class="text-sm font-medium">Default pricing</h4>
                    <p class="text-xs text-muted-foreground">
                        These organisation-wide prices are shared across
                        branches. Panel-specific rates can be set below.
                    </p>
                </div>
                <div class="overflow-hidden rounded-lg border">
                    <div
                        class="hidden grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] gap-3 bg-muted/60 px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:grid"
                    >
                        <span>Price tier</span>
                        <span>Coverage</span>
                        <span>Default price (RM)</span>
                    </div>
                    <div
                        class="grid gap-2 border-t p-3 sm:grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] sm:items-center"
                    >
                        <span class="font-medium">Self-pay</span>
                        <span class="text-xs text-muted-foreground"
                            >Patients paying directly</span
                        >
                        <div class="grid gap-1">
                            <Input
                                v-model="form.prices.self_pay_rm"
                                aria-label="Consultation self-pay price in RM"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!canPublishPrices"
                            />
                            <InputError
                                :message="formError('prices.self_pay_sen')"
                            />
                        </div>
                    </div>
                    <div
                        class="grid gap-2 border-t p-3 sm:grid-cols-[minmax(8rem,0.8fr)_minmax(12rem,1.5fr)_minmax(10rem,1fr)] sm:items-center"
                    >
                        <span class="font-medium">Panel</span>
                        <span class="text-xs text-muted-foreground"
                            >Default for Panels without a custom rate</span
                        >
                        <div class="grid gap-1">
                            <Input
                                v-model="form.prices.panel_default_rm"
                                aria-label="Consultation default Panel price in RM"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!canPublishPrices"
                            />
                            <InputError
                                :message="formError('prices.panel_default_sen')"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h4 class="text-sm font-medium">
                            Panel-specific price overrides
                        </h4>
                        <p class="text-xs text-muted-foreground">
                            Existing published rates are retained as history;
                            update them by publishing a new amount.
                        </p>
                    </div>
                    <div
                        v-if="canPublishPrices && addablePanels.length"
                        class="flex items-center gap-2"
                    >
                        <OperationalSelect
                            v-model="selectedNewPanel"
                            label="Choose Panel to add"
                            :options="[
                                { value: '', label: 'Select a Panel' },
                                ...addablePanels.map((panel) => ({
                                    value: String(panel.id),
                                    label: panel.name,
                                })),
                            ]"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!selectedNewPanel"
                            @click="addPanelOverride"
                        >
                            Add override
                        </Button>
                    </div>
                </div>
                <div
                    v-if="form.prices.panel_overrides.length"
                    class="overflow-hidden rounded-lg border"
                >
                    <div
                        class="hidden grid-cols-[minmax(12rem,1fr)_minmax(10rem,1fr)] gap-3 bg-muted/60 px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:grid"
                    >
                        <span>Panel</span>
                        <span>Price (RM)</span>
                    </div>
                    <div
                        v-for="(override, index) in form.prices.panel_overrides"
                        :key="override.panel_id"
                        class="grid gap-2 border-t p-3 sm:grid-cols-[minmax(12rem,1fr)_minmax(10rem,1fr)] sm:items-center"
                    >
                        <span class="font-medium">
                            {{
                                panels.find(
                                    (panel) =>
                                        String(panel.id) === override.panel_id,
                                )?.name ??
                                tariff.panelOverrides.find(
                                    (saved) =>
                                        saved.panelId === override.panel_id,
                                )?.panelName ??
                                `Panel ${override.panel_id}`
                            }}
                        </span>
                        <div class="grid gap-1">
                            <Input
                                v-model="override.amount_rm"
                                :aria-label="`Consultation tariff for Panel ${override.panel_id} in RM`"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!canPublishPrices"
                            />
                            <InputError
                                :message="
                                    formError(
                                        `prices.panel_overrides.${index}.amount_sen`,
                                    )
                                "
                            />
                        </div>
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-md bg-muted/30 p-3 text-sm text-muted-foreground"
                >
                    No Panel-specific rates. Panel visits will use the default
                    Panel price.
                </p>
                <InputError :message="form.errors.prices" />
                <InputError :message="form.errors.expected_versions" />
            </div>

            <div class="grid gap-2 sm:max-w-sm">
                <label class="grid gap-1 text-xs">
                    Branch for this price publication
                    <OperationalSelect
                        v-model="form.expected_branch_id"
                        label="Branch for price publication"
                        :options="
                            branches.map((branch) => ({
                                value: String(branch.id),
                                label: branch.name,
                            }))
                        "
                        :disabled="!canPublishPrices"
                    />
                    <InputError :message="form.errors.expected_branch_id" />
                </label>
            </div>

            <div
                v-if="canPublishPrices"
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-3"
            >
                <p class="max-w-2xl text-xs text-muted-foreground">
                    Saving publishes new immutable price versions. The invoice
                    flow reads these rates automatically; no separate Pricing
                    menu visit is needed.
                </p>
                <Button type="submit" :disabled="saving || form.processing">
                    {{
                        saving || form.processing
                            ? 'Saving…'
                            : 'Save consultation tariffs'
                    }}
                </Button>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                Your account can manage catalogue references but does not have
                price-publishing authority. Ask an authorised pricing user to
                save changes.
            </p>
        </form>
    </section>
</template>
