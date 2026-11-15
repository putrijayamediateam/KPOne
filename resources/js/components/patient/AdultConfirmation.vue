<script setup lang="ts">
import InputError from '@/components/InputError.vue';
defineProps<{ error?: string }>();
const confirmed = defineModel<boolean>({ default: false });
</script>

<template>
    <div class="space-y-2 rounded-md border p-3 md:col-span-2">
        <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm">
            <span class="flex size-11 shrink-0 items-center justify-center">
                <input v-model="confirmed" type="checkbox" class="size-5" />
            </span>
            <span class="py-3"
                >Date of birth unknown. I confirm the patient is an adult (18 or
                older).</span
            >
        </label>
        <p class="text-sm text-muted-foreground">
            If you are unsure or the patient is a minor, enter a date of birth.
            The existing guardian rule will apply.
        </p>
        <InputError :message="error" />
    </div>
</template>
