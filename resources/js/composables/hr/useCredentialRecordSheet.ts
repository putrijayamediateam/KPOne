import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { CredentialTypeHead } from '@/components/hr/credentials/types';

/**
 * The add or renew sheet's state and actions (HR-5a), shared by Classic (`components/hr/credentials/RecordSheet.vue`) and
 * Version 2 (`pages/HR/V2/Credentials.vue`), so both send the same request with the same key. Moved out of the Classic
 * component unchanged; its template did not change.
 */
export type RecordSheetProps = {
    open: boolean;
    type: CredentialTypeHead | null;
    /** Where the record is sent: your own, or a person's from the register. */
    url: string;
    /** "Renew" when a version is already in force. */
    renewing: boolean;
    /** The person's name when a manager enters it for them. */
    person?: string | null;
};

export function useCredentialRecordSheet(
    props: RecordSheetProps,
    close: () => void,
) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const form = reactive({
        issuedOn: '',
        expiresOn: '',
        issuingBody: '',
        reference: '',
        health: '',
        file: null as File | null,
        key: '',
        processing: false,
    });
    watch(
        () => props.open,
        (open) => {
            if (open) {
                Object.assign(form, {
                    issuedOn: '',
                    expiresOn: '',
                    issuingBody: '',
                    reference: '',
                    health: '',
                    file: null,
                    key: crypto.randomUUID(),
                });
            }
        },
    );
    const healthOptions = [
        { value: 'done', label: 'Done' },
        { value: 'not_done', label: 'Not done' },
    ];
    const title = computed(() =>
        props.type
            ? `${props.renewing ? 'Renew' : 'Add'} ${props.type.name}`
            : 'Add a credential',
    );
    const onFile = (event: Event) => {
        form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    };
    const send = () => {
        if (!props.type) {
            return;
        }

        form.processing = true;
        const health = props.type.healthItem;
        router.post(
            props.url,
            {
                type_id: props.type.typeId,
                issued_on:
                    health && form.health !== 'done' ? '' : form.issuedOn,
                expires_on: props.type.expires ? form.expiresOn : '',
                issuing_body: health ? '' : form.issuingBody,
                reference: health ? '' : form.reference,
                health: health ? form.health : '',
                ...(form.file && !health ? { file: form.file } : {}),
                idempotency_key: form.key,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => close(),
                onFinish: () => (form.processing = false),
            },
        );
    };

    return { errors, form, healthOptions, title, onFile, send };
}
