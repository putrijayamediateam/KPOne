<script setup lang="ts">
// Version 2 location check card (Director only), HR V2 Attendance PR 2: the same form and request as Classic through
// useLocationCheckSettings. Off at every branch until turned on (the pilot branch stays off by the owner's decision).
// It reports unsaved changes; the page decides whether its Save is the filled one (Daniel: one at a time, top first).
import { computed, watch } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import { useLocationCheckSettings } from '@/composables/hr/useLocationCheckSettings';
import type { LocationCheckCard } from '../types';
import {
    button,
    inputClass,
    numberClass,
    primaryButton,
    textareaClass,
} from './classes';

const props = defineProps<{
    card: LocationCheckCard;
    primary: boolean;
    othersAbove: boolean;
}>();
const emit = defineEmits<{ dirty: [value: boolean] }>();

const { errors, form, branch, branchOptions, blockedBy, put } =
    useLocationCheckSettings(props);

// What the fields hold for the chosen branch before any typing (the composable fills them the same way).
const baseline = () => {
    const b = branch.value;

    return {
        centreLat: b?.centreLat ?? '',
        centreLng: b?.centreLng ?? '',
        radius: String(b?.radiusMetres ?? props.card.suggestedRadius),
        consentEn: b?.consentEn ?? props.card.draftConsentEn,
        consentBm: b?.consentBm ?? props.card.draftConsentBm,
    };
};
const snapshot = (v: ReturnType<typeof baseline>) =>
    JSON.stringify([
        v.centreLat,
        v.centreLng,
        v.radius,
        v.consentEn,
        v.consentBm,
    ]);
const dirty = computed(() => snapshot(form) !== snapshot(baseline()));
watch(dirty, (value) => emit('dirty', value), { immediate: true });
const reset = () => Object.assign(form, baseline());
defineExpose({ reset, dirty });
</script>

<template>
    <V2FormCard
        title="Location check at clock-in"
        description="Off at every branch until you turn it on, and on at one branch at a time. When it is on, a press there keeps only inside, outside or not available, with how accurate the reading was. Nobody's location is stored, and a refusal never stops a clock-in."
        data-test="v2-location-check"
    >
        <div class="flex flex-col gap-4">
            <p class="text-[13px] text-muted-foreground">
                Synthetic data only until the PDPA review. The consent text
                below is a draft for that review with the Data Protection
                Officer.
            </p>
            <ul class="flex flex-col text-sm" data-test="location-check-status">
                <li
                    v-for="b in card.branches"
                    :key="b.id"
                    class="flex min-h-11 items-center justify-between gap-2 border-b py-2 last:border-b-0"
                >
                    <span>{{ b.name }}</span>
                    <V2Chip
                        :tone="b.enabled ? 'info' : 'neutral'"
                        :label="
                            b.enabled
                                ? `On since ${b.since}, ${b.radiusMetres} m`
                                : 'Off'
                        "
                        class="text-nowrap!"
                    />
                </li>
            </ul>
            <form class="flex flex-col gap-4" @submit.prevent="put(true)">
                <div class="flex flex-col gap-1.5">
                    <label for="v2-location-branch" class="text-sm font-medium"
                        >Branch</label
                    >
                    <OperationalSelect
                        id="v2-location-branch"
                        v-model="form.branchId"
                        :options="branchOptions"
                        label="Branch"
                        placeholder="Choose a branch"
                    />
                    <p
                        v-if="errors.branch_id"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.branch_id }}
                    </p>
                </div>
                <p
                    v-if="blockedBy"
                    class="text-sm text-muted-foreground"
                    data-test="location-check-blocked"
                >
                    It is on at {{ blockedBy.name }}. Turn it off there first:
                    it runs at one branch at a time.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Branch latitude"
                        :error="errors.centre_lat"
                    >
                        <input
                            :id="id"
                            v-model="form.centreLat"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Branch longitude"
                        :error="errors.centre_lng"
                    >
                        <input
                            :id="id"
                            v-model="form.centreLng"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <p class="-mt-2 text-[13px] text-muted-foreground">
                    The branch's own point, filled in from its public location
                    where one is set. It is not anyone's location.
                </p>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Radius (metres)"
                    :hint="`From 25 to 2,000 m. KPOne suggests ${card.suggestedRadius} m; indoor readings can be rough.`"
                    :error="errors.radius_metres"
                >
                    <input
                        :id="id"
                        v-model="form.radius"
                        inputmode="numeric"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="numberClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Consent text (English)"
                    :error="errors.consent_en"
                >
                    <textarea
                        :id="id"
                        v-model="form.consentEn"
                        rows="11"
                        maxlength="2000"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="textareaClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Consent text (Bahasa Melayu)"
                    :error="errors.consent_bm"
                >
                    <textarea
                        :id="id"
                        v-model="form.consentBm"
                        rows="11"
                        maxlength="2000"
                        lang="ms"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="textareaClass"
                    />
                </V2Field>
                <p class="text-[13px] text-muted-foreground">
                    Saving a change asks staff to read the consent text again
                    before their next press.
                </p>
                <p
                    v-if="errors.enabled"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.enabled }}
                </p>
                <div class="flex flex-col gap-1.5">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button
                            type="submit"
                            :class="primary ? primaryButton : button"
                            :disabled="form.processing || !!blockedBy"
                            data-test="location-check-save"
                        >
                            {{
                                form.processing
                                    ? 'Saving…'
                                    : branch?.enabled
                                      ? 'Save changes'
                                      : 'Turn on at this branch'
                            }}
                        </button>
                        <button
                            v-if="branch?.enabled"
                            type="button"
                            :class="button"
                            data-test="location-check-off"
                            @click="form.confirmOff = true"
                        >
                            Turn off at {{ branch.name }}
                        </button>
                    </div>
                    <p
                        v-if="dirty && othersAbove"
                        class="text-[13px] text-muted-foreground"
                    >
                        You also have unsaved changes above.
                    </p>
                </div>
            </form>
        </div>
        <V2Dialog
            :open="form.confirmOff"
            :title="`Turn off the location check at ${branch?.name ?? 'this branch'}?`"
            description="Presses there stop keeping a location result. Earlier results stay on Review."
            confirm-label="Turn off"
            tone="danger"
            :busy="form.processing"
            @update:open="form.confirmOff = $event"
            @confirm="put(false)"
        />
    </V2FormCard>
</template>
