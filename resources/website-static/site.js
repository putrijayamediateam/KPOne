'use strict';
(() => {
    const page = document.body.dataset.page;

    if (page !== 'services' && page !== 'service-detail') {
        return;
    }

    const bm = document.body.dataset.language === 'bm';
    const params = new URLSearchParams(location.search);
    const validAlias = (value) =>
        typeof value === 'string' &&
        /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value) &&
        value.length >= 3 &&
        value.length <= 60;
    const allowed = page === 'services' ? 'category' : 'slug';
    const validQuery =
        [...params.keys()].every((key) => key === allowed) &&
        params.getAll(allowed).length <= 1;
    const unavailable = () => {
        if (page === 'service-detail') {
            const title = bm
                ? 'Perkhidmatan tidak tersedia'
                : 'Service unavailable';
            document.querySelector('h1').textContent = title;
            document.getElementById('service-detail').textContent = bm
                ? 'Perkhidmatan yang diminta tidak tersedia.'
                : 'The requested service is unavailable.';
            document.title = title + ' — ' + document.body.dataset.wordmark;
            document.querySelector('meta[name="robots"]').content =
                'noindex,nofollow';
        } else {
            document.getElementById('filter-status').textContent = bm
                ? 'Kategori tidak tersedia.'
                : 'Category unavailable.';

            for (const card of document.querySelectorAll('[data-category]')) {
                card.hidden = true;
            }
        }
    };
    const catalog = new URL(document.body.dataset.catalog, location.href);

    if (catalog.origin !== location.origin || !validQuery) {
        unavailable();

        return;
    }

    fetch(catalog, {
        credentials: 'omit',
        mode: 'same-origin',
        redirect: 'error',
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error('unavailable');
            }

            return response.json();
        })
        .then((data) => {
            if (
                !Array.isArray(data.services) ||
                !Array.isArray(data.categories)
            ) {
                throw new Error('unavailable');
            }

            if (page === 'services') {
                const select = document.getElementById('service-category');
                const apply = (value) => {
                    if (
                        value &&
                        (!validAlias(value) ||
                            !data.categories.some((c) => c.alias === value))
                    ) {
                        unavailable();

                        return;
                    }

                    const categories = data.categories
                        .filter((c) => c.alias === value || c.parent === value)
                        .map((c) => c.alias);
                    select.value = value;
                    let visible = 0;

                    for (const card of document.querySelectorAll(
                        '[data-category]',
                    )) {
                        card.hidden =
                            value !== '' &&
                            !categories.includes(card.dataset.category);

                        if (!card.hidden) {
                            visible++;
                        }
                    }

                    document.getElementById('filter-status').textContent =
                        visible === 0
                            ? bm
                                ? 'Tiada perkhidmatan dalam kategori ini.'
                                : 'No services in this category.'
                            : '';
                };
                apply(params.get('category') || '');
                select.addEventListener('change', () => {
                    const query = new URLSearchParams();

                    if (select.value) {
                        query.set('category', select.value);
                    }

                    history.replaceState(
                        null,
                        '',
                        location.pathname +
                            (query.size ? '?' + query.toString() : ''),
                    );
                    apply(select.value);
                });

                return;
            }

            const slug = params.get('slug');

            if (!validAlias(slug)) {
                unavailable();

                return;
            }

            const item = data.services.find((s) => s.alias === slug);

            if (!item) {
                unavailable();

                return;
            }

            const box = document.getElementById('service-detail');
            box.replaceChildren();
            document.querySelector('h1').textContent = item.name;
            document.title =
                (item.seo_title || item.name) +
                ' — ' +
                document.body.dataset.wordmark;
            document.querySelector('meta[name="description"]').content =
                item.seo_description || item.short_summary || item.name;
            document.querySelector('meta[property="og:title"]').content =
                document.title;
            document.querySelector('meta[property="og:description"]').content =
                document.querySelector('meta[name="description"]').content;
            const canonical = document.querySelector('link[rel="canonical"]');
            canonical.href =
                canonical.href.split('?')[0] +
                '?slug=' +
                encodeURIComponent(slug);
            document.querySelector('meta[property="og:url"]').content =
                canonical.href;

            for (const alternate of document.querySelectorAll(
                'link[rel="alternate"]',
            )) {
                alternate.href =
                    alternate.href.split('?')[0] +
                    '?slug=' +
                    encodeURIComponent(slug);
            }

            const switchLink = document.querySelector('.language');
            switchLink.href =
                switchLink.href.split('?')[0] +
                '?slug=' +
                encodeURIComponent(slug);
            const paragraph = (text) => {
                if (!text) {
                    return;
                }

                const p = document.createElement('p');
                p.className = 'prose';
                p.textContent = text;
                box.append(p);
            };
            paragraph(item.short_summary);

            for (const image of item.media || []) {
                if (
                    !/^assets\/img\/[a-f0-9]{64}\.(jpg|png|webp)$/.test(
                        image.path,
                    )
                ) {
                    throw new Error('unavailable');
                }

                const img = document.createElement('img');
                img.src = document.body.dataset.root + image.path;
                img.alt = image.alt;
                img.width = image.width;
                img.height = image.height;
                box.append(img);
            }

            for (const section of item.description_sections || []) {
                const h = document.createElement('h2');
                h.textContent = section.heading;
                box.append(h);
                paragraph(section.body);
            }

            paragraph(item.price_text);
            paragraph(item.what_to_expect);
            paragraph(item.suitable_for);
        })
        .catch(unavailable);
})();
