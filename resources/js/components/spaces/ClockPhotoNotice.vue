<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

/**
 * The one-time "About clock-in photos" notice (DS-13). This component only draws the sheet and its wording.
 * HR-PHOTO decides when to show it (before the first camera opens) and records that it was seen, on the server.
 * `first-use` ends with "OK, open the camera" and tells its parent to open the camera; `info` is the same text
 * opened from the link on the Me home, with a plain Close.
 */
withDefaults(
    defineProps<{
        open: boolean;
        mode?: 'first-use' | 'info';
    }>(),
    { mode: 'info' },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();

const confirm = () => {
    emit('update:open', false);
    emit('confirm');
};
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent side="bottom" class="max-h-[85vh] rounded-t-2xl">
            <SheetHeader>
                <SheetTitle>About clock-in photos</SheetTitle>
                <SheetDescription>
                    A live photo is taken when you clock in and when you clock
                    out. Only HR can open it, and each look is recorded. Photos
                    are kept for 7 years. You can always see your own.
                </SheetDescription>
            </SheetHeader>
            <SheetFooter>
                <Button
                    v-if="mode === 'first-use'"
                    type="button"
                    class="min-h-11 w-full"
                    data-test="clock-photo-notice-confirm"
                    @click="confirm"
                >
                    OK, open the camera
                </Button>
                <Button
                    v-else
                    type="button"
                    class="min-h-11 w-full"
                    data-test="clock-photo-notice-close"
                    @click="emit('update:open', false)"
                >
                    Close
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
