/**
 * Classic only (CO-1, owner decision 19 Nov 2026): every page is Classic, the spaces included, so `data-ui` on <html>
 * is always "classic" and no `v2:` class or Version 2 token applies. `app.blade.php` sets the same value on first
 * paint. The type and helpers stay until the V2 code is removed (CO-10).
 */
export type UiVersion = 'classic' | 'v2';

// eslint-disable-next-line @typescript-eslint/no-unused-vars
export const uiVersionFor = (page: {
    url: string;
    props: { workspace?: { spaces?: { enabled?: boolean } } | null };
}): UiVersion => 'classic';

export const applyUiVersion = (version: UiVersion): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.ui = version;
};

/**
 * "New look (preview)": `data-look="new"` on <html>, only in Version 2 and only when the person switched it on. The server
 * sets it on first paint (`app.blade.php`); this keeps it right after a visit, since Inertia does not re-render <html>.
 */
export const applyNewLook = (page: {
    url: string;
    props: {
        workspace?: {
            newLook?: boolean;
            spaces?: { enabled?: boolean };
        } | null;
    };
}): void => {
    if (typeof document === 'undefined') {
        return;
    }

    if (uiVersionFor(page) === 'v2' && page.props.workspace?.newLook === true) {
        document.documentElement.dataset.look = 'new';
    } else {
        delete document.documentElement.dataset.look;
    }
};

/** A Version 2 page component name, as the server renders it (`Registration/V2/Index`). The V2 shell layout is for these. */
export const isV2Page = (name: string): boolean => /(^|\/)V2\//.test(name);
