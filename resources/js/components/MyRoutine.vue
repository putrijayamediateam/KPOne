<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

// DAY-1b: a person's own daily routine. Private to them: no manager, report or agent reads it. Ticks belong to one date
// (the branch's local date, chosen by the server), so tomorrow every item shows unticked.
type Routine = {
    date: string;
    lockVersion: number;
    items: Array<{ id: string; title: string; done: boolean }>;
    done: number;
    total: number;
    removed: Array<{ id: string; title: string }>;
    max: number;
};

const props = defineProps<{ routine: Routine }>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const editing = ref(false);
const newTitle = ref('');
const renameDraft = ref<Record<string, string>>({});
const busy = ref(false);
// One key per add attempt: a retry of the same press never adds a second item. It is replaced after a success.
const addKey = ref<string>(crypto.randomUUID());

const send = (
    method: 'post' | 'put' | 'patch',
    url: string,
    data: Record<string, unknown>,
    after?: () => void,
) => {
    busy.value = true;
    router[method](url, data as never, {
        preserveScroll: true,
        preserveState: true,
        only: ['routine', 'errors'],
        onSuccess: () => after?.(),
        onFinish: () => {
            busy.value = false;
        },
    });
};

const base = '/my-day/routine';
const full = computed(() => props.routine.total >= props.routine.max);
const progress = computed(() =>
    props.routine.total === 0
        ? ''
        : `${props.routine.done} of ${props.routine.total} done`,
);

const tick = (id: string, done: boolean) =>
    send('put', `${base}/${id}/tick`, { done });
const add = () => {
    const title = newTitle.value.trim();

    if (title === '' || busy.value) {
        return;
    }

    send(
        'post',
        base,
        {
            title,
            lock_version: props.routine.lockVersion,
            idempotency_key: addKey.value,
        },
        () => {
            newTitle.value = '';
            addKey.value = crypto.randomUUID();
        },
    );
};
const rename = (id: string, current: string) => {
    const title = (renameDraft.value[id] ?? current).trim();

    if (title === '' || title === current) {
        return;
    }

    send(
        'patch',
        `${base}/${id}`,
        { title, lock_version: props.routine.lockVersion },
        () => {
            delete renameDraft.value[id];
        },
    );
};
const move = (index: number, by: -1 | 1) => {
    const ids = props.routine.items.map((i) => i.id);
    const target = index + by;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    send('post', `${base}/reorder`, {
        order: ids,
        lock_version: props.routine.lockVersion,
    });
};
const hide = (id: string) =>
    send('post', `${base}/${id}/hide`, {
        lock_version: props.routine.lockVersion,
    });
const restore = (id: string) =>
    send('post', `${base}/${id}/restore`, {
        lock_version: props.routine.lockVersion,
        idempotency_key: crypto.randomUUID(),
    });
</script>

<template>
    <section
        class="space-card"
        aria-labelledby="my-routine-title"
        data-test="my-routine"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="my-routine-title">My routine</h2>
            <div class="flex items-center gap-3">
                <span
                    v-if="progress"
                    class="text-sm text-muted-foreground"
                    data-test="routine-progress"
                    >{{ progress }}</span
                >
                <Button
                    v-if="routine.total > 0"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="max-lg:min-h-11"
                    :aria-pressed="editing"
                    data-test="routine-edit-toggle"
                    @click="editing = !editing"
                    >{{ editing ? 'Done editing' : 'Edit list' }}</Button
                >
            </div>
        </div>

        <p
            v-if="routine.total === 0"
            class="mt-2 text-sm text-muted-foreground"
        >
            Add the things you do every day. Only you can see this list, and it
            starts fresh each day.
        </p>

        <ul v-else class="mt-2 space-y-1" data-test="routine-list">
            <li
                v-for="(item, index) in routine.items"
                :key="item.id"
                class="flex min-h-11 flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-1"
            >
                <template v-if="!editing">
                    <label
                        class="flex min-h-11 flex-1 cursor-pointer items-center gap-3 text-sm"
                    >
                        <input
                            type="checkbox"
                            class="routine-check"
                            :checked="item.done"
                            :disabled="busy"
                            @change="
                                tick(
                                    item.id,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <span
                            :class="
                                item.done
                                    ? 'text-muted-foreground line-through'
                                    : 'text-foreground'
                            "
                            >{{ item.title }}</span
                        >
                    </label>
                </template>
                <template v-else>
                    <label class="sr-only" :for="`routine-${item.id}`"
                        >Item {{ index + 1 }}</label
                    >
                    <Input
                        :id="`routine-${item.id}`"
                        :model-value="renameDraft[item.id] ?? item.title"
                        maxlength="120"
                        class="min-w-0 flex-1 basis-full max-lg:min-h-11 sm:basis-0"
                        @update:model-value="
                            renameDraft[item.id] = String($event)
                        "
                        @keydown.enter.prevent="rename(item.id, item.title)"
                        @blur="rename(item.id, item.title)"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="max-lg:size-11"
                        :disabled="index === 0 || busy"
                        :aria-label="`Move ${item.title} up`"
                        @click="move(index, -1)"
                        ><ChevronUp class="size-4"
                    /></Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="max-lg:size-11"
                        :disabled="index === routine.items.length - 1 || busy"
                        :aria-label="`Move ${item.title} down`"
                        @click="move(index, 1)"
                        ><ChevronDown class="size-4"
                    /></Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        :disabled="busy"
                        :aria-label="`Remove ${item.title}`"
                        @click="hide(item.id)"
                        >Remove</Button
                    >
                </template>
            </li>
        </ul>
        <InputError :message="errors.lock_version ?? errors.order" />

        <form class="mt-3 space-y-1" @submit.prevent="add">
            <label for="routine-new" class="text-sm font-medium"
                >Add to my routine</label
            >
            <div class="flex gap-2">
                <Input
                    id="routine-new"
                    v-model="newTitle"
                    maxlength="120"
                    placeholder="For example: Check the fridge log"
                    :disabled="full"
                    aria-describedby="routine-warning"
                    class="min-w-0 flex-1 max-lg:min-h-11"
                />
                <Button
                    type="submit"
                    class="max-lg:min-h-11"
                    :disabled="busy || full || newTitle.trim() === ''"
                    >Add</Button
                >
            </div>
            <InputError :message="errors.title" />
            <p id="routine-warning" class="text-xs text-muted-foreground">
                Do not write patient names, IC numbers or passwords here.<span
                    v-if="full"
                >
                    Your routine is full ({{ routine.max }} items).</span
                >
            </p>
        </form>

        <details v-if="routine.removed.length" class="mt-3 text-sm">
            <summary class="min-h-11 cursor-pointer py-2">
                Recently removed ({{ routine.removed.length }})
            </summary>
            <ul class="space-y-1">
                <li
                    v-for="r in routine.removed"
                    :key="r.id"
                    class="flex min-h-11 items-center justify-between gap-2 rounded-lg border px-3 py-1"
                >
                    <span class="text-muted-foreground">{{ r.title }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        :disabled="busy || full"
                        :aria-label="`Restore ${r.title}`"
                        @click="restore(r.id)"
                        >Restore</Button
                    >
                </li>
            </ul>
            <InputError :message="errors.item" />
            <p class="text-xs text-muted-foreground">
                Removed items can be restored for 7 days.
            </p>
        </details>
    </section>
</template>
