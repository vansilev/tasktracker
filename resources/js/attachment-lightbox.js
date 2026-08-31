/**
 * Open task attachment images in an overlay instead of navigating to /view.
 *
 * Overlay visibility is toggled on the DOM node (not Alpine x-show): Livewire
 * morphs the card after load, which leaves Alpine bindings on the overlay dead.
 */
export function isAttachmentViewPath(pathname) {
    const path = String(pathname).split('?')[0].split('#')[0];

    return /^\/(?:tasks\/attachments|pending-attachments)\/\d+\/view$/.test(path);
}

export function resolveAttachmentViewClick(event, origin) {
    if (event.defaultPrevented) {
        return null;
    }

    if (event.button != null && event.button !== 0) {
        return null;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return null;
    }

    const fromHref = (href, name) => {
        if (! href) {
            return null;
        }

        let url;
        try {
            url = new URL(href, origin);
        } catch {
            return null;
        }

        let pageOrigin;
        try {
            pageOrigin = new URL(origin).origin;
        } catch {
            return null;
        }

        if (url.origin !== pageOrigin) {
            return null;
        }

        if (! isAttachmentViewPath(url.pathname)) {
            return null;
        }

        return { src: url.pathname, name: (name || '').trim() };
    };

    const link = event.target?.closest?.('a[href]');
    if (link) {
        const img = link.querySelector('img');
        const name = img?.getAttribute('alt') || link.textContent;

        return fromHref(link.getAttribute('href'), name);
    }

    const img = event.target?.closest?.('img[src]');
    if (img) {
        return fromHref(img.getAttribute('src'), img.getAttribute('alt'));
    }

    return null;
}

export default function attachmentLightbox() {
    let onKey = null;

    function overlayOf(root) {
        return root.querySelector('[data-attachment-lightbox]');
    }

    return {
        init() {
            this.$el.addEventListener('click', (event) => {
                if (event.target.closest('[data-attachment-lightbox-close], [data-attachment-lightbox-backdrop]')) {
                    event.preventDefault();
                    this.closeLightbox();

                    return;
                }

                this.onClick(event);
            });

            onKey = (event) => {
                if (event.key === 'Escape') {
                    this.closeLightbox();
                }
            };
            window.addEventListener('keydown', onKey);
        },
        destroy() {
            if (onKey) {
                window.removeEventListener('keydown', onKey);
            }
            document.body.classList.remove('overflow-hidden');
        },
        onClick(event) {
            const hit = resolveAttachmentViewClick(event, window.location.origin);
            if (! hit) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const overlay = overlayOf(this.$el);
            if (! overlay) {
                return;
            }

            const img = overlay.querySelector('[data-attachment-lightbox-image]');
            const caption = overlay.querySelector('[data-attachment-lightbox-name]');
            if (img) {
                img.src = hit.src;
                img.alt = hit.name;
            }
            if (caption) {
                caption.textContent = hit.name;
                caption.hidden = hit.name === '';
            }

            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            overlay.setAttribute('aria-label', hit.name || overlay.getAttribute('data-fallback-label') || '');
            document.body.classList.add('overflow-hidden');
        },
        closeLightbox() {
            const overlay = overlayOf(this.$el);
            if (overlay) {
                overlay.hidden = true;
                overlay.setAttribute('aria-hidden', 'true');
                const img = overlay.querySelector('[data-attachment-lightbox-image]');
                if (img) {
                    img.removeAttribute('src');
                    img.alt = '';
                }
            }
            document.body.classList.remove('overflow-hidden');
        },
    };
}
