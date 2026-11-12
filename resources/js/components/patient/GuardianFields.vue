<script setup lang="ts">
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { blankGuardian, knownMinor } from '@/lib/guardian-contact';
import type { GuardianInput } from '@/lib/guardian-contact';
const props = defineProps<{
    dob: string;
    today?: string;
    newPatient?: boolean;
    originalDob?: string;
    errors: Record<string, string | undefined>;
}>();
const guardian = defineModel<GuardianInput | null | undefined>();
const minor = computed(() => knownMinor(props.dob, props.today));
watch(
    () => props.dob,
    () => {
        if (
            minor.value &&
            !guardian.value &&
            (props.newPatient || props.dob !== props.originalDob)
        ) {
            guardian.value = blankGuardian();
        }

        if (!minor.value && guardian.value?.lock_version === 0) {
            guardian.value = null;
        }
    },
    { immediate: true },
);
watch(
    () =>
        guardian.value
            ? [
                  guardian.value.relationship,
                  guardian.value.full_name,
                  guardian.value.phone,
                  guardian.value.phone_country,
                  guardian.value.email,
              ]
            : null,
    () => {
        if (guardian.value) {
            guardian.value.retry_key = crypto.randomUUID();
        }
    },
);
</script>
<template>
    <section
        v-if="minor || guardian"
        class="space-y-4 rounded-xl border bg-card p-5"
        data-guardian-fields
    >
        <h2 class="text-base font-semibold">
            Guardian contact{{ minor ? ' (required)' : '' }}
        </h2>
        <p class="text-sm text-muted-foreground">
            Stored only for this Patient. This number is not used for patient
            search or WhatsApp.
        </p>
        <button
            v-if="!guardian"
            type="button"
            class="h-9 rounded-[10px] border px-3 max-lg:h-11"
            @click="guardian = blankGuardian()"
        >
            Add guardian contact
        </button>
        <template v-else>
            <label class="grid gap-2"
                >Relationship
                <select
                    v-model="guardian.relationship"
                    class="h-9 w-full rounded-[10px] border bg-background px-3 max-lg:h-11"
                >
                    <option value="">Select relationship</option>
                    <option value="mother">Mother</option>
                    <option value="father">Father</option>
                    <option value="legal_guardian">Legal guardian</option>
                    <option value="other_family_member">
                        Other family member
                    </option></select
                ><InputError :message="errors['guardian.relationship']" />
            </label>
            <label class="grid gap-2"
                >Guardian full name<input
                    v-model="guardian.full_name"
                    maxlength="255"
                    class="h-9 min-w-0 rounded-[10px] border bg-background px-3 max-lg:h-11" /><InputError
                    :message="errors['guardian.full_name']"
            /></label>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="grid gap-2"
                    >Guardian phone country code<input
                        v-model="guardian.phone_country"
                        maxlength="2"
                        class="h-9 min-w-0 rounded-[10px] border bg-background px-3 max-lg:h-11" /><InputError
                        :message="errors['guardian.phone_country']"
                /></label>
                <label class="grid gap-2"
                    >Guardian phone<input
                        v-model="guardian.phone"
                        type="tel"
                        maxlength="64"
                        class="h-9 min-w-0 rounded-[10px] border bg-background px-3 max-lg:h-11" /><InputError
                        :message="errors['guardian.phone']"
                /></label>
            </div>
            <label class="grid gap-2"
                >Guardian email (optional)<input
                    v-model="guardian.email"
                    type="email"
                    maxlength="255"
                    class="h-9 min-w-0 rounded-[10px] border bg-background px-3 max-lg:h-11" /><InputError
                    :message="errors['guardian.email']"
            /></label>
        </template>
        <InputError
            :message="
                errors.guardian ||
                errors['guardian.lock_version'] ||
                errors['guardian.retry_key']
            "
        />
    </section>
</template>
