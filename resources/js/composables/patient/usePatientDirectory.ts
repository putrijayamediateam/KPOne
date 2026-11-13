import { reactive, ref } from 'vue';

/**
 * Version 2 Patient directory search. A copy of the Classic logic in `pages/Patient/Index.vue`: the same
 * `POST /patients/search` request and body (`query`, `search_type`, `issuing_country_code`, `page`), the same CSRF
 * header and the same error messages. Nothing here decides a permission.
 */
export type PatientResult = {
    canWhatsapp: boolean;
    patientNumber: string;
    fullName: string;
    dateOfBirth: string | null;
    sex: string;
    identifier: { type: string; maskedValue: string } | null;
    maskedPhone: string | null;
    mergedFrom?: string[];
};

export const PATIENT_SEARCH_TYPES = [
    { value: 'name', label: 'Name' },
    { value: 'patient_number', label: 'Patient number' },
    { value: 'nric', label: 'NRIC' },
    { value: 'passport', label: 'Passport' },
    { value: 'phone', label: 'Phone' },
];

export const patientHref = (number: string) =>
    '/patients/' + encodeURIComponent(number);

// Sentence case for staff; the same word the search type select on this page uses for the Malaysian IC.
const IDENTIFIER_NAMES: Record<string, string> = {
    nric: 'NRIC',
    passport: 'Passport',
};

export const identifierLabel = (result: PatientResult) =>
    result.identifier
        ? (IDENTIFIER_NAMES[result.identifier.type] ??
              result.identifier.type.charAt(0).toUpperCase() +
                  result.identifier.type.slice(1)) +
          ' · ' +
          result.identifier.maskedValue
        : 'None recorded';

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

export const usePatientDirectory = () => {
    const form = reactive({
        query: '',
        search_type: 'name',
        issuing_country_code: '',
    });
    const results = ref<PatientResult[]>([]);
    const total = ref(0);
    const currentPage = ref(1);
    const lastPage = ref(1);
    const perPage = ref(20);
    const error = ref('');
    const loading = ref(false);
    const searched = ref(false);

    const search = async (page = 1) => {
        if (loading.value) {
            return;
        }

        loading.value = true;
        error.value = '';

        try {
            const response = await fetch('/patients/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ ...form, page }),
            });
            const payload = await response.json();

            if (!response.ok) {
                const validationErrors = payload.errors as
                    Record<string, string[]> | undefined;
                error.value =
                    payload.message ??
                    (validationErrors
                        ? Object.values(validationErrors)[0]?.[0]
                        : undefined) ??
                    'Search could not be completed.';

                return;
            }

            results.value = payload.data;
            total.value = payload.total;
            currentPage.value = payload.currentPage;
            lastPage.value = payload.lastPage;
            perPage.value = payload.perPage ?? perPage.value;
            searched.value = true;
        } catch {
            error.value = 'Search could not be completed.';
        } finally {
            loading.value = false;
        }
    };

    return {
        form,
        results,
        total,
        currentPage,
        lastPage,
        error,
        loading,
        searched,
        perPage,
        search,
    };
};
