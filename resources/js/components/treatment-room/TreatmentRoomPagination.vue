<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { TreatmentRoomPagination } from '@/types/treatment-room';
defineProps<{
    pagination: TreatmentRoomPagination;
    label: string;
    busy?: boolean;
}>();
const emit = defineEmits<{ page: [page: number] }>();
const navigation = ref<HTMLElement | null>(null);
const changePage = (page: number) => {
    // Move focus before the clicked control becomes disabled; never focus on initial load.
    navigation.value?.focus({ preventScroll: true });
    emit('page', page);
};
</script>
<template>
    <nav
        ref="navigation"
        tabindex="-1"
        :aria-label="`${label} pages`"
        class="flex flex-wrap items-center gap-2 text-sm"
    >
        <span
            >{{ label }}: {{ pagination.total }} total · Page
            {{ pagination.currentPage }} of {{ pagination.lastPage }}</span
        >
        <Button
            variant="outline"
            class="h-9 max-lg:h-11"
            :disabled="busy || pagination.currentPage <= 1"
            :aria-label="`Previous ${label.toLowerCase()} page`"
            @click="changePage(pagination.currentPage - 1)"
            >Previous</Button
        >
        <Button
            variant="outline"
            class="h-9 max-lg:h-11"
            :disabled="busy || pagination.currentPage >= pagination.lastPage"
            :aria-label="`Next ${label.toLowerCase()} page`"
            @click="changePage(pagination.currentPage + 1)"
            >Next</Button
        >
    </nav>
</template>
