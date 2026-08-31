import assert from 'node:assert/strict';
import { isAttachmentViewPath, resolveAttachmentViewClick } from '../../resources/js/attachment-lightbox.js';

const origin = 'https://task.avant.od.ua';

assert.equal(isAttachmentViewPath('/tasks/attachments/39/view'), true);
assert.equal(isAttachmentViewPath('/pending-attachments/2/view'), true);
assert.equal(isAttachmentViewPath('/tasks/attachments/39/download'), false);
assert.equal(isAttachmentViewPath('/evil/tasks/attachments/1/view'), false);
assert.equal(isAttachmentViewPath('/tasks/attachments/39/view?x=1'), true);
assert.equal(isAttachmentViewPath('https://example.com/tasks/attachments/1/view'), false);

function click({ href = null, imgSrc = null, imgAlt = '', text = '', ...rest } = {}) {
    const img = imgSrc
        ? {
            getAttribute: (name) => (name === 'alt' ? imgAlt : name === 'src' ? imgSrc : null),
            querySelector: () => null,
        }
        : null;
    const link = href
        ? {
            getAttribute: (name) => (name === 'href' ? href : null),
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

assert.equal(
    resolveAttachmentViewClick(click({ href: '/tasks/attachments/39/download', text: 'file.pdf' }), origin),
    null,
);

assert.equal(
    resolveAttachmentViewClick(click({
        href: '/tasks/attachments/39/view',
        imgAlt: 'shot.png',
        metaKey: true,
    }), origin),
    null,
);

assert.equal(
    resolveAttachmentViewClick(click({ href: 'https://evil.example/tasks/attachments/1/view' }), origin),
    null,
);

assert.deepEqual(
    resolveAttachmentViewClick(click({
        href: null,
        imgSrc: '/tasks/attachments/12/view',
        imgAlt: 'paste.png',
    }), origin),
    { src: '/tasks/attachments/12/view', name: 'paste.png' },
);

console.log('attachment-lightbox-path: ok');
