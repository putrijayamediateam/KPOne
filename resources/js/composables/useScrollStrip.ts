import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

const EDGE = 64;
const START_PADDING = 12;

/**
 * A one-row strip that scrolls sideways on a phone (the space shell's top strip and the Insights report tabs).
 * On load the current item is brought to the start of the strip, so no name is cut off mid-word; while more items are
 * hidden at an edge that edge fades (the DD-30 pattern). Nothing happens when the strip already fits.
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

    /** Binds as :style on the strip. Empty when nothing is hidden. */
    const fadeStyle = computed(() => {
        if (!moreBefore.value && !moreAfter.value) {
            return {};
        }

        const left = moreBefore.value
            ? `transparent, #000 ${EDGE}px`
            : '#000, #000';
        const right = moreAfter.value
            ? `#000 calc(100% - ${EDGE}px), transparent`
            : '#000, #000';
        const gradient = `linear-gradient(to right, ${left}, ${right})`;

        return { maskImage: gradient, WebkitMaskImage: gradient };
    });

    return { measure, fadeStyle, moreBefore, moreAfter };
}
