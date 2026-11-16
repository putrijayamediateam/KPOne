<script setup lang="ts">
// HR-5a add or renew (HR-PLAN 12.2): the dates, the issuing body, the reference and an evidence file, which goes to the
// HR-4b vault as a Certificate. A health item asks only Done or Not done and the date. Someone else checks it.
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
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

import { useCredentialRecordSheet } from '@/composables/hr/useCredentialRecordSheet';
import type { RecordSheetProps } from '@/composables/hr/useCredentialRecordSheet';

const props = defineProps<RecordSheetProps>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { errors, form, healthOptions, title, onFile, send } =
    useCredentialRecordSheet(props, () => emit('update:open', false));
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent
            class="w-full overflow-y-auto sm:max-w-md"
            data-test="credential-sheet"
        >
            <SheetHeader>
                <SheetTitle>{{ title }}</SheetTitle>
                <SheetDescription>
                    <template v-if="person">For {{ person }}. </template>
                    Someone else checks it before it counts.
                    <template v-if="renewing">
                        Until then the current version stays in force.</template
                    >
                </SheetDescription>
            </SheetHeader>
            <form
                v-if="type"
                class="flex flex-col gap-4 px-4 pb-6"
                @submit.prevent="send"
            >
                <ErrorState
                    v-if="errors.credentials || errors.type_id"
                    :message="errors.credentials ?? errors.type_id ?? ''"
                />
                <template v-if="type.healthItem">
                    <p class="text-xs text-muted-foreground">
                        A health item records only Done or Not done and the
                        date. Do not add test results or notes.
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="credential-health"
                            ><span id="credential-health-label"
                                >Done or not done *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="credential-health"
                            labelledby="credential-health-label"
                            v-model="form.health"
                            trigger-class="max-lg:h-11"
                            label="Done or not done"
                            placeholder="Choose"
                            :options="healthOptions"
                            :invalid="!!errors.health"
                        />
                        <InputError :message="errors.health" />
                    </div>
                    <div v-if="form.health === 'done'" class="grid gap-1.5">
                        <Label for="credential-issued">Date done *</Label>
                        <DateField
                            id="credential-issued"
                            v-model="form.issuedOn"
                            :aria-invalid="!!errors.issued_on"
                        />
                        <InputError :message="errors.issued_on" />
                    </div>
                </template>
                <template v-else>
                    <div class="grid gap-1.5">
                        <Label for="credential-issued">Issue date *</Label>
                        <DateField
                            id="credential-issued"
                            v-model="form.issuedOn"
                            :aria-invalid="!!errors.issued_on"
                        />
                        <InputError :message="errors.issued_on" />
                    </div>
                    <div v-if="type.expires" class="grid gap-1.5">
                        <Label for="credential-expires">Expiry date *</Label>
                        <DateField
                            id="credential-expires"
                            v-model="form.expiresOn"
                            :aria-invalid="!!errors.expires_on"
                        />
                        <InputError :message="errors.expires_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="credential-body"
                            >Issuing body (optional)</Label
                        >
                        <Input
                            id="credential-body"
                            v-model="form.issuingBody"
                            maxlength="120"
                            class="max-lg:h-11"
                            autocomplete="off"
                        />
                        <InputError :message="errors.issuing_body" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="credential-reference"
                            >Reference number (optional)</Label
                        >
                        <Input
                            id="credential-reference"
                            v-model="form.reference"
                            maxlength="40"
                            class="max-lg:h-11"
                            autocomplete="off"
                        />
                        <p class="text-xs text-muted-foreground">
                            Stored encrypted and shown as its last four.
                        </p>
                        <InputError :message="errors.reference" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="credential-file"
                            >Certificate file (optional)</Label
                        >
                        <input
                            id="credential-file"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="min-h-11 text-sm file:mr-3 file:rounded-md file:border file:bg-card file:px-3 file:py-2"
                            :aria-invalid="!!errors.file"
                            @change="onFile"
                        />
                        <p class="text-xs text-muted-foreground">
                            PDF, JPG or PNG, up to 10 MB. It is kept in the
                            staff file as a Certificate.
                        </p>
                        <InputError :message="errors.file" />
                    </div>
                </template>
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="form.processing"
                    data-test="credential-save"
                >
                    {{ form.processing ? 'Sending…' : 'Send for check' }}
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
