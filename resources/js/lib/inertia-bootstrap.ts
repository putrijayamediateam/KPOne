import type { Page } from '@inertiajs/core';
import { getInitialPageFromDOM } from '@inertiajs/core';

export const readInitialInertiaPage = (id = 'app'): Page => {
    const page = getInitialPageFromDOM<Page>(id);

    if (!page) {
        throw new Error('Inertia initial page payload is missing.');
    }

    return page;
};
