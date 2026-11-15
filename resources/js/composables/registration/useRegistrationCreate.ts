import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import type { GuardianInput } from '@/lib/guardian-contact';
import { knownMinor } from '@/lib/guardian-contact';
import {
    phoneError,
    identityError,
    focusInvalidField,
} from '@/lib/patient-registration';
import type { PatientRegistrationSummary, VisitOptions } from '@/types';

/**
 * Version 2 Register visit logic: a copy of the script of the Classic `pages/Registration/Create.vue` (which stays
 * untouched), so the V2 page posts the same `/registration` payload (`quick_patient`, idempotency key, Panel note),
 * runs the same patient search (`/patients/search`) and applies the same checks. A test compares the two.
 */
export type CreateInput = {
    options: VisitOptions & { idempotencyKey: string };
    recentPatients: PatientRegistrationSummary[];
    guardianToday?: string;
};

export function useRegistrationCreate(props: CreateInput) {
    const identityTypeOptions = [
        { value: 'nric', label: 'Malaysian IC' },
        { value: 'passport', label: 'Passport' },
    ];
    const genderOptions = [
        { value: 'unknown', label: 'Unknown' },
        { value: 'female', label: 'Female' },
        { value: 'male', label: 'Male' },
        { value: 'indeterminate', label: 'Indeterminate' },
    ];
    const doctorOptions = computed(() => [
        { value: '', label: 'No doctor' },
        ...props.options.doctors.map((doctor) => ({
            value: doctor.id,
            label: doctor.name,
        })),
    ]);
    const panelOptions = computed(() => [
        { value: '', label: 'Select Panel' },
        ...props.options.panels.map((panel) => ({
            value: panel.id,
            label: panel.name,
        })),
    ]);
    const patientQuery = ref('');
    const patientResults = ref<PatientRegistrationSummary[]>([
        ...props.recentPatients,
    ]);
    const activeResultIndex = ref(props.recentPatients.length ? 0 : -1);
    const patientSearchError = ref('');
    const searching = ref(false);
    const selectedPatient = ref<PatientRegistrationSummary | null>(null);
    const quickMode = ref(false);
    const quickIdentifierType = ref<'nric' | 'passport'>('nric');
    const quickIdentifierValue = ref('');
    const quickIssuer = ref('MY');
    type QuickPatientInput = {
        adult_confirmed: boolean;
        adult_confirmation_key: string;
        guardian?: GuardianInput | null;
        full_name: string;
        date_of_birth: string;
        nationality_code: string;
        email: string;
        address_line_1: string;
        address_line_2: string;
        city: string;
        state: string;
        postcode: string;
        country_code: string;
        sex: string;
        mobile_phone: string;
        phone_country: string;
        duplicate_override: boolean;
        identifiers: Array<{
            identifier_type: 'nric' | 'passport';
            issuing_country_code: string;
            value: string;
        }>;
    };
    type RegistrationForm = {
        idempotency_key: string;
        expected_branch_id: number;
        patient_number: string;
        quick_patient: QuickPatientInput | null;
        visit_type: 'consultation' | 'otc';
        assigned_doctor_user_id: number | '';
        visit_reason_public_ids: string[];
        coverage_type: 'self_pay' | 'panel';
        panel_id: number | '';
        coverage_member_reference: string;
        coverage_panel_note: string;
        panel_note_panel_id: number | '';
        priority: 'normal' | 'urgent';
        confirm_repeat: boolean;
    };
    const form = useForm<RegistrationForm>({
        idempotency_key: props.options.idempotencyKey,
        expected_branch_id: props.options.branch.id,
        patient_number: '',
        quick_patient: null,
        visit_type: 'consultation' as 'consultation' | 'otc',
        assigned_doctor_user_id:
            props.options.doctors.length === 1
                ? props.options.doctors[0].id
                : '',
        visit_reason_public_ids: [],
        coverage_type: 'self_pay' as 'self_pay' | 'panel',
        panel_id: '',
        coverage_member_reference: '',
        coverage_panel_note: '',
        panel_note_panel_id: '' as number | '',
        priority: 'normal' as 'normal' | 'urgent',
        confirm_repeat: false,
    });
    const quick = ref({
        adult_confirmed: false,
        adult_confirmation_key: crypto.randomUUID(),
        guardian: null as GuardianInput | null,
        full_name: '',
        date_of_birth: '',
        nationality_code: '',
        email: '',
        address_line_1: '',
        address_line_2: '',
        city: '',
        state: '',
        postcode: '',
        country_code: 'MY',
        sex: 'unknown',
        mobile_phone: '',
        phone_country: 'MY',
        duplicate_override: false,
    });
    const contactErrors = computed(() =>
        Object.fromEntries(
            Object.entries(form.errors)
                .filter(([key]) => key.startsWith('quick_patient.'))
                .map(([key, value]) => [
                    key.slice('quick_patient.'.length),
                    value,
                ]),
        ),
    );
    const selectedVisitReasons = ref<VisitReasonOption[]>([]);
    const csrf = () =>
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';
    let patientSearchTimer: ReturnType<typeof setTimeout> | undefined;
    let patientSearchController: AbortController | undefined;
    const showingRecent = computed(() => patientQuery.value.trim() === '');
    const patientSearchType = (query: string) => {
        const trimmed = query.trim();
        const compact = trimmed.replace(/[\s-]/g, '');

        if (trimmed.toUpperCase().startsWith('KP-')) {
            return 'patient_number';
        }

        if (/^\d{12}$/.test(compact)) {
            return 'nric';
        }

        if (/^\+?[()\d][()\d\s-]{6,}$/.test(trimmed)) {
            return 'phone';
        }

        return 'name';
    };
    const searchPatients = async () => {
        const query = patientQuery.value.trim();

        if (query.length < 3) {
            patientResults.value =
                query === '' ? [...props.recentPatients] : [];
            activeResultIndex.value = patientResults.value.length ? 0 : -1;

            return;
        }

        searching.value = true;
        patientSearchError.value = '';
        patientSearchController?.abort();
        const controller = new AbortController();
        patientSearchController = controller;

        try {
            const response = await fetch('/patients/search', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                signal: controller.signal,
                body: JSON.stringify({
                    query,
                    search_type: patientSearchType(query),
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                patientSearchError.value =
                    payload.message ?? 'Patient search could not be completed.';

                return;
            }

            patientResults.value = payload.data;
            activeResultIndex.value = patientResults.value.length ? 0 : -1;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            patientSearchError.value = 'Patient search could not be completed.';
        } finally {
            if (patientSearchController === controller) {
                searching.value = false;
                patientSearchController = undefined;
            }
        }
    };
    watch(patientQuery, (query) => {
        if (patientSearchTimer) {
            clearTimeout(patientSearchTimer);
        }

        patientSearchController?.abort();
        patientSearchController = undefined;
        searching.value = false;

        patientSearchError.value = '';
        const trimmed = query.trim();

        if (trimmed === '') {
            patientResults.value = [...props.recentPatients];
            activeResultIndex.value = patientResults.value.length ? 0 : -1;

            return;
        }

        patientResults.value = [];
        activeResultIndex.value = -1;

        if (trimmed.length >= 3) {
            patientSearchTimer = setTimeout(searchPatients, 150);
        }
    });
    watch(
        () => form.panel_id,
        (panelId) => {
            form.coverage_panel_note = '';
            form.panel_note_panel_id = panelId;
        },
    );
    watch(
        () => form.coverage_type,
        (coverage) => {
            if (coverage === 'self_pay') {
                form.panel_id = '';
                form.coverage_member_reference = '';
                form.coverage_panel_note = '';
                form.panel_note_panel_id = '';
            }
        },
    );
    onBeforeUnmount(() => {
        if (patientSearchTimer) {
            clearTimeout(patientSearchTimer);
        }

        patientSearchController?.abort();
    });
    const handlePatientSearchKeydown = (event: KeyboardEvent) => {
        if (!patientResults.value.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeResultIndex.value = Math.min(
                activeResultIndex.value + 1,
                patientResults.value.length - 1,
            );
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeResultIndex.value = Math.max(activeResultIndex.value - 1, 0);
        } else if (event.key === 'Enter' && activeResultIndex.value >= 0) {
            event.preventDefault();
            selectPatient(patientResults.value[activeResultIndex.value]);
        }
    };
    const selectPatient = (patient: PatientRegistrationSummary) => {
        selectedPatient.value = patient;
        form.patient_number = patient.patientNumber;
        quickMode.value = false;
        form.quick_patient = null;
        form.clearErrors('patient_number', 'quick_patient');
    };
    const changePatient = () => {
        selectedPatient.value = null;
        form.patient_number = '';
        quickMode.value = false;
        form.quick_patient = null;
        patientQuery.value = '';
        patientResults.value = [...props.recentPatients];
        activeResultIndex.value = patientResults.value.length ? 0 : -1;
        nextTick(() =>
            document
                .querySelector<HTMLInputElement>('#patient-search')
                ?.focus(),
        );
    };
    const startQuickCreate = () => {
        selectedPatient.value = null;
        form.patient_number = '';
        quickMode.value = true;
        form.clearErrors('patient_number', 'quick_patient');
        nextTick(() =>
            document
                .querySelector<HTMLInputElement>('#quick-patient-name')
                ?.focus(),
        );
    };
    const visitReady = computed(
        () => selectedPatient.value !== null || quickMode.value,
    );
    const patientSummaryName = computed(
        () =>
            selectedPatient.value?.fullName ||
            quick.value.full_name.trim() ||
            'New Patient',
    );
    const submit = () => {
        form.clearErrors();

        if (quickMode.value) {
            const missingName = !quick.value.full_name.trim();

            if (missingName) {
                form.setError(
                    'quick_patient.full_name',
                    'Please enter the Patient name.',
                );
            }

            const phone = phoneError(
                quick.value.mobile_phone,
                quick.value.phone_country,
                !knownMinor(quick.value.date_of_birth, props.guardianToday),
            );
            const identity = identityError(
                quickIdentifierType.value,
                quickIdentifierValue.value,
                quickIssuer.value,
            );

            if (phone) {
                form.setError('quick_patient.mobile_phone', phone);
            }

            if (identity) {
                form.setError('quick_patient.identifiers', identity);
            }

            if (missingName || phone || identity) {
                return focusInvalidField();
            }

            form.quick_patient = {
                ...quick.value,
                identifiers: quickIdentifierValue.value.trim()
                    ? [
                          {
                              identifier_type: quickIdentifierType.value,
                              issuing_country_code:
                                  quickIdentifierType.value === 'nric'
                                      ? 'MY'
                                      : quickIssuer.value,
                              value: quickIdentifierValue.value,
                          },
                      ]
                    : [],
            };
            form.patient_number = '';
        }

        form.post('/registration', {
            preserveState: true,
            onError: focusInvalidField,
        });
    };
    const errorFor = (key: string) =>
        (form.errors as Record<string, string>)[key];

    return {
        identityTypeOptions,
        genderOptions,
        doctorOptions,
        panelOptions,
        patientQuery,
        patientResults,
        activeResultIndex,
        patientSearchError,
        searching,
        selectedPatient,
        quickMode,
        quickIdentifierType,
        quickIdentifierValue,
        quickIssuer,
        form,
        quick,
        contactErrors,
        selectedVisitReasons,
        showingRecent,
        searchPatients,
        handlePatientSearchKeydown,
        selectPatient,
        changePatient,
        startQuickCreate,
        visitReady,
        patientSummaryName,
        submit,
        errorFor,
    };
}
