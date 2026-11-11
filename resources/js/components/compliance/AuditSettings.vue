<script setup lang="ts">
// HR-11c the clinic's own monthly checklist (KPOne ships no item) and the audit settings (Director, Administrator).
// Items sit inside their section, so a section is typed once; the order on screen is the order of the audit.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState, ErrorState } from '@/components/ui/state';
import type { AuditSettings } from './auditTypes';

type Item = { title: string; help: string; photoRequired: boolean };
type Section = { title: string; items: Item[] };

const props = defineProps<{ audit: AuditSettings }>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const blankItem = (): Item => ({ title: '', help: '', photoRequired: false });
const group = (): Section[] => {
    const out: Section[] = [];

    props.audit.items.forEach((i) => {
        let s = out.find(
            (x) => x.title.toLowerCase() === i.section.toLowerCase(),
        );

        if (!s) {
            s = { title: i.section, items: [] };
            out.push(s);
        }

        s.items.push({
            title: i.title,
            help: i.help,
            photoRequired: i.photoRequired,
        });
    });

    return out;
};
const sections = ref<Section[]>(group());
const savingChecklist = ref(false);
const addSection = () =>
    sections.value.push({ title: '', items: [blankItem()] });
const removeSection = (s: number) => sections.value.splice(s, 1);
const moveSection = (s: number, by: number) => {
    const to = s + by;

    if (to >= 0 && to < sections.value.length) {
        sections.value.splice(to, 0, sections.value.splice(s, 1)[0]);
    }
};
const addItem = (s: number) => sections.value[s].items.push(blankItem());
const removeItem = (s: number, n: number) =>
    sections.value[s].items.splice(n, 1);
const moveItem = (s: number, n: number, by: number) => {
    const list = sections.value[s].items;
    const to = n + by;

    if (to >= 0 && to < list.length) {
        list.splice(to, 0, list.splice(n, 1)[0]);
    }
};
const duplicate = (s: number) =>
    sections.value.some(
        (x, i) =>
            i !== s &&
            x.title.trim() !== '' &&
            x.title.trim().toLowerCase() ===
                sections.value[s].title.trim().toLowerCase(),
    );
const flat = computed(() => {
    const out: {
        section: string;
        title: string;
        help: string;
        photo_required: boolean;
    }[] = [];

    sections.value.forEach((s) =>
        s.items.forEach((i) =>
            out.push({
                section: s.title,
                title: i.title,
                help: i.help,
                photo_required: i.photoRequired,
            }),
        ),
    );

    return out;
});
// The server reports an error by the item's place in the saved list, so find the same place on screen.
const errorAt = (s: number, n: number, field: string) => {
    let index = n;

    for (let k = 0; k < s; k++) {
        index += sections.value[k].items.length;
    }

    return errors.value[`items.${index}.${field}`];
};
const canSave = computed(
    () =>
        flat.value.length > 0 &&
        !sections.value.some((_, s) => duplicate(s)) &&
        !savingChecklist.value,
);
const saveChecklist = () => {
    savingChecklist.value = true;
    router.post(
        `${base}/checklist`,
        { items: flat.value, idempotency_key: crypto.randomUUID() },
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
        <div id="audit-checklist" class="flex scroll-mt-20 flex-col gap-3">
            <div>
                <h2 class="text-sm font-semibold">Monthly audit checklist</h2>
                <p class="text-sm text-muted-foreground">
                    KPOne ships no checklist. Type your clinic's own sections
                    and items; they appear in this order. Each save is a new
                    version; audits already started keep the version they began
                    with.
                    <template v-if="audit.version">
                        Current version: {{ audit.version }}.</template
                    >
                </p>
            </div>
            <ErrorState v-if="errors.compliance" :message="errors.compliance" />
            <InputError :message="errors.items" />
            <EmptyState
                v-if="!sections.length"
                title="No items yet."
                description="Add the first section, then its items."
            />
            <div
                v-for="(sec, s) in sections"
                :key="s"
                class="grid gap-3 rounded-lg border bg-card p-4"
                data-test="checklist-section"
            >
                <div class="grid gap-1.5">
                    <Label :for="`section-title-${s}`">Section *</Label>
                    <Input
                        :id="`section-title-${s}`"
                        v-model="sec.title"
                        maxlength="80"
                        class="max-lg:h-11"
                        :aria-invalid="
                            duplicate(s) || !!errorAt(s, 0, 'section')
                        "
                    />
                    <p
                        v-if="duplicate(s)"
                        class="text-xs text-destructive"
                        role="status"
                    >
                        Another section has this name. Add the item to that
                        section instead.
                    </p>
                    <InputError :message="errorAt(s, 0, 'section')" />
                </div>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="(item, n) in sec.items"
                        :key="n"
                        class="grid gap-3 rounded-md border p-3"
                        data-test="checklist-item"
                    >
                        <div class="grid gap-1.5">
                            <Label :for="`item-title-${s}-${n}`">Item *</Label>
                            <Input
                                :id="`item-title-${s}-${n}`"
                                v-model="item.title"
                                maxlength="160"
                                class="max-lg:h-11"
                                :aria-invalid="!!errorAt(s, n, 'title')"
                            />
                            <InputError :message="errorAt(s, n, 'title')" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label :for="`item-help-${s}-${n}`"
                                >Help line (optional)</Label
                            >
                            <Input
                                :id="`item-help-${s}-${n}`"
                                v-model="item.help"
                                maxlength="240"
                                class="max-lg:h-11"
                                :aria-invalid="!!errorAt(s, n, 'help')"
                            />
                            <InputError :message="errorAt(s, n, 'help')" />
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
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                :disabled="n === 0"
                                @click="moveItem(s, n, -1)"
                                >Move up<span class="sr-only">
                                    {{ item.title || `item ${n + 1}` }}</span
                                ></Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                :disabled="n === sec.items.length - 1"
                                @click="moveItem(s, n, 1)"
                                >Move down<span class="sr-only">
                                    {{ item.title || `item ${n + 1}` }}</span
                                ></Button
                            >
                            <Button
                                type="button"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                @click="removeItem(s, n)"
                                >Remove item<span class="sr-only">
                                    {{ item.title || n + 1 }}</span
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
                        @click="addItem(s)"
                        >Add an item<span class="sr-only">
                            to {{ sec.title || `section ${s + 1}` }}</span
                        ></Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :disabled="s === 0"
                        @click="moveSection(s, -1)"
                        >Move section up<span class="sr-only">
                            {{ sec.title }}</span
                        ></Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :disabled="s === sections.length - 1"
                        @click="moveSection(s, 1)"
                        >Move section down<span class="sr-only">
                            {{ sec.title }}</span
                        ></Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        @click="removeSection(s)"
                        >Remove section<span class="sr-only">
                            {{ sec.title }}</span
                        ></Button
                    >
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    data-test="add-checklist-section"
                    @click="addSection"
                    >Add a section</Button
                >
                <Button
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    :disabled="!canSave"
                    data-test="save-checklist"
                    @click="saveChecklist"
                    >Save checklist</Button
                >
            </div>
        </div>

        <form
            id="audit-settings"
            class="flex scroll-mt-20 flex-col gap-3"
            @submit.prevent="saveSettings"
        >
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
