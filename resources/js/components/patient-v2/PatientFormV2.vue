<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import V2PhoneField from '@/components/registration-v2/V2PhoneField.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import { blankGuardian, knownMinor } from '@/lib/guardian-contact';
import { ageOn } from '@/lib/patient-v2';
import { formatDate } from '@/lib/presentation';
import type { PatientFormValues } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

/**
 * Version 2 patient form (Edit patient now, Create patient next). A copy of the Classic fields in
 * `components/patient/PatientFormFields.vue` and `GuardianFields.vue` (both untouched) on V2 blocks: the same model
 * keys, the same required rules (`newPatient` plus the registration settings), the same minor and guardian rules,
 * the same adult-confirmation tick. The page owns the request.
 */
const props = defineProps<{
    model: PatientFormValues;
    newPatient?: boolean;
    requirements?: RegistrationRequirements;
    originalPhone?: string;
    originalDob?: string;
    guardianToday?: string;
    hasAddress?: boolean;
    errors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{
    clear: [key: string];
    // true while the date of birth box holds text it could not read (the page must not save then).
    dobInvalid: [value: boolean];
}>();
const fields = reactive(props.model);

// A plain sentence for the "required" refusals; the server's own words for anything else (a duplicate, a stale
// record). `Enter ...` for typed fields, `Choose ...` for a choice.
const PLAIN_REQUIRED: Record<string, string> = {
    full_name: 'Enter the full name.',
    date_of_birth: 'Enter the date of birth.',
    sex: 'Choose a gender.',
    nationality_code: 'Enter the 2-letter country code.',
    mobile_phone: 'Enter a phone number.',
    email: 'Enter an email address.',
    address_line_1: 'Enter address line 1.',
    postcode: 'Enter the postcode.',
    city: 'Enter the city.',
    state: 'Enter the state.',
    country_code: 'Enter the 2-letter country code.',
    'guardian.relationship': 'Choose the guardian relationship.',
    'guardian.full_name': 'Enter the guardian full name.',
    'guardian.phone': 'Enter the guardian phone number.',
};
const err = (key: string) => {
    const message = props.errors[key];

    return message && /\b(is|are) required\b|field is required/i.test(message)
        ? (PLAIN_REQUIRED[key] ?? message)
        : message;
};

// An error goes away as soon as its field is edited.
for (const key of [
    'full_name',
    'date_of_birth',
    'sex',
    'nationality_code',
    'mobile_phone',
    'phone_country',
    'email',
    'address_line_1',
    'address_line_2',
    'postcode',
    'city',
    'state',
    'country_code',
    'adult_confirmed',
] as const) {
    watch(
        () => fields[key],
        () => emit('clear', key),
    );
}

watch(
    () =>
        JSON.stringify(
            fields.guardian ? { ...fields.guardian, retry_key: '' } : null,
        ),
    () => emit('clear', 'guardian'),
);

const genderOptions = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'indeterminate', label: 'Indeterminate' },
    { value: 'unknown', label: 'Unknown' },
];
const relationshipChoices = [
    { value: 'mother', label: 'Mother' },
    { value: 'father', label: 'Father' },
    { value: 'legal_guardian', label: 'Legal guardian' },
    { value: 'other_family_member', label: 'Other family member' },
];

// ---- Guardian (same rules as Classic GuardianFields) ----
const minor = computed(() =>
    knownMinor(fields.date_of_birth, props.guardianToday),
);
watch(
    () => fields.date_of_birth,
    () => {
        if (
            minor.value &&
            !fields.guardian &&
            (props.newPatient || fields.date_of_birth !== props.originalDob)
        ) {
            fields.guardian = blankGuardian();
        }

        if (!minor.value && fields.guardian?.lock_version === 0) {
            fields.guardian = null;
        }
    },
    { immediate: true },
);
watch(
    () =>
        fields.guardian
            ? [
                  fields.guardian.relationship,
                  fields.guardian.full_name,
                  fields.guardian.phone,
                  fields.guardian.phone_country,
                  fields.guardian.email,
              ]
            : null,
    () => {
        if (fields.guardian) {
            fields.guardian.retry_key = crypto.randomUUID();
        }
    },
);

// The date of birth decides minor or adult, so it is shown in words under the box and follows the Guardian card.
const dobWords = computed(() => {
    const age = ageOn(fields.date_of_birth, props.guardianToday);

    if (age === null) {
        return 'Date of birth not entered.';
    }

    return `Born ${formatDate(fields.date_of_birth)} · age ${age}${
        minor.value
            ? ' · a guardian contact is required'
            : ' · no guardian contact is needed'
    }`;
});
const showAdultConfirmation = computed(
    () =>
        !fields.date_of_birth &&
        (props.newPatient || !!props.originalDob) &&
        !props.requirements?.requireDateOfBirth,
);
const addressRequired = computed(
    () => !!props.newPatient && !!props.requirements?.requireAddress,
);
const addressOpen = ref(false);
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive md:text-[15px] lg:h-9';
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <V2FormCard
            title="Identity"
            description="Record the stated identity. A number read from an NRIC is never taken as proof."
        >
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <V2Field
                        label="Full name"
                        required
                        :error="err('full_name')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="fields.full_name"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                autocomplete="off"
                                :class="inputClass"
                            />
                        </template>
                    </V2Field>
                </div>
                <V2DateField
                    v-model="fields.date_of_birth"
                    label="Date of birth"
                    subject="Date of birth"
                    :hint="dobWords"
                    :error="err('date_of_birth')"
                    :required="
                        !!newPatient && !!requirements?.requireDateOfBirth
                    "
                    :max="guardianToday"
                    @update:invalid="
                        (value: boolean) => emit('dobInvalid', value)
                    "
                />
                <V2Field
                    :label="
                        newPatient && requirements?.requireNationality
                            ? 'Nationality (2-letter country code) *'
                            : 'Nationality (2-letter country code)'
                    "
                    hint="For example MY"
                    :error="err('nationality_code')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.nationality_code"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            maxlength="2"
                            autocomplete="off"
                            :class="[inputClass, 'uppercase']"
                        />
                    </template>
                </V2Field>
                <div class="md:col-span-2">
                    <V2OptionGroup
                        v-model="fields.sex"
                        label="Gender"
                        :options="genderOptions"
                        :error="err('sex')"
                    />
                </div>
                <div
                    v-if="showAdultConfirmation"
                    class="flex min-w-0 flex-col gap-2 rounded-[10px] border p-3 md:col-span-2"
                >
                    <label
                        class="flex min-h-11 cursor-pointer items-start gap-3 text-sm"
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center"
                        >
                            <input
                                v-model="fields.adult_confirmed"
                                type="checkbox"
                                class="size-5"
                            />
                        </span>
                        <span class="py-3"
                            >I confirm this patient is an adult. This is
                            recorded.</span
                        >
                    </label>
                    <p class="text-sm text-muted-foreground">
                        The date of birth is not entered. If you are not sure,
                        or the patient may be a minor, enter a date of birth
                        instead; a guardian contact is then required for a
                        minor.
                    </p>
                    <p
                        v-if="
                            err('adult_confirmed') ||
                            err('adult_confirmation_key')
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            err('adult_confirmed') ||
                            err('adult_confirmation_key')
                        }}
                    </p>
                </div>
            </div>
        </V2FormCard>

        <V2FormCard
            v-if="minor || fields.guardian"
            :title="minor ? 'Guardian contact (required)' : 'Guardian contact'"
            description="Stored only for this patient. This number is not used for patient search or WhatsApp."
        >
            <button
                v-if="!fields.guardian"
                type="button"
                class="inline-flex h-11 items-center justify-center self-start rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                @click="fields.guardian = blankGuardian()"
            >
                Add guardian contact
            </button>
            <div v-else class="grid min-w-0 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <V2OptionGroup
                        v-model="fields.guardian.relationship"
                        label="Relationship"
                        :options="relationshipChoices"
                        :error="err('guardian.relationship')"
                    />
                </div>
                <div class="md:col-span-2">
                    <V2Field
                        label="Guardian full name"
                        :error="err('guardian.full_name')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="fields.guardian.full_name"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                maxlength="255"
                                autocomplete="off"
                                :class="inputClass"
                            />
                        </template>
                    </V2Field>
                </div>
                <div class="md:col-span-2">
                    <V2PhoneField
                        id="guardian-phone"
                        v-model="fields.guardian.phone"
                        v-model:country="fields.guardian.phone_country"
                        label="Guardian phone"
                        empty-label="Choose a country"
                        :error="
                            err('guardian.phone') ||
                            err('guardian.phone_country')
                        "
                    />
                </div>
                <div class="md:col-span-2">
                    <V2Field
                        label="Guardian email (optional)"
                        :error="err('guardian.email')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="fields.guardian.email"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                type="email"
                                maxlength="255"
                                autocomplete="off"
                                :class="inputClass"
                            />
                        </template>
                    </V2Field>
                </div>
            </div>
            <p
                v-if="
                    err('guardian') ||
                    err('guardian.lock_version') ||
                    err('guardian.retry_key')
                "
                role="alert"
                class="text-sm text-destructive"
            >
                {{
                    err('guardian') ||
                    err('guardian.lock_version') ||
                    err('guardian.retry_key')
                }}
            </p>
        </V2FormCard>

        <V2FormCard title="Contact">
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                <V2PhoneField
                    id="patient-phone"
                    v-model="fields.mobile_phone"
                    v-model:country="fields.phone_country"
                    :required="
                        newPatient &&
                        !knownMinor(fields.date_of_birth, guardianToday)
                    "
                    :unchanged-value="originalPhone"
                    :error="err('mobile_phone') || err('phone_country')"
                />
                <V2Field
                    :label="
                        newPatient && requirements?.requireEmail
                            ? 'Email *'
                            : 'Email'
                    "
                    :error="err('email')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.email"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :required="newPatient && requirements?.requireEmail"
                            type="email"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </template>
                </V2Field>
            </div>
        </V2FormCard>

        <V2DisclosureBar
            v-if="!hasAddress && !addressRequired && !addressOpen"
            title="Address (optional)"
        >
            <button
                type="button"
                class="m-4 inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                @click="addressOpen = true"
            >
                Add an address
            </button>
        </V2DisclosureBar>
        <V2FormCard
            v-if="hasAddress || addressRequired || addressOpen"
            title="Address"
        >
            <div class="grid min-w-0 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <V2Field
                        :label="
                            addressRequired
                                ? 'Address line 1 *'
                                : 'Address line 1'
                        "
                        :error="err('address_line_1')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="fields.address_line_1"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                :required="addressRequired"
                                autocomplete="off"
                                :class="inputClass"
                            />
                        </template>
                    </V2Field>
                </div>
                <div class="md:col-span-2">
                    <V2Field
                        label="Address line 2"
                        :error="err('address_line_2')"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <input
                                :id="id"
                                v-model="fields.address_line_2"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                autocomplete="off"
                                :class="inputClass"
                            />
                        </template>
                    </V2Field>
                </div>
                <V2Field
                    :label="addressRequired ? 'Postcode *' : 'Postcode'"
                    :error="err('postcode')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.postcode"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :required="addressRequired"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </template>
                </V2Field>
                <V2Field
                    :label="addressRequired ? 'City *' : 'City'"
                    :error="err('city')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.city"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :required="addressRequired"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </template>
                </V2Field>
                <V2Field
                    :label="addressRequired ? 'State *' : 'State'"
                    :error="err('state')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.state"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :required="addressRequired"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </template>
                </V2Field>
                <V2Field
                    :label="
                        addressRequired
                            ? 'Country (2-letter code) *'
                            : 'Country (2-letter code)'
                    "
                    hint="For example MY"
                    :error="err('country_code')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.country_code"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :required="addressRequired"
                            maxlength="2"
                            autocomplete="off"
                            :class="[inputClass, 'uppercase']"
                        />
                    </template>
                </V2Field>
            </div>
        </V2FormCard>
    </div>
</template>
