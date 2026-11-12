<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import OperationalSelect from '@/components/ui/select/OperationalSelect.vue';
import { Textarea } from '@/components/ui/textarea';
import { getInitials } from '@/composables/useInitials';

/**
 * UX-18: "Waiting for a second person" (GUIDE section 18, two-person approvals; Daniel's rules 2026-11-12).
 *
 * A look and a caller only. It sends the caller's own payload to an EXISTING endpoint; every rule (who may decide,
 * the person who recorded it never deciding, lock versions, idempotency) stays in the domain services, and the
 * server's answer is shown as it comes back. `canDecide` and `blockedReason` come from the server and only choose
 * what to show: the server still refuses a decision it does not allow.
 *
 * Approve is outline and Reject is destructive-outline, never two filled buttons side by side. Reject asks for its
 * required reason in a dialog: a reason code list (`reject.reasons`) or free text.
 */
type Method = 'post' | 'put' | 'patch';
type Action = {
    url: string;
    method?: Method;
    data?: Record<string, unknown>;
};

const props = withDefaults(
    defineProps<{
        title: string;
        recordedBy: string;
        recordedAt?: string;
        /** Who may decide, in words, for the text and the empty circle ("a Finance Officer or Director"). */
        neededFrom?: string;
        canDecide: boolean;
        /** Server text shown instead of the buttons when the viewer may not decide. */
        blockedReason?: string;
        approve: Action;
        reject: Action & {
            /** The request field the existing endpoint reads the reason from, e.g. `reason_code`. */
            reasonField: string;
            reasons?: Array<{ value: string; label: string }>;
            reasonLabel?: string;
            maxLength?: number;
        };
    }>(),
    {
        recordedAt: undefined,
        neededFrom: undefined,
        blockedReason: undefined,
    },
);

// Several panels can share a page, so every id is unique per panel.
const uid = useId();
const titleId = `approval-panel-title-${uid}`;
const reasonId = `approval-panel-reason-${uid}`;
const reasonErrorId = `approval-panel-reason-error-${uid}`;

const busy = ref<'approve' | 'reject' | null>(null);
const errors = ref<Record<string, string>>({});
const rejectOpen = ref(false);
const reason = ref<string | number>('');

const firstError = computed(() => Object.values(errors.value)[0] ?? '');
const reasonError = computed(
    () => errors.value[props.reject.reasonField] ?? '',
);
/** The full sentence: the visible lines say the same in two parts, and the avatar stack reads it out. */
const recorderText = computed(
    () =>
        `Recorded by ${props.recordedBy}${props.recordedAt ? `, ${props.recordedAt}` : ''}. Needs a second person${props.neededFrom ? ` (${props.neededFrom})` : ''}.`,
);
const recordedLine = computed(
    () =>
        `Recorded by ${props.recordedBy}${props.recordedAt ? `, ${props.recordedAt}` : ''}.`,
);

const send = (
    which: 'approve' | 'reject',
    action: Action,
    extra: Record<string, unknown> = {},
) => {
    busy.value = which;
    errors.value = {};
    router.visit(action.url, {
        method: action.method ?? 'post',
        data: { ...(action.data ?? {}), ...extra } as RequestPayload,
        preserveScroll: true,
        onError: (bag) => {
            errors.value = bag;
        },
        onSuccess: () => {
            rejectOpen.value = false;
            reason.value = '';
        },
        onFinish: () => {
            busy.value = null;
        },
    });
};

const approve = () => send('approve', props.approve);

const openReject = () => {
    errors.value = {};
    reason.value = '';
    rejectOpen.value = true;
};

const reasonGiven = computed(() => String(reason.value).trim() !== '');

const confirmReject = () => {
    if (!reasonGiven.value) {
        errors.value = {
            [props.reject.reasonField]: 'Give a reason to reject.',
        };

        return;
    }

    send('reject', props.reject, {
        [props.reject.reasonField]:
            typeof reason.value === 'string'
                ? reason.value.trim()
                : reason.value,
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
        :aria-labelledby="titleId"
        data-test="approval-panel"
    >
        <div class="flex items-start gap-3">
            <div
                class="flex shrink-0 -space-x-2"
                role="img"
                :aria-label="recorderText"
            >
                <span
                    class="relative z-10 flex size-8 items-center justify-center rounded-full border-2 border-card bg-secondary text-xs font-semibold text-foreground"
                    aria-hidden="true"
                    >{{ getInitials(recordedBy) }}</span
                >
                <span
                    class="size-8 rounded-full border-2 border-dashed border-muted-foreground/60 bg-card"
                    aria-hidden="true"
                    data-test="approval-panel-empty"
                />
            </div>
            <div class="min-w-0 flex-1">
                <p
                    class="text-xs font-medium text-muted-foreground"
                    data-test="approval-panel-heading"
                >
                    Waiting for a second person
                </p>
                <h3
                    :id="titleId"
                    class="text-[15px] font-semibold text-foreground"
                >
                    {{ title }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ recordedLine
                    }}<template v-if="neededFrom">
                        Needs {{ neededFrom }}.</template
                    >
                </p>
            </div>
        </div>

        <div v-if="$slots.default" class="text-sm">
            <slot />
        </div>

        <div
            v-if="canDecide"
            class="flex flex-col gap-2 sm:flex-row sm:justify-end"
        >
            <Button
                variant="outline"
                class="max-sm:h-11 max-sm:w-full"
                :disabled="busy !== null"
                data-test="approval-panel-approve"
                @click="approve"
            >
                {{ busy === 'approve' ? 'Approving…' : 'Approve' }}
            </Button>
            <Button
                variant="outline"
                class="border-destructive/50 text-destructive hover:border-destructive hover:bg-destructive/10 hover:text-destructive max-sm:h-11 max-sm:w-full"
                :disabled="busy !== null"
                data-test="approval-panel-reject"
                @click="openReject"
            >
                Reject…
            </Button>
        </div>
        <p
            v-else
            class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
            data-test="approval-panel-blocked"
        >
            {{
                blockedReason ??
                'You cannot decide this one. Someone else must approve it.'
            }}
        </p>

        <InputError
            v-if="!rejectOpen && firstError"
            :message="firstError"
            data-test="approval-panel-error"
        />

        <Dialog v-model:open="rejectOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Reject: {{ title }}</DialogTitle>
                    <DialogDescription>
                        A reason is required. {{ recordedBy }} will see that it
                        was rejected.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-2"
                    novalidate
                    @submit.prevent="confirmReject"
                >
                    <Label :for="reasonId">{{
                        reject.reasonLabel ?? 'Reason'
                    }}</Label>
                    <OperationalSelect
                        v-if="reject.reasons?.length"
                        :id="reasonId"
                        v-model="reason"
                        :options="reject.reasons"
                        :label="reject.reasonLabel ?? 'Reason'"
                        placeholder="Choose a reason"
                        :invalid="reasonError !== ''"
                        :describedby="reasonErrorId"
                        trigger-class="max-sm:h-11"
                    />
                    <Textarea
                        v-else
                        :id="reasonId"
                        v-model="reason as string"
                        rows="3"
                        :maxlength="reject.maxLength ?? 500"
                        :aria-invalid="reasonError !== '' || undefined"
                        aria-:describedby="reasonErrorId"
                    />
                    <InputError
                        :id="reasonErrorId"
                        :message="reasonError || (rejectOpen ? firstError : '')"
                    />
                    <DialogFooter class="mt-2 gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            class="max-sm:h-11"
                            @click="rejectOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="outline"
                            class="border-destructive/50 text-destructive hover:border-destructive hover:bg-destructive/10 hover:text-destructive max-sm:h-11"
                            :disabled="busy !== null"
                            data-test="approval-panel-reject-confirm"
                        >
                            {{ busy === 'reject' ? 'Rejecting…' : 'Reject' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
