<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import V2PhoneField from '@/components/registration-v2/V2PhoneField.vue';
import { DateField } from '@/components/ui/date-field';
import { OperationalSelect } from '@/components/ui/select';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import { blankGuardian, knownMinor } from '@/lib/guardian-contact';
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

const fields = reactive(props.model);
const err = (key: string) => props.errors[key];

const genderOptions = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'indeterminate', label: 'Indeterminate' },
    { value: 'unknown', label: 'Unknown' },
];
const relationshipOptions = [
    { value: '', label: 'Choose a relationship' },
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
                <V2Field
                    :label="
                        newPatient && requirements?.requireDateOfBirth
                            ? 'Date of birth *'
                            : 'Date of birth'
                    "
                    :error="err('date_of_birth')"
                >
                    <template #default="{ id }">
                        <DateField
                            :id="id"
                            v-model="fields.date_of_birth"
                            :required="
                                newPatient && requirements?.requireDateOfBirth
                            "
                            :aria-invalid="!!err('date_of_birth')"
                        />
                    </template>
                </V2Field>
                <V2Field
                    :label="
                        newPatient && requirements?.requireNationality
                            ? 'Nationality (2-letter code) *'
                            : 'Nationality (2-letter code)'
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
                            >Date of birth unknown. I confirm the patient is an
                            adult (18 or older).</span
                        >
                    </label>
                    <p class="text-sm text-muted-foreground">
                        If you are unsure or the patient is a minor, enter a
                        date of birth. The guardian rule will then apply.
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
                <V2Field
                    label="Relationship"
                    :error="err('guardian.relationship')"
                >
                    <template #default>
                        <OperationalSelect
                            v-model="fields.guardian.relationship"
                            label="Relationship"
                            :options="relationshipOptions"
                        />
                    </template>
                </V2Field>
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
                <V2Field
                    label="Phone country (2-letter code)"
                    :error="err('guardian.phone_country')"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.guardian.phone_country"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            maxlength="2"
                            autocomplete="off"
                            :class="[inputClass, 'uppercase']"
                        />
                    </template>
                </V2Field>
                <V2Field label="Guardian phone" :error="err('guardian.phone')">
                    <template #default="{ id, describedBy, invalid }">
                        <input
                            :id="id"
                            v-model="fields.guardian.phone"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            type="tel"
                            maxlength="64"
                            autocomplete="off"
                            :class="inputClass"
                        />
                    </template>
                </V2Field>
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
