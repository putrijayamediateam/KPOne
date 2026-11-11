<script setup lang="ts">
// HR-11c the clinic's own monthly checklist (KPOne ships no item) and the audit settings (Director, Administrator).
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/state';
import type { AuditSettings, ChecklistItemInput } from './auditTypes';

const props = defineProps<{ audit: AuditSettings }>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const blank = (): ChecklistItemInput => ({
    section: '',
    title: '',
    help: '',
    photoRequired: false,
});
const items = ref<ChecklistItemInput[]>(
    props.audit.items.length ? props.audit.items.map((i) => ({ ...i })) : [],
);
const savingChecklist = ref(false);
const addItem = () => {
    const last = items.value[items.value.length - 1];
    items.value.push({ ...blank(), section: last?.section ?? '' });
};
const removeItem = (n: number) => items.value.splice(n, 1);
const saveChecklist = () => {
    savingChecklist.value = true;
    router.post(
        `${base}/checklist`,
        {
            items: items.value.map((i) => ({
                section: i.section,
                title: i.title,
                help: i.help,
                photo_required: i.photoRequired,
            })),
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onFinish: () => (savingChecklist.value = false),
        },
    );
};

const form = reactive({
    verify: props.audit.verifyDoneTasks,
    days:
        props.audit.photoRetentionDays === null
            ? ''
            : String(props.audit.photoRetentionDays),
    processing: false,
});
const saveSettings = () => {
    form.processing = true;
    router.post(
        `${base}/audit-settings`,
        {
            verify_done_tasks: form.verify ? '1' : '0',
            photo_retention_days: form.days,
            expected_lock_version: props.audit.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (form.processing = false) },
    );
};
</script>

<template>
    <section
        class="flex flex-col gap-6"
        aria-label="Audit checklist and settings"
    >
        <div class="flex flex-col gap-3">
            <div>
                <h2 class="text-sm font-semibold">Monthly audit checklist</h2>
                <p class="text-sm text-muted-foreground">
                    KPOne ships no checklist. Type your clinic's own sections
                    and items. Each save is a new version; audits already
                    started keep the version they began with.
                    <template v-if="audit.version">
                        Current version: {{ audit.version }}.</template
                    >
                </p>
            </div>
            <ErrorState v-if="errors.compliance" :message="errors.compliance" />
            <InputError :message="errors.items" />
            <EmptyState
                v-if="!items.length"
                title="No items yet."
                description="Add the first item."
            />
            <ul class="flex flex-col gap-3">
                <li
                    v-for="(item, n) in items"
                    :key="n"
                    class="grid gap-3 rounded-lg border bg-card p-4"
                    data-test="checklist-item"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`item-section-${n}`">Section *</Label>
                        <Input
                            :id="`item-section-${n}`"
                            v-model="item.section"
                            maxlength="80"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors[`items.${n}.section`]"
                        />
                        <InputError :message="errors[`items.${n}.section`]" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`item-title-${n}`">Item *</Label>
                        <Input
                            :id="`item-title-${n}`"
                            v-model="item.title"
                            maxlength="160"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors[`items.${n}.title`]"
                        />
                        <InputError :message="errors[`items.${n}.title`]" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`item-help-${n}`"
                            >Help line (optional)</Label
                        >
                        <Input
                            :id="`item-help-${n}`"
                            v-model="item.help"
                            maxlength="240"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors[`items.${n}.help`]"
                        />
                        <InputError :message="errors[`items.${n}.help`]" />
                    </div>
                    <label
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                    >
                        <input
                            v-model="item.photoRequired"
                            type="checkbox"
                            class="size-4"
                        />
                        A photo is required when this item fails
                    </label>
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="removeItem(n)"
                            >Remove item<span class="sr-only">
                                {{ n + 1 }}</span
                            ></Button
                        >
                    </div>
                </li>
            </ul>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    data-test="add-checklist-item"
                    @click="addItem"
                    >Add an item</Button
                >
                <Button
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    :disabled="savingChecklist || !items.length"
                    data-test="save-checklist"
                    @click="saveChecklist"
                    >Save checklist</Button
                >
            </div>
        </div>

        <form class="flex flex-col gap-3" @submit.prevent="saveSettings">
            <h2 class="text-sm font-semibold">Audit settings</h2>
            <label class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9">
                <input v-model="form.verify" type="checkbox" class="size-4" />
                A done task must be verified by someone other than the person
                who marked it done
            </label>
            <InputError :message="errors.verify_done_tasks" />
            <div class="grid max-w-xs gap-1.5">
                <Label for="retention-days"
                    >Keep audit and task photos for (days)</Label
                >
                <Input
                    id="retention-days"
                    v-model="form.days"
                    inputmode="numeric"
                    class="max-lg:h-11"
                    :aria-invalid="!!errors.photo_retention_days"
                />
                <p class="text-xs text-muted-foreground">
                    Leave empty to keep photos. From
                    {{ audit.minRetentionDays }} days. After this time a daily
                    clean-up deletes the photo files and keeps the record. The
                    change applies to older photos too.
                </p>
                <InputError :message="errors.photo_retention_days" />
            </div>
            <div>
                <Button
                    type="submit"
                    class="min-h-11 lg:min-h-9"
                    :disabled="form.processing"
                    >Save audit settings</Button
                >
            </div>
        </form>
    </section>
</template>
