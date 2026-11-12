<script setup lang="ts">
import { nextTick, ref } from 'vue';
import V2PatientPickRow from './V2PatientPickRow.vue';

/**
 * Version 2 patient pick list: the recent-patients rows as ONE radio group. Tab enters the group on the selected
 * row (or the first); arrow keys move and select, Home and End jump, and Space or Enter selects.
 */
export type PickPatient = {
    value: string;
    name: string;
    kpNumber: string;
    maskedIdentifier?: string;
    dateOfBirth?: string;
};

const props = defineProps<{
    label: string;
    modelValue: string | null;
    patients: PickPatient[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const list = ref<HTMLElement | null>(null);

const tabStop = (index: number) =>
    props.modelValue === null
        ? index === 0
        : props.patients[index]?.value === props.modelValue;

const move = (event: KeyboardEvent, index: number) => {
    const last = props.patients.length - 1;
    const next =
        event.key === 'ArrowDown' || event.key === 'ArrowRight'
            ? (index + 1) % (last + 1)
            : event.key === 'ArrowUp' || event.key === 'ArrowLeft'
              ? (index - 1 + last + 1) % (last + 1)
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? last
                  : null;

    if (next === null || last < 0) {
        return;
    }

    event.preventDefault();
    emit('update:modelValue', props.patients[next].value);
    nextTick(() =>
        list.value
            ?.querySelectorAll<HTMLElement>('[role=radio]')
            [next]?.focus(),
    );
};
</script>

<template>
    <div
        ref="list"
        data-slot="v2-patient-pick-list"
        role="radiogroup"
        :aria-label="label"
        class="flex flex-col gap-1"
    >
        <V2PatientPickRow
            v-for="(patient, index) in patients"
            :key="patient.value"
            :name="patient.name"
            :kp-number="patient.kpNumber"
            :masked-identifier="patient.maskedIdentifier"
            :date-of-birth="patient.dateOfBirth"
            :selected="patient.value === modelValue"
            :tab-stop="tabStop(index)"
            @select="emit('update:modelValue', patient.value)"
            @keydown="move($event, index)"
        />
    </div>
</template>
