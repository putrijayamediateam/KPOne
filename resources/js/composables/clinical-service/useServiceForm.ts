import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import { rmToSen } from '@/lib/money';

/**
 * Version 2 Clinical Service Catalogue: the Add and Edit form's state and rules. A copy of the Classic page's script
 * (`pages/ClinicalService/Index.vue`, which stays as it is): the same fields, the same
 * `GET /clinical-services/{id}/setup`, the same `POST` and `PATCH` payloads and the same money conversion.
 */
export type ServiceRow = {
    publicId: string;
    code: string;
    displayName: string;
    orderUnit: string;
    category?: string | null;
    selfPayAmountRm: string | null;
    panelDefaultAmountRm: string | null;
    isActive: boolean;
};

export type SetupOptions = {
    canManagePrices: boolean;
    canPublishPrices: boolean;
    activeBranchId: number | null;
    branches: { id: number; name: string }[];
    panels: { id: number; name: string }[];
    serviceCategories: string[];
};

export type ConsultationTariff = {
    chargeExists: boolean;
    code: string;
    displayName: string;
    isActive: boolean;
    selfPayAmountRm: string | null;
    selfPayVersion: number;
    panelDefaultAmountRm: string | null;
    panelDefaultVersion: number;
    panelOverrides: {
        panelId: string;
        panelName: string;
        amountRm: string | null;
        version: number;
    }[];
};

export function useServiceForm(setup: SetupOptions) {
    const formOpen = ref(false);
    const editing = ref<ServiceRow | null>(null);
    const editLoading = ref(false);
    const editLoadError = ref('');
    const form = useForm({
        code: '',
        display_name: '',
        order_unit: '',
        category: '',
        expected_branch_id: setup.activeBranchId
            ? String(setup.activeBranchId)
            : '',
        prices: {
            self_pay_rm: '',
            panel_default_rm: '',
            panel_overrides: [] as { panel_id: string; amount_rm: string }[],
        },
    });
    // CAT-11: a fixed list of categories. A category saved before the list stays as its own option, so editing other
    // fields of an older service never changes it.
    const categoryOptions = computed(() => {
        const options = [
            { value: '', label: 'No category' },
            ...setup.serviceCategories.map((name) => ({
                value: name,
                label: name,
            })),
        ];
        const current = form.category;

        if (current !== '' && !setup.serviceCategories.includes(current)) {
            options.push({
                value: current,
                label: `${current} (saved before the fixed list)`,
            });
        }

        return options;
    });

    const openCreate = () => {
        editing.value = null;
        editLoadError.value = '';
        editLoading.value = false;
        form.reset();
        form.clearErrors();
        form.expected_branch_id = setup.activeBranchId
            ? String(setup.activeBranchId)
            : '';
        formOpen.value = true;
        snapshot();
    };
    const openEdit = async (row: ServiceRow) => {
        editing.value = row;
        editLoadError.value = '';
        editLoading.value = true;
        formOpen.value = true;
        form.reset();
        form.clearErrors();
        form.code = row.code;
        form.display_name = row.displayName;
        form.order_unit = row.orderUnit;
        form.category = row.category ?? '';

        try {
            const details = await requestJson<{
                service: Record<string, string>;
                prices: {
                    self_pay_rm: string | null;
                    panel_default_rm: string | null;
                    panel_overrides: {
                        panel_id: string;
                        panel_name: string;
                        amount_rm: string;
                    }[];
                };
            }>(`/clinical-services/${row.publicId}/setup`);
            Object.assign(form, details.service);
            form.prices.self_pay_rm = details.prices.self_pay_rm ?? '';
            form.prices.panel_default_rm =
                details.prices.panel_default_rm ?? '';
            form.prices.panel_overrides = details.prices.panel_overrides.map(
                ({ panel_id, amount_rm }) => ({ panel_id, amount_rm }),
            );
            form.expected_branch_id = setup.activeBranchId
                ? String(setup.activeBranchId)
                : '';
            editing.value = {
                ...row,
                displayName: details.service.display_name ?? row.displayName,
            };
            snapshot();
        } catch (error) {
            editLoadError.value =
                error instanceof JsonRequestError
                    ? 'Clinical Service details could not be loaded. Refresh the page and try again.'
                    : 'Clinical Service details could not be loaded. Check your connection and try again.';
        } finally {
            editLoading.value = false;
        }
    };
    const formError = (key: string) =>
        form.errors[key as keyof typeof form.errors];
    const submit = () => {
        form.transform((data) => ({
            ...data,
            expected_branch_id: data.expected_branch_id || null,
            ...(setup.canPublishPrices
                ? {
                      prices: {
                          self_pay_sen: rmToSen(data.prices.self_pay_rm),
                          panel_default_sen: rmToSen(
                              data.prices.panel_default_rm,
                          ),
                          panel_overrides: data.prices.panel_overrides.map(
                              (override) => ({
                                  panel_id: override.panel_id,
                                  amount_sen: rmToSen(override.amount_rm),
                              }),
                          ),
                      },
                  }
                : {}),
        }));

        if (editing.value) {
            form.patch(`/clinical-services/${editing.value.publicId}`, {
                preserveScroll: true,
                onSuccess: () => {
                    formOpen.value = false;
                    form.transform((data) => data);
                },
            });
        } else {
            form.post('/clinical-services', {
                preserveScroll: true,
                onSuccess: () => {
                    formOpen.value = false;
                    form.transform((data) => data);
                },
            });
        }
    };

    const baseline = ref('');
    const snapshot = () => {
        baseline.value = JSON.stringify(form.data());
    };
    const isDirty = computed(
        () => formOpen.value && JSON.stringify(form.data()) !== baseline.value,
    );

    return {
        formOpen,
        editing,
        editLoading,
        editLoadError,
        form,
        categoryOptions,
        openCreate,
        openEdit,
        formError,
        submit,
        isDirty,
    };
}
