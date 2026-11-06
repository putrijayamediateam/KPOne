<script setup lang="ts">
// HR-4a "Ask for a correction" (HR-PLAN 11.3 rule 6), following the HR-3a sheet: "Recorded: … · New: …", a reason, the
// note warning and each error under its field.
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
import type { Option } from './types';

const props = defineProps<{
    kind: string;
    kinds: Option[];
    reasons: Option[];
    /** What KPOne holds now, already worded ("Not given" when empty). */
    recorded: string;
}>();
const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const blank = () => ({
    value: '',
    identityKind: 'ic',
    identityNumber: '',
    passportCountry: '',
    bankName: '',
    holder: '',
    account: '',
    reason: '',
    note: '',
    key: '',
    processing: false,
});
const form = reactive(blank());
watch(open, (value) => {
    if (value) {
        Object.assign(form, blank(), { key: crypto.randomUUID() });
    }
});

const kindLabel = computed(
    () => props.kinds.find((k) => k.value === props.kind)?.label ?? '',
);
const send = () => {
    form.processing = true;
    router.post(
        '/hr/records/my/corrections',
        {
            kind: props.kind,
            value: ['legal_name', 'nationality', 'date_of_birth'].includes(
                props.kind,
            )
                ? form.value
                : undefined,
            identity_kind:
                props.kind === 'identity' ? form.identityKind : undefined,
            identity_number:
                props.kind === 'identity' ? form.identityNumber : undefined,
            passport_country:
                props.kind === 'identity' && form.identityKind === 'passport'
                    ? form.passportCountry
                    : undefined,
            bank_name: props.kind === 'bank' ? form.bankName : undefined,
            holder: props.kind === 'bank' ? form.holder : undefined,
            account: props.kind === 'bank' ? form.account : undefined,
            reason: form.reason,
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
            class="w-full overflow-y-auto sm:max-w-md"
            data-test="correction-sheet"
        >
            <SheetHeader>
                <SheetTitle>Ask for a correction</SheetTitle>
                <SheetDescription>
                    {{ kindLabel }}. Someone in HR checks it and applies it.
                </SheetDescription>
            </SheetHeader>
            <form class="flex flex-col gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="errors.records" :message="errors.records" />
                <p class="text-sm" data-test="correction-compare">
                    Recorded: {{ recorded }}
                </p>

                <div
                    v-if="['legal_name', 'nationality'].includes(kind)"
                    class="grid gap-1.5"
                >
                    <Label for="correction-value"
                        >New {{ kindLabel.toLowerCase() }}</Label
                    >
                    <Input
                        id="correction-value"
                        v-model="form.value"
                        class="max-lg:h-11"
                        :aria-invalid="!!errors.value"
                        autocomplete="off"
                    />
                    <InputError :message="errors.value" />
                </div>
                <div v-else-if="kind === 'date_of_birth'" class="grid gap-1.5">
                    <Label for="correction-dob">New date of birth</Label>
                    <Input
                        id="correction-dob"
                        v-model="form.value"
                        type="date"
                        class="max-lg:h-11"
                        :aria-invalid="!!errors.date_of_birth"
                    />
                    <InputError :message="errors.date_of_birth" />
                </div>
                <template v-else-if="kind === 'identity'">
                    <div class="grid gap-1.5">
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="correction-identity-kind"
                            v-model="form.identityKind"
                            label="Identity document"
                            :options="[
                                { value: 'ic', label: 'IC (MyKad)' },
                                { value: 'passport', label: 'Passport' },
                            ]"
                        />
                        <InputError :message="errors.identity_kind" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="correction-identity-number">{{
                            form.identityKind === 'ic'
                                ? 'New IC number'
                                : 'New passport number'
                        }}</Label>
                        <Input
                            id="correction-identity-number"
                            v-model="form.identityNumber"
                            class="max-lg:h-11"
                            autocomplete="off"
                            :aria-invalid="!!errors.identity_number"
                        />
                        <InputError :message="errors.identity_number" />
                    </div>
                    <div
                        v-if="form.identityKind === 'passport'"
                        class="grid gap-1.5"
                    >
                        <Label for="correction-passport-country"
                            >Issuing country (2-letter code)</Label
                        >
                        <Input
                            id="correction-passport-country"
                            v-model="form.passportCountry"
                            maxlength="2"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.passport_country"
                        />
                        <InputError :message="errors.passport_country" />
                    </div>
                </template>
                <template v-else-if="kind === 'bank'">
                    <div class="grid gap-1.5">
                        <Label for="correction-bank-name">Bank name</Label>
                        <Input
                            id="correction-bank-name"
                            v-model="form.bankName"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.bank_name"
                        />
                        <InputError :message="errors.bank_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="correction-holder">Account holder</Label>
                        <Input
                            id="correction-holder"
                            v-model="form.holder"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.holder"
                        />
                        <InputError :message="errors.holder" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="correction-account">Account number</Label>
                        <Input
                            id="correction-account"
                            v-model="form.account"
                            inputmode="numeric"
                            autocomplete="off"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.account"
                        />
                        <InputError :message="errors.account" />
                    </div>
                </template>

                <div class="grid gap-1.5">
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="correction-reason"
                        v-model="form.reason"
                        label="Reason"
                        placeholder="Choose a reason"
                        :options="reasons"
                        :invalid="!!errors.reason"
                    />
                    <InputError :message="errors.reason" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="correction-note">Note (optional)</Label>
                    <Textarea
                        id="correction-note"
                        v-model="form.note"
                        maxlength="200"
                        aria-describedby="correction-note-help"
                        :aria-invalid="!!errors.note"
                    />
                    <p
                        id="correction-note-help"
                        class="text-xs text-muted-foreground"
                    >
                        Do not write medical details here.
                    </p>
                    <InputError :message="errors.note" />
                </div>
                <InputError :message="errors.kind" />
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="correction-send"
                >
                    {{ form.processing ? 'Sending…' : 'Send to HR' }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
