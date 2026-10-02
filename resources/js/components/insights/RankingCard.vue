<script setup lang="ts">
import { computed, ref } from 'vue';

type RankingItem = {
    name: string;
    code?: string;
    unit?: string;
    salesSen: number;
    unitsSold?: number;
    patients: number;
};

const props = defineProps<{
    title: string;
    kind: 'service' | 'medicine' | 'package' | 'provider';
    items: RankingItem[];
    emptyMessage?: string;
}>();

const emit = defineEmits<{
    viewAll: [title: string, items: RankingItem[], kind: string];
}>();
const sortKey = ref<'name' | 'salesSen' | 'patients' | 'unitsSold'>('salesSen');
const descending = ref(true);
const rows = computed(() =>
    [...props.items]
        .sort((left, right) => {
            const a = left[sortKey.value] ?? 0;
            const b = right[sortKey.value] ?? 0;
            const comparison =
                typeof a === 'string'
                    ? a.localeCompare(String(b))
                    : Number(a) - Number(b);

            return descending.value ? -comparison : comparison;
        })
        .slice(0, 10),
);

const sort = (key: typeof sortKey.value) => {
    if (sortKey.value === key) {
        descending.value = !descending.value;
    } else {
        sortKey.value = key;
        descending.value = key !== 'name';
    }
};

const money = (value: number) =>
    new Intl.NumberFormat('en-MY', {
        style: 'currency',
        currency: 'MYR',
        maximumFractionDigits: 2,
    }).format(value / 100);
</script>

<template>
    <section
        class="flex min-h-72 flex-col rounded-xl border border-border bg-card"
    >
        <header class="border-b border-border px-4 py-3">
            <h2 class="text-sm font-semibold">{{ title }}</h2>
        </header>
        <div class="flex-1 overflow-x-auto px-4">
            <table class="w-full min-w-[440px] text-left text-xs">
                <thead>
                    <tr
                        class="border-b border-border text-[10px] text-muted-foreground"
                    >
                        <th class="py-2 pr-3">#</th>
                        <th class="py-2 pr-3">
                            <button type="button" @click="sort('name')">
                                {{
                                    kind === 'provider'
                                        ? 'DOCTOR'
                                        : title.split(' ')[0].toUpperCase()
                                }}
                                <span aria-hidden="true">{{
                                    sortKey === 'name'
                                        ? descending
                                            ? ' ↓'
                                            : ' ↑'
                                        : ''
                                }}</span>
                            </button>
                        </th>
                        <th class="py-2 pr-3 text-right">
                            <button type="button" @click="sort('salesSen')">
                                SALES
                            </button>
                        </th>
                        <th class="py-2 text-right">
                            <button
                                type="button"
                                @click="
                                    sort(
                                        kind === 'service' ||
                                            kind === 'provider'
                                            ? 'patients'
                                            : 'unitsSold',
                                    )
                                "
                            >
                                {{
                                    kind === 'service' || kind === 'provider'
                                        ? 'PATIENTS'
                                        : 'UNIT SOLD'
                                }}
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(item, index) in rows"
                        :key="`${item.code ?? item.name}-${index}`"
                        class="border-b border-border/70 last:border-0"
                    >
                        <td class="py-2.5 pr-3 text-muted-foreground">
                            {{ index + 1 }}
                        </td>
                        <td
                            class="max-w-52 truncate py-2.5 pr-3 font-medium"
                            :title="item.name"
                        >
                            {{ item.name }}
                        </td>
                        <td class="py-2.5 pr-3 text-right tabular-nums">
                            {{ money(item.salesSen) }}
                        </td>
                        <td class="py-2.5 text-right tabular-nums">
                            {{
                                kind === 'service' || kind === 'provider'
                                    ? item.patients
                                    : item.unitsSold
                            }}
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            colspan="4"
                            class="py-8 text-center text-muted-foreground"
                        >
                            {{ emptyMessage ?? 'No data available' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <footer class="flex justify-end border-t border-border px-4 py-2">
            <button
                type="button"
                class="rounded-md border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="items.length === 0"
                @click="emit('viewAll', title, items, kind)"
            >
                View all
            </button>
        </footer>
    </section>
</template>
