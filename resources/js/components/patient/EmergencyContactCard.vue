<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/patient/PhoneInput.vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

export type EmergencyContact = {
    values: { full_name: string; relationship: string; phone: string } | null;
    lock_version: number;
};
export type EmergencyContactCommand = {
    operation: 'set' | 'clear';
    lock_version: number;
    idempotency_key: string;
    full_name?: string;
    relationship?: string;
    phone?: string;
    phone_country?: string;
};
const props = withDefaults(
    defineProps<{
        patientNumber?: string;
        initial?: EmergencyContact;
        canEdit: boolean;
        registration?: boolean;
        idempotencyKey?: string;
        branchId: number;
        errors?: Record<string, string>;
    }>(),
    { registration: false },
);
const emit = defineEmits<{
    'update:modelValue': [value: EmergencyContactCommand | null];
    pending: [value: boolean];
}>();
const id = useId();
const contact = ref<EmergencyContact>(
    props.initial ?? { values: null, lock_version: 0 },
);
const editing = ref(false);
const loading = ref(false);
const processing = ref(false);
const message = ref('');
const staged = ref(false);
const removeOpen = ref(false);
const localErrors = ref<Record<string, string>>({});
const draft = ref({
    full_name: '',
    relationship: '',
    phone: '',
    phone_country: 'MY',
});
const requestKey = ref(crypto.randomUUID());
let loadSequence = 0;
const endpoint = () =>
    `/patients/${encodeURIComponent(props.patientNumber ?? '')}/emergency-contact`;
const fieldError = (name: string) =>
    localErrors.value[name] ??
    props.errors?.[`emergency_contact_command.${name}`] ??
    '';
const globalError = computed(
    () =>
        message.value ||
        fieldError('lock_version') ||
        props.errors?.emergency_contact_command ||
        Object.entries(props.errors ?? {}).find(([key]) =>
            key.startsWith('emergency_contact_command.'),
        )?.[1] ||
        '',
);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
watch(
    () => [props.patientNumber, props.branchId],
    async () => {
        const sequence = ++loadSequence;
        emit('update:modelValue', null);
        emit('pending', false);
        editing.value = false;
        staged.value = false;
        message.value = '';
        localErrors.value = {};
        contact.value = props.initial ?? { values: null, lock_version: 0 };

        if (!props.patientNumber || props.initial) {
            loading.value = false;

            return;
        }

        loading.value = true;

        try {
            const response = await fetch(endpoint(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(
                    'Unable to load the emergency contact. Reload before editing.',
                );
            }

            const value = await response.json();

            if (sequence === loadSequence) {
                contact.value = value;
            }
        } catch {
            if (sequence === loadSequence) {
                message.value =
                    'Unable to load the emergency contact. Reload before editing.';
            }
        } finally {
            if (sequence === loadSequence) {
                loading.value = false;
            }
        }
    },
    { immediate: true },
);
const edit = () => {
    emit('pending', true);
    draft.value = {
        full_name: contact.value.values?.full_name ?? '',
        relationship: contact.value.values?.relationship ?? '',
        phone: contact.value.values?.phone ?? '',
        phone_country: 'MY',
    };
    requestKey.value = crypto.randomUUID();
    localErrors.value = {};
    editing.value = true;
};
const cancel = () => {
    emit('pending', false);
    editing.value = false;
    localErrors.value = {};
};
const persist = async (command: EmergencyContactCommand) => {
    if (props.registration) {
        emit('update:modelValue', command);
        emit('pending', false);
        staged.value = true;
        editing.value = false;
        removeOpen.value = false;
        contact.value = {
            values:
                command.operation === 'clear'
                    ? null
                    : {
                          full_name: command.full_name!,
                          relationship: command.relationship!,
                          phone: command.phone!,
                      },
            lock_version: command.lock_version,
        };

        return;
    }

    processing.value = true;
    message.value = '';
    localErrors.value = {};

    try {
        const response = await fetch(endpoint(), {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                expected_branch_id: props.branchId,
                emergency_contact_command: command,
            }),
        });
        const body = await response.json();

        if (!response.ok) {
            if (body.errors) {
                for (const [key, value] of Object.entries(body.errors)) {
                    localErrors.value[
                        key.replace('emergency_contact_command.', '')
                    ] = Array.isArray(value) ? String(value[0]) : String(value);
                }
            }

            message.value =
                response.status === 422
                    ? 'Review the emergency contact fields. If the record changed, reload before saving.'
                    : 'The emergency contact could not be saved. Reload and try again.';

            return;
        }

        contact.value = body;
        editing.value = false;
        removeOpen.value = false;
    } catch {
        message.value = 'The emergency contact could not be saved. Try again.';
    } finally {
        processing.value = false;
    }
};
const save = () => {
    localErrors.value = {};

    for (const field of ['full_name', 'relationship', 'phone'] as const) {
        if (!draft.value[field].trim()) {
            localErrors.value[field] =
                'Complete all three emergency contact fields.';
        }
    }

    if (Object.keys(localErrors.value).length) {
        return;
    }

    void persist({
        operation: 'set',
        lock_version: contact.value.lock_version,
        idempotency_key: props.idempotencyKey ?? requestKey.value,
        ...draft.value,
    });
};
const remove = () =>
    void persist({
        operation: 'clear',
        lock_version: contact.value.lock_version,
        idempotency_key: props.idempotencyKey ?? requestKey.value,
    });
</script>

<template>
    <section
        class="space-y-3 rounded-lg border bg-card p-4"
        :aria-labelledby="`${id}-title`"
        tabindex="-1"
        data-test="emergency-contact"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 :id="`${id}-title`" class="font-semibold">
                Emergency contact
                <span class="text-sm font-normal text-muted-foreground"
                    >(optional)</span
                >
            </h2>
            <Button
                v-if="canEdit && !editing && !loading && !message"
                type="button"
                variant="outline"
                @click="edit"
                >{{ contact.values ? 'Edit contact' : 'Add contact' }}</Button
            >
        </div>
        <p class="text-sm text-muted-foreground">
            If you add a contact, provide their name, relationship and phone.
            Used only to reach someone in an emergency.
        </p>
        <p v-if="registration" class="text-sm text-muted-foreground">
            Contact changes are saved with this registration.
        </p>
        <p v-if="loading" role="status" class="text-sm text-muted-foreground">
            Loading emergency contact…
        </p>
        <template v-else-if="!editing">
            <div v-if="contact.values" class="space-y-1 text-sm break-words">
                <p class="font-medium">{{ contact.values.full_name }}</p>
                <p>{{ contact.values.relationship }}</p>
                <p>{{ contact.values.phone }}</p>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                No emergency contact recorded.
            </p>
            <p
                v-if="staged"
                role="status"
                class="text-sm text-muted-foreground"
            >
                Change prepared. Complete registration to save it.
            </p>
        </template>
        <div v-if="editing" class="space-y-3">
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="grid gap-1">
                    <label :for="`${id}-name`" class="text-sm font-medium"
                        >Name *</label
                    >
                    <input
                        :id="`${id}-name`"
                        v-model="draft.full_name"
                        maxlength="200"
                        class="h-11 min-w-0 rounded-md border bg-background px-3 text-sm"
                        :aria-invalid="!!fieldError('full_name')"
                        :aria-describedby="
                            fieldError('full_name')
                                ? `${id}-name-error`
                                : undefined
                        "
                    />
                    <InputError
                        :id="`${id}-name-error`"
                        :message="fieldError('full_name')"
                    />
                </div>
                <div class="grid gap-1">
                    <label
                        :for="`${id}-relationship`"
                        class="text-sm font-medium"
                        >Relationship *</label
                    >
                    <input
                        :id="`${id}-relationship`"
                        v-model="draft.relationship"
                        maxlength="80"
                        class="h-11 min-w-0 rounded-md border bg-background px-3 text-sm"
                        :aria-invalid="!!fieldError('relationship')"
                        :aria-describedby="
                            fieldError('relationship')
                                ? `${id}-relationship-error`
                                : undefined
                        "
                    />
                    <InputError
                        :id="`${id}-relationship-error`"
                        :message="fieldError('relationship')"
                    />
                </div>
                <div
                    class="lg:col-span-2 [&_input]:min-h-11 [&_select]:min-h-11"
                >
                    <PhoneInput
                        :id="`${id}-phone`"
                        v-model="draft.phone"
                        v-model:country="draft.phone_country"
                        required
                        :error="fieldError('phone')"
                    />
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="save"
                    >{{
                        processing
                            ? 'Saving…'
                            : registration
                              ? 'Save contact with registration'
                              : 'Save contact'
                    }}</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="processing"
                    @click="cancel"
                    >Cancel</Button
                >
                <Button
                    v-if="contact.values"
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="removeOpen = true"
                    >Remove contact</Button
                >
            </div>
        </div>
        <InputError :message="globalError" />
        <OperationalConfirmDialog
            v-model:open="removeOpen"
            title="Remove emergency contact?"
            :description="
                registration
                    ? 'This removes the contact from the patient record when you complete registration.'
                    : 'This removes the contact from the patient record. It does not remove guardian details.'
            "
            confirm-label="Remove contact"
            :processing="processing"
            :error="globalError"
            destructive
            @confirm="remove"
        />
    </section>
</template>
