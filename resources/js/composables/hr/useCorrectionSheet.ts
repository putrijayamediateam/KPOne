import { router, usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, reactive, watch } from 'vue';
import type { Option } from '@/components/hr/records/types';

/**
 * HR-4a "Ask for a correction" form state (HR-PLAN 11.3 rule 6): the request and key for
 * `components/hr/records/CorrectionSheet.vue`.
 */
export type CorrectionSheetProps = {
    kind: string;
    kinds: Option[];
    reasons: Option[];
    /** What KPOne holds now, already worded ("Not given" when empty). */
    recorded: string;
};

export function useCorrectionSheet(
    props: CorrectionSheetProps,
    open: Ref<boolean>,
) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );

    const blank = () => ({
        value: '',
        identityKind: 'ic',
        identityNumber: '',
        passportCountry: '',
        bankName: '',
        holder: '',
        account: '',
        reason: '',
        note: '',
        key: '',
        processing: false,
    });
    const form = reactive(blank());
    watch(open, (value) => {
        if (value) {
            Object.assign(form, blank(), { key: crypto.randomUUID() });
        }
    });

    const kindLabel = computed(
        () => props.kinds.find((k) => k.value === props.kind)?.label ?? '',
    );
    const send = () => {
        form.processing = true;
        router.post(
            '/hr/records/my/corrections',
            {
                kind: props.kind,
                value: ['legal_name', 'nationality', 'date_of_birth'].includes(
                    props.kind,
                )
                    ? form.value
                    : undefined,
                identity_kind:
                    props.kind === 'identity' ? form.identityKind : undefined,
                identity_number:
                    props.kind === 'identity' ? form.identityNumber : undefined,
                passport_country:
                    props.kind === 'identity' &&
                    form.identityKind === 'passport'
                        ? form.passportCountry
                        : undefined,
                bank_name: props.kind === 'bank' ? form.bankName : undefined,
                holder: props.kind === 'bank' ? form.holder : undefined,
                account: props.kind === 'bank' ? form.account : undefined,
                reason: form.reason,
                note: form.note,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (open.value = false),
                onFinish: () => (form.processing = false),
            },
        );
    };

    return { errors, form, kindLabel, send };
}
