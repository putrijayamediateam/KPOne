/**
 * Version 2 look. Classic is the default for everyone. A person who switches on "Try the new layout" (and any page
 * inside the spaces shell) gets `data-ui="v2"` on <html>; the new tokens and component looks apply only under it.
 * `app.blade.php` sets the attribute on first paint from the same rule, so there is no flash.
 */
export type UiVersion = 'classic' | 'v2';

export const uiVersionFor = (page: {
    url: string;
    props: { workspace?: { spaces?: { enabled?: boolean } } | null };
}): UiVersion =>
    page.props.workspace?.spaces?.enabled === true ||
    page.url.startsWith('/spaces')
        ? 'v2'
        : 'classic';

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
