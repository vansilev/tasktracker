import Alpine from 'alpinejs';
import richTextEditor from '../../resources/js/rich-text-editor.js';

/**
 * Exercise the real Alpine component factory with a stub $wire.
 * Fails if Editor is accidentally put back on reactive state.
 */
Alpine.data('appEditor', () => {
    const state = { description: '<p>hello</p>' };
    const watchers = [];

    const data = richTextEditor({
        property: 'description',
        placeholder: 'Type…',
        ariaLabel: 'Description',
        enableMentions: false,
        enableInlineAttachments: false,
        labels: {
            linkPrompt: 'URL',
            linkInvalid: 'Invalid',
            mentionList: 'Mentions',
            mentionEmpty: 'Empty',
            attach: 'Attach',
            attachFailed: 'Failed',
        },
    });

    // Inject Livewire-ish API before Alpine init runs.
    data.$wire = {
        get: (key) => state[key] ?? '',
        set: (key, value) => {
            state[key] = value;
            watchers.forEach((fn) => fn(value));
        },
    };
    data.$watch = () => {
        // no-op for smoke; incoming sync not under test here
    };

    return {
        ...data,
        result: 'pending',
        html: '',
        runBold() {
            try {
                this.run((c) => c.selectAll().toggleBold());
                this.html = this.rawHtml();
                this.result = (this.html.includes('<strong>') || this.html.includes('<b>')) ? 'ok' : 'no-bold';
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
        runTable() {
            try {
                this.run((c) => c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }));
                this.html = this.rawHtml();
                const synced = this.currentHtml();
                if (!this.html.includes('<table')) {
                    this.result = `missing-table:${this.html}`;
                } else if (!synced.includes('<table')) {
                    // Guards the isEmpty/table sync bug.
                    this.result = `sync-dropped-table:${synced}`;
                } else {
                    this.result = 'ok-table';
                }
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
        runPasteInsert() {
            try {
                this.run((c) => c.focus('end').insertContent('<p>pasted</p>'));
                this.html = this.rawHtml();
                this.result = this.html.includes('pasted') ? 'ok-paste' : `paste-failed:${this.html}`;
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
    };
});

window.Alpine = Alpine;
Alpine.start();
window.__componentSmokeReady = true;
