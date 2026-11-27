<script setup lang="ts">
import { ref } from 'vue';
import type { RoomsToday } from '@/composables/useRoomsToday';

withDefaults(
    defineProps<{
        stats: RoomsToday | null;
        unavailable: boolean;
        presentation?: 'cards' | 'table';
    }>(),
    { presentation: 'cards' },
);
const emit = defineEmits<{ page: [page: number] }>();
const summary = ref<HTMLElement | null>(null);
const selectPage = (page: number) => {
    summary.value?.focus();
    emit('page', page);
};
</script>

<template>
    <details class="rounded-xl border bg-card px-4">
        <summary
            ref="summary"
            class="min-h-11 cursor-pointer rounded-sm py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
        >
            Rooms today
        </summary>
        <div class="pb-4">
            <p v-if="unavailable" class="text-sm text-muted-foreground">
                Rooms are temporarily unavailable.
            </p>
            <p v-else-if="!stats" class="text-sm text-muted-foreground">
                Loading rooms…
            </p>
            <template v-else>
                <p class="text-xs text-muted-foreground">
                    {{ stats.operationalDate }} · Whole branch
                </p>
                <p
                    v-if="!stats.hasRooms"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    No consultation rooms are set up for this branch yet.
                </p>
                <p
                    v-if="stats.rows.length === 0"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    <template v-if="presentation === 'table'"
                        >No eligible doctors for this branch today.</template
                    >
                    <template v-else
                        >No doctors are available for consultations at this
                        branch today.</template
                    >
                </p>
                <div
                    v-else-if="presentation === 'table'"
                    class="mt-3 overflow-x-auto"
                >
                    <table class="doctors-table w-full text-left text-sm">
                        <caption class="sr-only">
                            Doctors and consultation rooms for the current
                            branch
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" class="px-3 py-3">Doctor</th>
                                <th scope="col" class="px-3 py-3">Room</th>
                                <th scope="col" class="px-3 py-3">Now</th>
                                <th scope="col" class="px-3 py-3">
                                    Waiting for them
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="doctor in stats.rows"
                                :key="doctor.doctorId"
                                class="border-t align-top"
                            >
                                <td
                                    data-label="Doctor"
                                    class="px-3 py-3 font-semibold break-words"
                                >
                                    {{ doctor.doctorName }}
                                </td>
                                <td
                                    data-label="Room"
                                    class="px-3 py-3 break-words"
                                >
                                    <p>
                                        {{
                                            doctor.roomName ??
                                            'No room assigned'
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            doctor.roomSource === 'chosen' ||
                                            doctor.roomSource === 'roster'
                                        "
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            doctor.roomSource === 'chosen'
                                                ? 'Chosen by doctor'
                                                : 'From approved roster'
                                        }}
                                    </p>
                                </td>
                                <td data-label="Now" class="px-3 py-3">
                                    <p class="font-medium">
                                        <template
                                            v-if="doctor.state === 'serving'"
                                            >Serving<span
                                                v-if="doctor.servingNumber"
                                                class="ml-1 tabular-nums"
                                                >{{
                                                    doctor.servingNumber
                                                }}</span
                                            ></template
                                        ><template
                                            v-else-if="
                                                doctor.state ===
                                                'busy_elsewhere'
                                            "
                                            >Busy at another branch</template
                                        ><template
                                            v-else-if="doctor.state === 'free'"
                                            >Free<span
                                                class="mt-1 block text-xs font-normal text-muted-foreground"
                                                >No active consultation</span
                                            ></template
                                        ><template v-else
                                            >Not available</template
                                        >
                                    </p>
                                    <p
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        Held consultations:
                                        <span class="tabular-nums"
                                            >{{ doctor.held }} of
                                            {{ doctor.heldCap }}</span
                                        >
                                    </p>
                                </td>
                                <td
                                    data-label="Waiting for them"
                                    class="px-3 py-3"
                                >
                                    <p class="font-semibold tabular-nums">
                                        {{ doctor.waiting }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        <span class="tabular-nums">{{
                                            doctor.notReady
                                        }}</span>
                                        not ready (included in waiting)
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul
                    v-else
                    class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <li
                        v-for="doctor in stats.rows"
                        :key="doctor.doctorId"
                        class="min-w-0 border-t pt-3 text-sm"
                    >
                        <h2 class="font-semibold break-words">
                            {{ doctor.doctorName }}
                        </h2>
                        <p class="mt-1 break-words">
                            {{ doctor.roomName ?? 'No room assigned' }}
                        </p>
                        <p
                            v-if="doctor.roomSource"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                doctor.roomSource === 'chosen'
                                    ? 'Chosen by doctor'
                                    : 'From approved roster'
                            }}
                        </p>
                        <p class="mt-2 font-medium">
                            <template v-if="doctor.state === 'serving'"
                                >Serving
                                <span class="tabular-nums">{{
                                    doctor.servingNumber
                                }}</span></template
                            >
                            <template
                                v-else-if="doctor.state === 'busy_elsewhere'"
                                >Busy at another branch</template
                            >
                            <template v-else>Free</template>
                        </p>
                        <dl
                            class="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-xs"
                        >
                            <dt>Waiting</dt>
                            <dd class="tabular-nums">{{ doctor.waiting }}</dd>
                            <dt>Not ready (included in waiting)</dt>
                            <dd class="tabular-nums">{{ doctor.notReady }}</dd>
                            <dt>Held consultations</dt>
                            <dd class="tabular-nums">
                                {{ doctor.held }} of {{ doctor.heldCap }}
                            </dd>
                        </dl>
                    </li>
                </ul>
                <div
                    v-if="stats.pagination"
                    class="mt-4 flex flex-wrap items-center gap-3"
                >
                    <span class="text-xs text-muted-foreground"
                        >{{ stats.rows.length }} shown ·
                        {{ stats.pagination.total }} eligible doctors · Page
                        {{ stats.pagination.currentPage }} of
                        {{ stats.pagination.lastPage }}</span
                    >
                    <button
                        type="button"
                        class="min-h-11 rounded-md border px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50 lg:min-h-9"
                        :disabled="stats.pagination.currentPage <= 1"
                        @click="selectPage(stats.pagination.currentPage - 1)"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="min-h-11 rounded-md border px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50 lg:min-h-9"
                        :disabled="
                            stats.pagination.currentPage >=
                            stats.pagination.lastPage
                        "
                        @click="selectPage(stats.pagination.currentPage + 1)"
                    >
                        Next
                    </button>
                </div>
            </template>
        </div>
    </details>
</template>

<style scoped>
.doctors-table {
    table-layout: fixed;
}
.doctors-table td {
    overflow-wrap: anywhere;
}
@media (max-width: 1023px) {
    .doctors-table thead {
        display: none;
    }
    .doctors-table,
    .doctors-table tbody {
        display: block;
    }
    .doctors-table tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        padding-block: 0.75rem;
    }
    .doctors-table td {
        display: block;
        min-width: 0;
    }
    .doctors-table td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.35rem;
        color: var(--muted-foreground);
        font-size: 0.75rem;
        font-weight: 400;
    }
    .doctors-table td[data-label='Doctor'] {
        grid-column: 1 / -1;
    }
}
</style>
