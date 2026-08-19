import Alpine from 'alpinejs';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';

const extensions = [
    StarterKit,
    Table.configure({ resizable: false }),
    TableRow,
    TableHeader,
    TableCell,
];

// Anti-pattern that caused prod failures: Editor as Alpine reactive property.
Alpine.data('brokenEditor', () => ({
    editor: null,
    result: 'pending',
    init() {
        this.editor = new Editor({
            element: this.$refs.mount,
            extensions,
            content: '<p>hello</p>',
        });
    },
    runBold() {
        try {
            this.editor.chain().focus('end').toggleBold().run();
            this.result = `ok:${this.editor.getHTML()}`;
        } catch (e) {
            this.result = `error:${e.name}:${e.message}`;
        }
    },
}));

// Official TipTap Alpine pattern: Editor lives in a closure (non-reactive).
Alpine.data('fixedEditor', () => {
    let editor = null;

    return {
        result: 'pending',
        html: '',
        init() {
            editor = new Editor({
                element: this.$refs.mount,
                extensions,
                content: '<p>hello</p>',
            });
            this.html = editor.getHTML();
        },
        runBold() {
            try {
                editor.commands.selectAll();
                editor.chain().focus().toggleBold().run();
                this.html = editor.getHTML();
                this.result = 'ok';
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
        runTable() {
            try {
                editor.chain().focus('end').insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
                this.html = editor.getHTML();
                this.result = this.html.includes('<table') ? 'ok-table' : 'missing-table';
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
        pasteText() {
            try {
                editor.commands.focus('end');
                const ok = editor.commands.insertContent(' pasted');
                this.html = editor.getHTML();
                this.result = ok && this.html.includes('pasted') ? 'ok-paste' : 'paste-failed';
            } catch (e) {
                this.result = `error:${e.name}:${e.message}`;
            }
        },
    };
});

window.Alpine = Alpine;
Alpine.start();
window.__smokeReady = true;
