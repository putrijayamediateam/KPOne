import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { MedicineRow } from '@/composables/medicine/useMedicineForm';

/**
 * Version 2 Medicine Catalogue: the row actions (activate or deactivate, controlled mark, drug groups, other names).
 * A copy of the Classic page's script: the same routes, the same payloads, the same stale-list handling.
 */
export function useMedicineRowActions() {
    const pendingToggle = reactive<{ row: MedicineRow | null }>({ row: null });
    const toggleBusy = ref(false);
    const requestToggle = (row: MedicineRow) => {
        pendingToggle.row = row;
    };
    const confirmToggle = () => {
        const row = pendingToggle.row;

        if (!row) {
            return;
        }

        toggleBusy.value = true;
        router.post(
            `/medicines/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    toggleBusy.value = false;
                    pendingToggle.row = null;
                },
            },
        );
    };
    // DSP-3: the controlled-medicine flag, printed as a marker on the drug label.
    const pendingControlled = reactive<{ row: MedicineRow | null }>({
        row: null,
    });
    const controlledBusy = ref(false);
    const confirmControlled = () => {
        const row = pendingControlled.row;

        if (!row) {
            return;
        }

        controlledBusy.value = true;
        router.post(
            `/medicines/${row.publicId}/controlled`,
            {
                is_controlled: !row.isControlled,
                expected_is_controlled: row.isControlled,
            },
            {
                preserveScroll: true,
                // A refusal (for example someone else changed it first) must be visible, not silent.
                onError: (errors: Record<string, string>) => {
                    toast.error(
                        errors.is_controlled ??
                            'Could not change the controlled medicine mark. Reload and try again.',
                    );
                },
                onFinish: () => {
                    controlledBusy.value = false;
                    pendingControlled.row = null;
                },
            },
        );
    };
    const controlledDescription = computed(() => {
        const row = pendingControlled.row;

        if (!row) {
            return '';
        }

        return row.isControlled
            ? `${row.displayName} will no longer show "Ubat Terkawal/Controlled Medicine" below the label border on new drug labels or on reprints of earlier labels. At Dispensary, a returned ${row.displayName} can then be checked into the patient returns location instead of being disposed of only. Its stock will stop being listed in the controlled register. The change is recorded.`
            : `New drug labels for ${row.displayName}, and reprints of earlier labels, will show "Ubat Terkawal/Controlled Medicine" below the label border. At Dispensary, a returned ${row.displayName} can only be disposed of and is never put back into stock. Its stock will be listed in the controlled register. The change is recorded.`;
    });
    // DSP-1b: drug groups the Dispensary allergy warning matches against (one per line).
    const groupsRow = ref<MedicineRow | null>(null);
    const groupsText = ref('');
    const groupsBusy = ref(false);
    const groupsError = ref('');
    const openGroups = (row: MedicineRow) => {
        groupsRow.value = row;
        groupsText.value = row.allergyGroups.join('\n');
        groupsError.value = '';
    };
    const saveGroups = () => {
        const row = groupsRow.value;

        if (!row) {
            return;
        }

        groupsBusy.value = true;
        router.post(
            `/medicines/${row.publicId}/allergy-groups`,
            {
                allergy_groups: groupsText.value
                    .split('\n')
                    .map((group) => group.trim())
                    .filter((group) => group !== ''),
                expected_allergy_groups: row.allergyGroups,
            },
            {
                preserveScroll: true,
                onSuccess: () => (groupsRow.value = null),
                onError: (errors: Record<string, string>) => {
                    const message =
                        errors.allergy_groups ??
                        'Could not save the drug groups. Reload and try again.';

                    // DD-37: a list changed meanwhile closes, so the next attempt starts from the reloaded list.
                    if (message.startsWith('This medicine changed.')) {
                        groupsRow.value = null;
                        toast.error(message);

                        return;
                    }

                    // Anything else (too short, too many): keep the dialog and what was typed.
                    groupsError.value = message;
                },
                onFinish: () => (groupsBusy.value = false),
            },
        );
    };
    // DSP-1c: other names (brand names, common misspellings) the Dispensary allergy warning matches against.
    const aliasesRow = ref<MedicineRow | null>(null);
    const aliasesText = ref('');
    const aliasesBusy = ref(false);
    const aliasesError = ref('');
    const openAliases = (row: MedicineRow) => {
        aliasesRow.value = row;
        aliasesText.value = row.allergyAliases.join('\n');
        aliasesError.value = '';
    };
    const saveAliases = () => {
        const row = aliasesRow.value;

        if (!row) {
            return;
        }

        aliasesBusy.value = true;
        router.post(
            `/medicines/${row.publicId}/allergy-aliases`,
            {
                allergy_aliases: aliasesText.value
                    .split('\n')
                    .map((alias) => alias.trim())
                    .filter((alias) => alias !== ''),
                expected_allergy_aliases: row.allergyAliases,
            },
            {
                preserveScroll: true,
                onSuccess: () => (aliasesRow.value = null),
                onError: (errors: Record<string, string>) => {
                    const message =
                        errors.allergy_aliases ??
                        'Could not save the other names. Reload and try again.';

                    // A list changed meanwhile: close, so the next attempt starts from the reloaded list.
                    if (message.startsWith('This medicine changed.')) {
                        aliasesRow.value = null;
                        toast.error(message);

                        return;
                    }

                    // Anything else (too short, too many): keep the dialog and what was typed.
                    aliasesError.value = message;
                },
                onFinish: () => (aliasesBusy.value = false),
            },
        );
    };
    const toggleDescription = computed(() => {
        const row = pendingToggle.row;

        if (!row) {
            return '';
        }

        return row.isActive
            ? `${row.displayName} will no longer be available to order on a Treatment Plan.`
            : `${row.displayName} will become available to order on a Treatment Plan again.`;
    });

    return {
        pendingToggle,
        toggleBusy,
        requestToggle,
        confirmToggle,
        pendingControlled,
        controlledBusy,
        confirmControlled,
        controlledDescription,
        groupsRow,
        groupsText,
        groupsBusy,
        groupsError,
        openGroups,
        saveGroups,
        aliasesRow,
        aliasesText,
        aliasesBusy,
        aliasesError,
        openAliases,
        saveAliases,
        toggleDescription,
    };
}
