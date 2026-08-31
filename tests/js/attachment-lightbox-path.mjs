import assert from 'node:assert/strict';
import {
    classifyPreview,
    isAttachmentViewPath,
    looksLikePdf,
    parseAttachmentHref,
    previewItemFromLink,
    resolveAttachmentPreviewClick,
    resolveAttachmentViewClick,
} from '../../resources/js/attachment-lightbox.js';

const origin = 'https://task.avant.od.ua';

assert.equal(isAttachmentViewPath('/tasks/attachments/39/view'), true);
assert.equal(isAttachmentViewPath('/pending-attachments/2/view'), true);
assert.equal(isAttachmentViewPath('/tasks/attachments/39/download'), false);
assert.equal(isAttachmentViewPath('/evil/tasks/attachments/1/view'), false);
assert.equal(isAttachmentViewPath('/tasks/attachments/39/view?x=1'), true);
assert.equal(isAttachmentViewPath('https://example.com/tasks/attachments/1/view'), false);

assert.deepEqual(parseAttachmentHref('/tasks/attachments/39/view', origin), {
    id: 39,
    action: 'view',
    viewPath: '/tasks/attachments/39/view',
    downloadPath: '/tasks/attachments/39/download',
});

assert.equal(classifyPreview({ action: 'view', name: 'shot.png', hasImage: true }), 'image');
assert.equal(classifyPreview({ action: 'download', name: 'file.pdf', hasImage: false }), 'pdf');
assert.equal(classifyPreview({ action: 'download', name: 'notes.docx', hasImage: false }), null);
assert.equal(looksLikePdf(new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d])), true);
assert.equal(looksLikePdf(new Uint8Array([0x00, 0x00])), false);
assert.equal(looksLikePdf(new Uint8Array()), false);

assert.deepEqual(
    previewItemFromLink({ href: '/tasks/attachments/8/download', name: '📄 scan.pdf', hasImage: false }, origin),
    {
        id: 8,
        src: '/tasks/attachments/8/view',
        downloadSrc: '/tasks/attachments/8/download',
        name: '📄 scan.pdf',
        type: 'pdf',
    },
);

function click({ href = null, imgSrc = null, imgAlt = '', text = '', preview = null, ...rest } = {}) {
    const img = imgSrc
        ? {
            getAttribute: (name) => (name === 'alt' ? imgAlt : name === 'src' ? imgSrc : null),
            querySelector: () => null,
        }
        : null;
    const link = href
        ? {
            getAttribute: (name) => {
                if (name === 'href') {
                    return href;
                }
                if (name === 'title') {
                    return null;
                }
                if (name === 'data-attachment-preview') {
                    return preview;
                }

                return null;
            },
            querySelector: (sel) => (sel === 'img' ? img : null),
            textContent: text,
        }
        : null;

    return {
        defaultPrevented: false,
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        target: {
            closest: (sel) => {
                if (sel === 'a[href]') {
                    return link;
                }
                if (sel === 'img[src]') {
                    return img;
                }

                return null;
            },
        },
        ...rest,
    };
}

assert.deepEqual(
    resolveAttachmentViewClick(click({
        href: '/tasks/attachments/39/view',
        imgSrc: '/tasks/attachments/39/view',
        imgAlt: 'shot.png',
        text: 'shot.png',
    }), origin),
    { src: '/tasks/attachments/39/view', name: 'shot.png' },
);

assert.deepEqual(
    resolveAttachmentPreviewClick(click({ href: '/tasks/attachments/39/download', text: 'file.pdf' }), origin),
    {
        id: 39,
        src: '/tasks/attachments/39/view',
        downloadSrc: '/tasks/attachments/39/download',
        name: 'file.pdf',
        type: 'pdf',
    },
);

assert.equal(
    resolveAttachmentPreviewClick(click({ href: '/tasks/attachments/39/download', text: 'notes.docx' }), origin),
    null,
);

assert.equal(
    resolveAttachmentPreviewClick(click({
        href: '/tasks/attachments/39/view',
        imgAlt: 'shot.png',
        metaKey: true,
    }), origin),
    null,
);

assert.equal(
    resolveAttachmentPreviewClick(click({ href: 'https://evil.example/tasks/attachments/1/view' }), origin),
    null,
);

assert.deepEqual(
    resolveAttachmentPreviewClick(click({
        href: null,
        imgSrc: '/tasks/attachments/12/view',
        imgAlt: 'paste.png',
    }), origin),
    {
        id: 12,
        src: '/tasks/attachments/12/view',
        downloadSrc: '/tasks/attachments/12/download',
        name: 'paste.png',
        type: 'image',
    },
);

console.log('attachment-lightbox-path: ok');
