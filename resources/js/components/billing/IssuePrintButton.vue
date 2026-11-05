<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * BIL-5: Print records the print on the server (ORIGINAL, REVISED or COPY) and then opens the frozen copy in this tab.
 */
const props = withDefaults(
    defineProps<{
        url: string;
        label: string;
        returnTo?: 'billing' | 'payments' | 'invoice' | 'history';
        variant?: 'secondary' | 'ghost' | 'outline';
        size?: 'sm' | 'default';
    }>(),
    { returnTo: 'billing', variant: 'secondary', size: 'sm' },
);

const page = usePage();
const busy = ref(false);
const sent = ref(false);
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const error = computed(() =>
    sent.value
        ? (errors.value.document ??
          errors.value.expected_branch_id ??
          errors.value.idempotency_key)
        : undefined,
);

const issue = () => {
    busy.value = true;
    sent.value = true;
    router.post(
        props.url,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            idempotency_key: crypto.randomUUID(),
            return_to: props.returnTo,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                sent.value = false;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="grid gap-1">
        <Button
            :variant="variant"
            :size="size"
            class="h-11 justify-self-start sm:h-8"
            :disabled="busy"
            data-testid="issue-print"
            @click="issue"
        >
            <Printer class="size-4" aria-hidden="true" />
            <span aria-live="polite">{{ busy ? 'Issuing…' : label }}</span>
        </Button>
        <InputError
            v-if="error"
            :message="`Could not issue the copy. Nothing was printed or recorded. ${error}`"
        />
    </div>
</template>
