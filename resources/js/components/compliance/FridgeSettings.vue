<script setup lang="ts">
// HR-11b fridges (Settings tab, Director and Administrator). KPOne ships no fridge and no temperature range: the clinic
// enters its own. 2 to 8 °C is only a hint, to confirm with the clinic's pharmacist or the vaccine supplier.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
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
import { EmptyState, ErrorState } from '@/components/ui/state';
import type { FridgeSetting } from './fridgeTypes';

const props = defineProps<{
    fridges: FridgeSetting[];
    branches: { id: number; name: string }[];
}>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const branchOptions = computed(() =>
    props.branches.map((b) => ({ value: String(b.id), label: b.name })),
);
const sheet = reactive({
    open: false,
    fridge: null as FridgeSetting | null,
    branch: '',
    name: '',
    min: '',
    max: '',
    perDay: '1',
    expectBy: '',
    key: '',
    processing: false,
});
const openSheet = (f: FridgeSetting | null) =>
    Object.assign(sheet, {
        open: true,
        fridge: f,
        branch: f ? String(f.branchId) : '',
        name: f?.name ?? '',
        min: f?.min ?? '',
        max: f?.max ?? '',
        perDay: String(f?.readingsPerDay ?? 1),
        expectBy: f?.expectBy ?? '',
        key: crypto.randomUUID(),
        processing: false,
    });
const send = () => {
    sheet.processing = true;
    router.post(
        `${base}/fridges`,
        {
            ...(sheet.fridge
                ? {
                      fridge_id: sheet.fridge.id,
                      expected_lock_version: sheet.fridge.lockVersion,
                  }
                : { branch_id: sheet.branch }),
            name: sheet.name,
            min: sheet.min,
            max: sheet.max,
            readings_per_day: sheet.perDay,
            expect_by: sheet.expectBy,
            idempotency_key: sheet.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (sheet.open = false),
            onFinish: () => (sheet.processing = false),
        },
    );
};
const archive = (f: FridgeSetting) =>
    router.post(
        `${base}/fridges/${f.id}/archive`,
        {
            expected_lock_version: f.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );
</script>

<template>
    <section class="flex flex-col gap-3" aria-label="Fridges">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold">Vaccine fridges</h2>
                <p class="text-sm text-muted-foreground">
                    KPOne ships no fridge and no temperature range. Type the
                    range your pharmacist or vaccine supplier gives you.
                </p>
            </div>
            <Button
                type="button"
                class="min-h-11 lg:min-h-9"
                data-test="add-fridge"
                @click="openSheet(null)"
                >Add a fridge</Button
            >
        </div>
        <EmptyState
            v-if="!fridges.length"
            title="No fridges yet."
            description="Add each vaccine fridge with its branch and allowed range."
        />
        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="f in fridges"
                :key="f.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold break-words">
                        {{ f.name }}
                        <span
                            v-if="!f.isActive"
                            class="text-xs font-normal text-muted-foreground"
                            >· archived</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ f.branchName }} · Allowed {{ f.min }} to
                        {{ f.max }} °C · {{ f.readingsPerDay }}
                        {{ f.readingsPerDay === 1 ? 'reading' : 'readings' }} a
                        day<template v-if="f.expectBy">
                            · expect by {{ f.expectBy }}</template
                        >
                    </p>
                </div>
                <div v-if="f.isActive" class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        @click="openSheet(f)"
                        >Edit<span class="sr-only"> {{ f.name }}</span></Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        @click="archive(f)"
                        >Archive<span class="sr-only">
                            {{ f.name }}</span
                        ></Button
                    >
                </div>
            </li>
        </ul>

        <Sheet
            :open="sheet.open"
            @update:open="(v: boolean) => (sheet.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="fridge-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        sheet.fridge ? 'Edit fridge' : 'Add a fridge'
                    }}</SheetTitle>
                    <SheetDescription>
                        Hint: many vaccines are kept at 2 to 8 °C. Confirm the
                        range with your pharmacist or vaccine supplier. A change
                        of range applies to new readings only.
                    </SheetDescription>
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="send"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div v-if="!sheet.fridge" class="grid gap-1.5">
                        <Label for="fridge-branch"
                            ><span id="fridge-branch-label"
                                >Branch *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="fridge-branch"
                            v-model="sheet.branch"
                            labelledby="fridge-branch-label"
                            trigger-class="max-lg:h-11"
                            label="Branch"
                            placeholder="Choose"
                            :options="branchOptions"
                            :invalid="!!errors.branch_id"
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fridge-name">Name *</Label>
                        <Input
                            id="fridge-name"
                            v-model="sheet.name"
                            maxlength="80"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fridge-min">Lowest allowed (°C) *</Label>
                        <Input
                            id="fridge-min"
                            v-model="sheet.min"
                            inputmode="decimal"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.min"
                        />
                        <InputError :message="errors.min" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fridge-max">Highest allowed (°C) *</Label>
                        <Input
                            id="fridge-max"
                            v-model="sheet.max"
                            inputmode="decimal"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.max"
                        />
                        <InputError :message="errors.max" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fridge-per-day"
                            >Readings expected a day *</Label
                        >
                        <Input
                            id="fridge-per-day"
                            v-model="sheet.perDay"
                            inputmode="numeric"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.readings_per_day"
                        />
                        <InputError :message="errors.readings_per_day" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="fridge-expect-by"
                            >Expect the readings by (optional, hh:mm)</Label
                        >
                        <Input
                            id="fridge-expect-by"
                            v-model="sheet.expectBy"
                            placeholder="10:00"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.expect_by"
                        />
                        <InputError :message="errors.expect_by" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="sheet.processing"
                        >Save fridge</Button
                    >
                </form>
            </SheetContent>
        </Sheet>
    </section>
</template>
