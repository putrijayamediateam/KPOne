<script setup lang="ts">
// HR-6 New claim (HR-PLAN 13.2 and 13.6): category, date spent, amount in RM, what it was for and receipts, for the
// active branch. "Send a new claim" after a Wrong amount rejection copies the purpose in, and the server copies that
// claim's receipts. Errors show under each field, all in one submit.
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
import { myr } from '@/types/billing';
import type { CategoryOption, ClaimRow } from './types';

const props = defineProps<{
    open: boolean;
    categories: CategoryOption[];
    branch: string | null;
    today: string;
    maxReceipts: number;
    /** The claim rejected for Wrong amount that is being sent again, if any. */
    from: ClaimRow | null;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const form = reactive({
    categoryId: '',
    spentOn: '',
    amount: '',
    purpose: '',
    files: [] as File[],
    key: '',
    processing: false,
});
watch(
    () => props.open,
    (open) => {
        if (open) {
            Object.assign(form, {
                categoryId:
                    props.categories.find(
                        (c) => c.label === props.from?.category,
                    )?.value ?? '',
                spentOn: '',
                amount: '',
                purpose: props.from?.purpose ?? '',
                files: [],
                key: crypto.randomUUID(),
            });
        }
    },
);
const category = computed(() =>
    props.categories.find((c) => c.value === form.categoryId),
);
const copied = computed(() => props.from?.receipts.length ?? 0);
const room = computed(() => props.maxReceipts - copied.value);
const receiptError = computed(() => {
    const keyed = Object.entries(errors.value).find(
        ([k, v]) => k.startsWith('receipts.') && v,
    );

    return errors.value.receipts ?? keyed?.[1];
});
const onFiles = (event: Event) => {
    const input = event.target as HTMLInputElement;
    form.files = [...form.files, ...Array.from(input.files ?? [])].slice(
        0,
        room.value,
    );
    input.value = '';
};
const removeFile = (index: number) => {
    form.files = form.files.filter((_, i) => i !== index);
};
const send = () => {
    form.processing = true;
    router.post(
        '/hr/claims',
        {
            category_id: form.categoryId,
            spent_on: form.spentOn,
            amount: form.amount,
            purpose: form.purpose,
            ...(props.from ? { copy_from: props.from.id } : {}),
            ...(form.files.length ? { receipts: form.files } : {}),
            idempotency_key: form.key,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onFinish: () => (form.processing = false),
        },
    );
};
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-md"
            data-test="claim-sheet"
        >
            <SheetHeader>
                <SheetTitle>{{
                    from ? `Send a new claim for ${from.number}` : 'New claim'
                }}</SheetTitle>
                <SheetDescription>
                    Claim back what you paid for the clinic<template
                        v-if="branch"
                    >
                        at {{ branch }}</template
                    >. Someone else approves it. KPOne pays nothing; HR or
                    Finance pays it outside KPOne.
                </SheetDescription>
            </SheetHeader>
            <form class="flex flex-col gap-4 px-4 pb-6" @submit.prevent="send">
                <ErrorState v-if="errors.claims" :message="errors.claims" />
                <div class="grid gap-1.5">
                    <Label for="claim-category"
                        ><span id="claim-category-label"
                            >Category *</span
                        ></Label
                    >
                    <OperationalSelect
                        id="claim-category"
                        v-model="form.categoryId"
                        labelledby="claim-category-label"
                        label="Category"
                        placeholder="Choose a category"
                        trigger-class="max-lg:h-11"
                        :options="categories"
                        :invalid="!!errors.category_id"
                    />
                    <p
                        v-if="category"
                        class="text-xs text-muted-foreground"
                        data-test="claim-category-rule"
                    >
                        {{
                            category.needsReceipt
                                ? 'Needs a receipt.'
                                : 'A receipt is optional.'
                        }}<template v-if="category.perClaimLimitSen">
                            Limit {{ myr(category.perClaimLimitSen) }} a
                            claim.</template
                        ><template v-if="category.monthlyLimitSen">
                            {{ myr(category.monthlyLimitSen) }} a
                            month.</template
                        >
                    </p>
                    <InputError :message="errors.category_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="claim-date">Date you paid *</Label>
                    <Input
                        id="claim-date"
                        v-model="form.spentOn"
                        type="date"
                        :max="today"
                        class="w-44 max-lg:h-11"
                        :aria-invalid="!!errors.spent_on"
                    />
                    <InputError :message="errors.spent_on" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="claim-amount">Amount *</Label>
                    <div
                        class="flex w-44 items-center rounded-md border bg-transparent focus-within:ring-2 focus-within:ring-ring dark:bg-input/30"
                        :class="
                            errors.amount
                                ? 'border-destructive'
                                : 'border-input'
                        "
                    >
                        <span
                            class="pl-3 text-sm text-muted-foreground"
                            aria-hidden="true"
                            >RM</span
                        >
                        <Input
                            id="claim-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                            autocomplete="off"
                            class="border-0 bg-transparent text-right tabular-nums shadow-none focus-visible:ring-0 max-lg:h-11 dark:bg-transparent"
                            aria-describedby="claim-amount-help"
                            :aria-invalid="!!errors.amount"
                        />
                    </div>
                    <p
                        id="claim-amount-help"
                        class="text-xs text-muted-foreground"
                    >
                        In RM. For example 45.60
                    </p>
                    <InputError :message="errors.amount" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="claim-purpose">What it was for *</Label>
                    <Textarea
                        id="claim-purpose"
                        v-model="form.purpose"
                        maxlength="200"
                        rows="3"
                        aria-describedby="claim-purpose-help"
                        :aria-invalid="!!errors.purpose"
                    />
                    <p
                        id="claim-purpose-help"
                        class="text-xs text-muted-foreground"
                    >
                        Up to 200 characters. Do not write medical details here.
                    </p>
                    <InputError :message="errors.purpose" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="claim-receipts"
                        >Receipts{{ category?.needsReceipt ? ' *' : '' }}</Label
                    >
                    <p
                        v-if="from && copied"
                        class="text-sm"
                        data-test="claim-copied"
                    >
                        The
                        {{ copied === 1 ? 'receipt' : `${copied} receipts` }} of
                        {{ from.number }} {{ copied === 1 ? 'is' : 'are' }}
                        copied in.
                    </p>
                    <input
                        id="claim-receipts"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,application/pdf"
                        class="min-h-11 text-sm file:mr-3 file:rounded-md file:border file:bg-card file:px-3 file:py-2"
                        aria-describedby="claim-receipts-help"
                        :disabled="form.files.length >= room"
                        :aria-invalid="!!receiptError"
                        @change="onFiles"
                    />
                    <p
                        id="claim-receipts-help"
                        class="text-xs text-muted-foreground"
                    >
                        Up to {{ maxReceipts }} receipts. JPG, PNG or PDF, 5 MB
                        each.
                    </p>
                    <ul
                        v-if="form.files.length"
                        class="flex flex-col gap-1 text-sm"
                    >
                        <li
                            v-for="(f, i) in form.files"
                            :key="`${f.name}-${i}`"
                            class="flex items-center justify-between gap-2"
                        >
                            <span class="min-w-0 truncate"
                                >Receipt {{ copied + i + 1 }} ·
                                {{ f.name }}</span
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                class="min-h-11 lg:min-h-8"
                                :aria-label="`Remove receipt ${copied + i + 1}`"
                                @click="removeFile(i)"
                                >Remove</Button
                            >
                        </li>
                    </ul>
                    <InputError :message="receiptError" />
                </div>
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="claim-send"
                >
                    {{
                        form.processing
                            ? form.files.length
                                ? 'Uploading…'
                                : 'Sending…'
                            : 'Send claim'
                    }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
