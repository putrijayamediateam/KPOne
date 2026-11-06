<script setup lang="ts">
// HR-3b "Ask for overtime" sheet. Shows worked beside rostered time and KPOne's day type; the minutes start at the most allowed.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ErrorState } from '@/components/ui/state';
import { Textarea } from '@/components/ui/textarea';
import type { Option, OvertimeChoice } from './types';

const props = defineProps<{
    choices: OvertimeChoice[];
    reasons: Option[];
    sessionId: string;
    person?: string;
    userId?: number;
    fromReview?: boolean;
    reviewQuery?: Record<string, string | number | undefined>;
}>();
const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const form = reactive({
    sessionId: '',
    minutes: '',
    reason: '',
    note: '',
    key: '',
    processing: false,
});
const choice = computed(
    () => props.choices.find((c) => c.sessionId === form.sessionId) ?? null,
);
const dayOptions = computed(() =>
    props.choices.map((c) => ({ value: c.sessionId, label: c.label })),
);
const maxLabel = (max: number) => {
    const h = Math.floor(max / 60);
    const m = max % 60;
    const parts = h === 0 ? `${m} min` : m === 0 ? `${h} h` : `${h} h ${m} min`;

    return `Most allowed: ${max} min (${parts})`;
};

watch(open, (value) => {
    if (value) {
        Object.assign(form, {
            sessionId: props.sessionId,
            minutes: String(
                props.choices.find((c) => c.sessionId === props.sessionId)
                    ?.max ?? '',
            ),
            reason: '',
            note: '',
            key: crypto.randomUUID(),
        });
    }
});
watch(
    () => form.sessionId,
    (id, old) => {
        if (old !== '' && id !== old) {
            form.minutes = String(choice.value?.max ?? '');
        }
    },
);

const send = () => {
    form.processing = true;
    router.post(
        '/hr/attendance/overtime',
        {
            tab: props.fromReview ? 'review' : 'my',
            ...(props.fromReview ? (props.reviewQuery ?? {}) : {}),
            user_id: props.fromReview ? props.userId : undefined,
            session_id: form.sessionId,
            minutes: form.minutes,
            reason_code: form.reason,
            note: form.note,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onFinish: () => (form.processing = false),
        },
    );
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full overflow-y-auto sm:max-w-md"
            data-test="overtime-sheet"
        >
            <SheetHeader>
                <SheetTitle>{{
                    person
                        ? `Ask for overtime for ${person}`
                        : 'Ask for overtime'
                }}</SheetTitle>
                <SheetDescription
                    >Someone else approves it. Minutes only: pay is worked out
                    later.</SheetDescription
                >
            </SheetHeader>
            <form class="grid gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="errors.overtime" :message="errors.overtime" />
                <InputError v-if="fromReview" :message="errors.user_id" />
                <div class="grid gap-1">
                    <Label for="overtime-day">Day *</Label>
                    <OperationalSelect
                        id="overtime-day"
                        v-model="form.sessionId"
                        :options="dayOptions"
                        label="Day"
                        placeholder="Choose a day"
                    />
                    <InputError :message="errors.session_id" />
                </div>
                <dl
                    v-if="choice"
                    class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border bg-muted/40 p-3 text-sm"
                    data-test="overtime-compare"
                >
                    <dt class="text-muted-foreground">Worked</dt>
                    <dd class="tabular-nums">{{ choice.worked }}</dd>
                    <dt class="text-muted-foreground">Rostered</dt>
                    <dd class="tabular-nums">
                        {{ choice.rostered ?? 'Not on the roster' }}
                    </dd>
                    <dt class="text-muted-foreground">Day</dt>
                    <dd>{{ choice.dayLabel }}</dd>
                </dl>
                <div class="grid gap-1">
                    <Label for="overtime-minutes">Minutes *</Label>
                    <Input
                        id="overtime-minutes"
                        v-model="form.minutes"
                        inputmode="numeric"
                        class="min-h-11 w-40 sm:min-h-9"
                        aria-describedby="overtime-minutes-help"
                    />
                    <p
                        id="overtime-minutes-help"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            choice
                                ? maxLabel(choice.max)
                                : 'Choose a day first.'
                        }}
                    </p>
                    <InputError :message="errors.minutes" />
                </div>
                <div class="grid gap-1">
                    <Label for="overtime-reason">Reason *</Label>
                    <OperationalSelect
                        id="overtime-reason"
                        v-model="form.reason"
                        :options="reasons"
                        label="Reason"
                        placeholder="Choose a reason"
                    />
                    <InputError :message="errors.reason_code" />
                </div>
                <div class="grid gap-1">
                    <Label for="overtime-note">Note</Label>
                    <Textarea
                        id="overtime-note"
                        v-model="form.note"
                        maxlength="200"
                        rows="3"
                        aria-describedby="overtime-note-help"
                    />
                    <p
                        id="overtime-note-help"
                        class="text-xs text-muted-foreground"
                    >
                        Do not write medical details here.
                    </p>
                    <InputError :message="errors.note" />
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 sm:min-h-9"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="min-h-11 sm:min-h-9"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Sending…' : 'Send for approval' }}
                    </Button>
                </div>
            </form>
        </SheetContent>
    </Sheet>
</template>
