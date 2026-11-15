import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

const EDGE = 64;
// The strip's own gap is 4 px, so the item before the current one ends exactly at the strip's edge and is hidden whole:
// no name is cut off mid-word on first view.
const START_PADDING = 4;

/**
 * A one-row strip that scrolls sideways on a phone (the space shell's top strip and the Insights report tabs).
 * On load the current item is brought to the start of the strip, so no name is cut off mid-word; while more items are
 * hidden at the right edge it fades (the DD-30 pattern). Nothing happens when the strip already fits.
 */
export function useScrollStrip(
    strip: Ref<HTMLElement | null>,
    currentSelector: string,
) {
    const moreBefore = ref(false);
    const moreAfter = ref(false);

    const measure = () => {
        const el = strip.value;

        moreBefore.value = !!el && el.scrollLeft > 2;
        moreAfter.value =
            !!el && el.scrollLeft + el.clientWidth < el.scrollWidth - 2;
    };

    let observer: ResizeObserver | undefined;

    onMounted(async () => {
        await nextTick();
        const el = strip.value;
        const current = el?.querySelector<HTMLElement>(currentSelector) ?? null;

        if (el && current && el.scrollWidth > el.clientWidth) {
            const box = el.getBoundingClientRect();
            const item = current.getBoundingClientRect();

            // Only move when the current item is not fully in view already.
            if (item.right > box.right - EDGE || item.left < box.left) {
                el.scrollLeft += item.left - box.left - START_PADDING;
            }
        }

        measure();

        if (el && typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(measure);
            observer.observe(el);
        }
    });

    onBeforeUnmount(() => observer?.disconnect());

    /** Binds as :style on the strip: a right-edge fade while more items are hidden there, nothing otherwise. */
    const fadeStyle = computed(() => {
        if (!moreAfter.value) {
            return {};
        }

        const gradient = `linear-gradient(to right, #000 calc(100% - ${EDGE}px), transparent)`;

        return { maskImage: gradient, WebkitMaskImage: gradient };
    });

    return { measure, fadeStyle, moreBefore, moreAfter };
}
