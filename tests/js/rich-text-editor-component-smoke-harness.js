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
        enableMentions: true,
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
        mentionSearch: async (term) => {
            window.__mentionTerms = window.__mentionTerms || [];
            window.__mentionTerms.push(String(term ?? ''));
            const people = [
                { id: 1, name: 'Павел', email: 'pavel@tcsavant.com', label: 'Павел' },
                { id: 2, name: 'Максим Гольдт', email: 'crm.manager@tcsavant.com', label: 'Максим Гольдт' },
            ];
            const q = String(term ?? '').trim().toLowerCase();
            if (q === '') {
                return people;
            }

            return people.filter((person) => person.name.toLowerCase().includes(q) || person.email.toLowerCase().startsWith(q));
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
        runQuoteAfterBlur() {
            try {
                this.run((c) => c.setContent('<p>typed here</p>').focus('end'));
                this.rememberCaret();
                this.$refs.editor?.querySelector('.ProseMirror')?.blur();
                this.insertCommentQuote({ id: 9, author: 'Ann', excerpt: 'said this' });
                this.html = this.rawHtml();
                const quote = this.$refs.editor?.querySelector('blockquote.comment-quote');
                const typedAt = this.html.indexOf('typed here');
                const quoteAt = this.html.indexOf('data-quoted-comment-id="9"');
                if (! quote || quoteAt === -1) {
                    this.result = `quote-missing:${this.html}`;
                } else if (typedAt === -1 || typedAt > quoteAt) {
                    this.result = `quote-not-after-caret:${this.html}`;
                } else if (quote.getAttribute('contenteditable') !== 'false') {
                    this.result = `quote-editable:${this.html}`;
                } else {
                    this.result = 'ok-quote-caret';
                }
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
    };
});

window.Alpine = Alpine;
Alpine.start();
window.__componentSmokeReady = true;
