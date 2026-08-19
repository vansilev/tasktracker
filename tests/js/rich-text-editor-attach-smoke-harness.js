import Alpine from 'alpinejs';
import richTextEditor from '../../resources/js/rich-text-editor.js';

/**
 * Confirms the HTTP inline-upload path inserts into TipTap and that a stale
 * non-empty Livewire echo cannot wipe it (applyIncoming only mirrors clears).
 */
function createAttachEditor() {
    const state = { description: '<p>hello</p>' };

    const payload = {
        id: 9,
        filename: 'shot.png',
        mime: 'image/png',
        isImage: true,
        viewUrl: '/tasks/attachments/9/view',
        downloadUrl: '/tasks/attachments/9/download',
    };

    const data = richTextEditor({
        property: 'description',
        placeholder: 'Type…',
        ariaLabel: 'Description',
        enableMentions: false,
        enableInlineAttachments: true,
        inlineUploadUrl: '/tasks/1/attachments/inline',
        labels: {
            linkPrompt: 'URL',
            linkInvalid: 'Invalid',
            mentionList: 'Mentions',
            mentionEmpty: 'Empty',
            attach: 'Attach',
            attachFailed: 'Failed',
        },
    });

    data.$wire = {
        get: (key) => state[key] ?? '',
        set: (key, value) => {
            state[key] = value;
        },
        call: async (method) => {
            if (method === 'refreshAttachments') {
                return null;
            }

            throw new Error(`unexpected call: ${method}`);
        },
    };

    // Stub the JSON upload endpoint used by the real editor.
    window.fetch = async (url, options) => {
        if (String(url).includes('/attachments/inline') && options?.method === 'POST') {
            return {
                ok: true,
                status: 200,
                async json() {
                    return payload;
                },
            };
        }

        throw new Error(`unexpected fetch: ${url}`);
    };

    return {
        ...data,
        result: 'pending',
        html: '',
        async runAttach() {
            try {
                const file = new File([new Uint8Array([1, 2, 3])], 'shot.png', { type: 'image/png' });
                await this.uploadAndInsert(file);

                // Stale non-empty Livewire echo — must be ignored.
                this.applyIncoming('<p>hello</p>');

                this.html = this.rawHtml();
                this.result = this.html.includes('/tasks/attachments/9/view')
                    && this.html.includes('<img')
                    ? 'ok-attach'
                    : `wiped:${this.html}`;
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
        runConfirmClearOnly() {
            try {
                this.insertAttachment(payload);
                this.applyIncoming('<p>stale</p>');
                const afterStale = this.rawHtml();
                this.applyIncoming('');
                const afterClear = this.rawHtml();
                this.html = `stale=${afterStale}||clear=${afterClear}`;
                this.result = afterStale.includes('<img') && !afterClear.includes('<img')
                    ? 'clear-only-ok'
                    : `unexpected:${this.html}`;
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
    };
}

Alpine.data('attachEditor', createAttachEditor);

window.Alpine = Alpine;
Alpine.start();
window.__attachSmokeReady = true;
