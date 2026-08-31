/**
 * Open task images and PDFs in an overlay instead of leaving the card.
 * Visibility and slide state are toggled on the DOM: Livewire morphs the
 * card after load, which leaves Alpine x-show bindings on the overlay dead.
 */

const ATTACHMENT_PATH = /^\/(?:tasks\/attachments|pending-attachments)\/(\d+)\/(view|download)$/;

export function isAttachmentViewPath(pathname) {
    const path = String(pathname).split('?')[0].split('#')[0];

    return /^\/(?:tasks\/attachments|pending-attachments)\/\d+\/view$/.test(path);
}

export function parseAttachmentHref(href, origin) {
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

    const path = url.pathname.split('?')[0].split('#')[0];
    const match = path.match(ATTACHMENT_PATH);
    if (! match) {
        return null;
    }

    const prefix = path.includes('pending-attachments')
        ? '/pending-attachments/'
        : '/tasks/attachments/';

    return {
        id: Number(match[1]),
        action: match[2],
        viewPath: `${prefix}${match[1]}/view`,
        downloadPath: `${prefix}${match[1]}/download`,
    };
}

export function classifyPreview({ action, name, hasImage, previewAttr }) {
    if (previewAttr === 'image' || previewAttr === 'pdf') {
        return previewAttr;
    }

    if (hasImage) {
        return 'image';
    }

    if (/\.pdf$/i.test(name || '')) {
        return 'pdf';
    }

    if (action === 'view') {
        return 'image';
    }

    return null;
}

export function previewItemFromLink({ href, name, hasImage, previewAttr }, origin) {
    const parsed = parseAttachmentHref(href, origin);
    if (! parsed) {
        return null;
    }

    const type = classifyPreview({
        action: parsed.action,
        name,
        hasImage,
        previewAttr,
    });

    if (! type) {
        return null;
    }

    return {
        id: parsed.id,
        src: parsed.viewPath,
        downloadSrc: parsed.downloadPath,
        name: (name || '').trim(),
        type,
    };
}

export function resolveAttachmentPreviewClick(event, origin) {
    if (event.defaultPrevented) {
        return null;
    }

    if (event.button != null && event.button !== 0) {
        return null;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return null;
    }

    const link = event.target?.closest?.('a[href]');
    if (link) {
        const img = link.querySelector('img');

        return previewItemFromLink({
            href: link.getAttribute('href'),
            name: img?.getAttribute('alt') || link.getAttribute('title') || link.textContent,
            hasImage: Boolean(img),
            previewAttr: link.getAttribute('data-attachment-preview'),
        }, origin);
    }

    const img = event.target?.closest?.('img[src]');
    if (img) {
        return previewItemFromLink({
            href: img.getAttribute('src'),
            name: img.getAttribute('alt'),
            hasImage: true,
            previewAttr: 'image',
        }, origin);
    }

    return null;
}

/** @deprecated use resolveAttachmentPreviewClick */
export function resolveAttachmentViewClick(event, origin) {
    const hit = resolveAttachmentPreviewClick(event, origin);

    return hit ? { src: hit.src, name: hit.name } : null;
}

export function collectPreviewItems(root, origin) {
    const items = [];
    const seen = new Set();

    const add = (item) => {
        if (! item) {
            return;
        }

        const key = `${item.src}`;
        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        items.push(item);
    };

    root.querySelectorAll('a[href]').forEach((link) => {
        if (link.closest('[data-attachment-lightbox]')) {
            return;
        }

        const img = link.querySelector('img');
        add(previewItemFromLink({
            href: link.getAttribute('href'),
            name: img?.getAttribute('alt') || link.getAttribute('title') || link.textContent,
            hasImage: Boolean(img),
            previewAttr: link.getAttribute('data-attachment-preview'),
        }, origin));
    });

    root.querySelectorAll('img[src]').forEach((img) => {
        if (img.closest('[data-attachment-lightbox]')) {
            return;
        }

        if (img.closest('a[href]')) {
            return;
        }

        add(previewItemFromLink({
            href: img.getAttribute('src'),
            name: img.getAttribute('alt'),
            hasImage: true,
            previewAttr: 'image',
        }, origin));
    });

    return items;
}

export function looksLikePdf(bytes) {
    return Boolean(
        bytes
        && bytes.length >= 5
        && bytes[0] === 0x25
        && bytes[1] === 0x50
        && bytes[2] === 0x44
        && bytes[3] === 0x46
        && bytes[4] === 0x2d,
    );
}

export default function attachmentLightbox() {
    let onKey = null;
    let items = [];
    let index = 0;
    let objectUrl = null;
    let loadSeq = 0;

    function overlayOf(root) {
        return root.querySelector('[data-attachment-lightbox]');
    }

    function revokePdfUrl() {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    }

    function setPdfError(overlay, show) {
        const error = overlay.querySelector('[data-attachment-lightbox-error]');
        const frame = overlay.querySelector('[data-attachment-lightbox-pdf]');
        if (error) {
            error.hidden = ! show;
        }
        if (frame && show) {
            frame.hidden = true;
            frame.removeAttribute('src');
        }
    }

    async function fillPdfFrame(overlay, src, token) {
        const frame = overlay.querySelector('[data-attachment-lightbox-pdf]');
        if (! frame) {
            return;
        }

        revokePdfUrl();
        frame.removeAttribute('src');
        setPdfError(overlay, false);

        try {
            const response = await fetch(src, { credentials: 'same-origin' });
            if (token !== loadSeq) {
                return;
            }

            if (! response.ok) {
                setPdfError(overlay, true);

                return;
            }

            const buffer = await response.arrayBuffer();
            if (token !== loadSeq) {
                return;
            }

            const bytes = new Uint8Array(buffer);
            if (! looksLikePdf(bytes)) {
                setPdfError(overlay, true);

                return;
            }

            const blob = new Blob([buffer], { type: 'application/pdf' });
            objectUrl = URL.createObjectURL(blob);
            frame.hidden = false;
            frame.src = objectUrl;
        } catch {
            if (token === loadSeq) {
                setPdfError(overlay, true);
            }
        }
    }

    function showItem(root, nextIndex) {
        if (items.length === 0) {
            return;
        }

        const token = ++loadSeq;
        index = (nextIndex + items.length) % items.length;
        const item = items[index];
        const overlay = overlayOf(root);
        if (! overlay) {
            return;
        }

        const img = overlay.querySelector('[data-attachment-lightbox-image]');
        const frame = overlay.querySelector('[data-attachment-lightbox-pdf]');
        const caption = overlay.querySelector('[data-attachment-lightbox-name]');
        const counter = overlay.querySelector('[data-attachment-lightbox-counter]');
        const prev = overlay.querySelector('[data-attachment-lightbox-prev]');
        const next = overlay.querySelector('[data-attachment-lightbox-next]');
        const download = overlay.querySelector('[data-attachment-lightbox-download]');

        revokePdfUrl();
        setPdfError(overlay, false);

        if (item.type === 'pdf') {
            if (img) {
                img.hidden = true;
                img.removeAttribute('src');
            }
            if (frame) {
                frame.hidden = true;
                frame.title = item.name;
            }
            fillPdfFrame(overlay, item.src, token);
        } else {
            if (frame) {
                frame.hidden = true;
                frame.removeAttribute('src');
            }
            if (img) {
                img.hidden = false;
                img.src = item.src;
                img.alt = item.name;
            }
        }

        if (caption) {
            caption.textContent = item.name;
            caption.hidden = item.name === '';
        }

        if (counter) {
            counter.textContent = items.length > 1 ? `${index + 1} / ${items.length}` : '';
            counter.hidden = items.length < 2;
        }

        const many = items.length > 1;
        if (prev) {
            prev.hidden = ! many;
        }
        if (next) {
            next.hidden = ! many;
        }

        if (download) {
            download.href = item.downloadSrc;
            download.hidden = false;
        }

        overlay.hidden = false;
        overlay.setAttribute('aria-hidden', 'false');
        overlay.setAttribute('aria-label', item.name || overlay.getAttribute('data-fallback-label') || '');
        document.body.classList.add('overflow-hidden');
    }

    return {
        init() {
            this.$el.addEventListener('click', (event) => {
                if (event.target.closest('[data-attachment-lightbox-close], [data-attachment-lightbox-backdrop]')) {
                    event.preventDefault();
                    this.closeLightbox();

                    return;
                }

                if (event.target.closest('[data-attachment-lightbox-prev]')) {
                    event.preventDefault();
                    showItem(this.$el, index - 1);

                    return;
                }

                if (event.target.closest('[data-attachment-lightbox-next]')) {
                    event.preventDefault();
                    showItem(this.$el, index + 1);

                    return;
                }

                this.onClick(event);
            });

            onKey = (event) => {
                const overlay = overlayOf(this.$el);
                if (! overlay || overlay.hidden) {
                    return;
                }

                if (event.key === 'Escape') {
                    this.closeLightbox();
                }
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    showItem(this.$el, index - 1);
                }
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    showItem(this.$el, index + 1);
                }
            };
            window.addEventListener('keydown', onKey);
        },
        destroy() {
            loadSeq += 1;
            revokePdfUrl();
            if (onKey) {
                window.removeEventListener('keydown', onKey);
            }
            document.body.classList.remove('overflow-hidden');
        },
        onClick(event) {
            if (event.target.closest('[data-attachment-lightbox]')) {
                return;
            }

            const hit = resolveAttachmentPreviewClick(event, window.location.origin);
            if (! hit) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            items = collectPreviewItems(this.$el, window.location.origin);
            if (items.length === 0) {
                items = [hit];
            }

            const found = items.findIndex((item) => item.src === hit.src);
            showItem(this.$el, found === -1 ? 0 : found);
        },
        closeLightbox() {
            loadSeq += 1;
            revokePdfUrl();
            const overlay = overlayOf(this.$el);
            if (overlay) {
                overlay.hidden = true;
                overlay.setAttribute('aria-hidden', 'true');
                const img = overlay.querySelector('[data-attachment-lightbox-image]');
                const frame = overlay.querySelector('[data-attachment-lightbox-pdf]');
                if (img) {
                    img.removeAttribute('src');
                    img.alt = '';
                }
                if (frame) {
                    frame.removeAttribute('src');
                }
                setPdfError(overlay, false);
            }
            items = [];
            index = 0;
            document.body.classList.remove('overflow-hidden');
        },
    };
}
