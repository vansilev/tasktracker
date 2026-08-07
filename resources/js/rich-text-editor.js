import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Mention from '@tiptap/extension-mention';
import Underline from '@tiptap/extension-underline';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { createMentionSuggestion } from './mention-suggestion';

/**
 * Everything below is constrained by the server-side HTMLPurifier profile
 * `task_content` (config/purifier.php). Markup the profile does not allow is
 * discarded on write, so the editor must not be able to produce it:
 *
 *  - horizontalRule is off: <hr> is not allowlisted and would vanish silently.
 *  - tables are not resizable: resizing writes a `colwidth` attribute that is
 *    not allowlisted, so widths would be dropped anyway.
 *  - no image extension: <img> is not allowlisted.
 *  - link schemes are limited to the same four the profile accepts.
 *  - mentions (when enabled) insert plain-text `@Token`, never span/data-* attrs.
 */
const LINK_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

const PUSH_DEBOUNCE_MS = 300;

function buildExtensions({ enableMentions = false, mentionSearch = null, mentionLabels = {}, onMentionPopup = null } = {}) {
    const extensions = [
        StarterKit.configure({
            horizontalRule: false,
            // Registered separately below so their options stay visible here.
            link: false,
            underline: false,
            heading: { levels: [1, 2, 3] },
        }),
        Underline,
        Link.configure({
            openOnClick: false,
            autolink: true,
            defaultProtocol: 'https',
            // `protocols` is deliberately left at its default: registering custom
            // schemes mutates linkify globally, and tearing one editor down would
            // reset it for the others. Scheme control lives in isAllowedUri.
            isAllowedUri: (url, ctx) => ctx.defaultValidate(url) && isAllowedScheme(url),
        }),
        Table.configure({ resizable: false, allowTableNodeSelection: true }),
        TableRow,
        TableHeader,
        TableCell,
    ];

    if (enableMentions && typeof mentionSearch === 'function') {
        extensions.push(
            Mention.configure({
                // Mentions are stored as plain text via the custom suggestion
                // command; the node type only hosts the @ trigger plugin.
                suggestion: createMentionSuggestion({
                    search: mentionSearch,
                    labels: mentionLabels,
                    onPopupEl: onMentionPopup || (() => {}),
                }),
            }),
        );
    }

    return extensions;
}

function schemeOf(url) {
    const match = /^([a-z][a-z0-9+.-]*):/i.exec(url);

    return match ? match[1].toLowerCase() : null;
}

function isAllowedScheme(url) {
    const scheme = schemeOf(url);

    return scheme === null || LINK_PROTOCOLS.includes(scheme);
}

function normalizeUrl(value) {
    if (value.startsWith('//')) {
        return `https:${value}`;
    }

    if (schemeOf(value) === null) {
        return `https://${value}`;
    }

    return isAllowedScheme(value) ? value : null;
}

/**
 * Alpine component backing <x-rich-text-editor>.
 *
 * Livewire lifecycle: the ProseMirror DOM lives inside wire:ignore so Livewire's
 * morph never touches it, which means the editor is never rebuilt underneath the
 * user. The price is that Livewire also cannot read the value out of the DOM, so
 * the value is pushed into the property explicitly with $wire.set(..., false).
 */
export default function richTextEditor(config = {}) {
    return {
        property: config.property,
        placeholder: config.placeholder || '',
        labels: config.labels || {},
        enableMentions: Boolean(config.enableMentions),
        editor: null,
        isEmpty: true,
        inTable: false,
        active: {},
        can: { undo: false, redo: false },
        // Last value this instance and the server agreed on. Used to recognise
        // our own write coming back, which is what would otherwise loop.
        lastSynced: '',
        pushTimer: null,
        submitHandler: null,
        formElement: null,
        commitHookCleanup: null,
        mentionPopupEl: null,
        tornDown: false,

        init() {
            const initial = this.$wire.get(this.property) ?? '';
            this.lastSynced = initial;

            const mentionSearch = this.enableMentions
                ? async (term) => {
                    try {
                        const results = await this.$wire.mentionSearch(term);

                        return Array.isArray(results) ? results : [];
                    } catch {
                        return [];
                    }
                }
                : null;

            this.editor = new Editor({
                element: this.$refs.editor,
                extensions: buildExtensions({
                    enableMentions: this.enableMentions,
                    mentionSearch,
                    mentionLabels: {
                        list: this.labels.mentionList,
                        empty: this.labels.mentionEmpty,
                    },
                    onMentionPopup: (el) => {
                        this.mentionPopupEl = el;
                    },
                }),
                content: initial,
                editorProps: {
                    attributes: {
                        role: 'textbox',
                        'aria-multiline': 'true',
                        ...(config.ariaLabel ? { 'aria-label': config.ariaLabel } : {}),
                    },
                },
                onSelectionUpdate: () => this.refreshState(),
                onUpdate: () => {
                    this.refreshState();
                    this.schedulePush();
                },
                onBlur: () => this.flushPush(),
            });

            // Not via onCreate: that can fire before this.editor is assigned,
            // which would leave the placeholder covering pre-filled content.
            this.refreshState();

            this.$watch(`$wire.${this.property}`, (value) => this.applyIncoming(value ?? ''));

            // A submit can beat the debounce when the user types and hits Enter.
            // Capture on document runs before Livewire's own submit listener.
            this.formElement = this.$el.closest('form');
            if (this.formElement) {
                this.submitHandler = (event) => {
                    if (event.target === this.formElement) {
                        this.flushPush();
                    }
                };
                document.addEventListener('submit', this.submitHandler, true);
            }

            this.registerCommitFlush();
        },

        destroy() {
            this.teardown();
        },

        /**
         * Flush before Livewire snapshots ephemeral state into a request.
         * `commit.prepare` runs before the payload is built; the later `commit`
         * hook is already too late (updates are frozen). No-op without Livewire.
         */
        registerCommitFlush() {
            if (typeof window.Livewire === 'undefined' || typeof window.Livewire.hook !== 'function') {
                return;
            }

            const owning = this.$wire?.__instance;
            if (!owning) {
                return;
            }

            this.commitHookCleanup = window.Livewire.hook('commit.prepare', ({ component }) => {
                if (component === owning) {
                    this.flushPush();
                }
            });
        },

        teardown() {
            if (this.tornDown) {
                return;
            }

            this.tornDown = true;

            if (this.pushTimer !== null) {
                clearTimeout(this.pushTimer);
                this.pushTimer = null;
            }

            if (this.submitHandler) {
                document.removeEventListener('submit', this.submitHandler, true);
                this.submitHandler = null;
            }

            if (this.commitHookCleanup) {
                this.commitHookCleanup();
                this.commitHookCleanup = null;
            }

            if (this.mentionPopupEl) {
                this.mentionPopupEl.remove();
                this.mentionPopupEl = null;
            }

            if (this.editor && !this.editor.isDestroyed) {
                this.editor.destroy();
            }

            this.editor = null;
        },

        currentHtml() {
            if (!this.editor || this.editor.isDestroyed) {
                return '';
            }

            // An "empty" document is still <p></p>; report it as empty so the
            // required rule fires with its normal message.
            return this.editor.isEmpty ? '' : this.editor.getHTML();
        },

        schedulePush() {
            if (this.pushTimer !== null) {
                clearTimeout(this.pushTimer);
            }

            this.pushTimer = setTimeout(() => {
                this.pushTimer = null;
                this.pushNow();
            }, PUSH_DEBOUNCE_MS);
        },

        flushPush() {
            if (this.pushTimer !== null) {
                clearTimeout(this.pushTimer);
                this.pushTimer = null;
            }

            this.pushNow();
        },

        pushNow() {
            const html = this.currentHtml();

            if (html === this.lastSynced) {
                return;
            }

            this.lastSynced = html;
            // live=false: update the client-side property only. Livewire ships
            // it with the next commit (form submit, wire:click) instead of
            // firing a request on every keystroke.
            this.$wire.set(this.property, html, false);
        },

        applyIncoming(incoming) {
            if (!this.editor || this.editor.isDestroyed) {
                return;
            }

            if (incoming === this.lastSynced || incoming === this.currentHtml()) {
                return;
            }

            // A queued push plus focus means the user is mid-keystroke. Their
            // text wins; the queued push will carry it. Without this an unrelated
            // round trip can land between keystrokes and reset the caret.
            if (this.pushTimer !== null && this.editor.isFocused) {
                return;
            }

            this.lastSynced = incoming;
            this.editor.commands.setContent(incoming, { emitUpdate: false });
            this.refreshState();
        },

        refreshState() {
            const editor = this.editor;

            if (!editor || editor.isDestroyed) {
                return;
            }

            this.isEmpty = editor.isEmpty;
            this.inTable = editor.isActive('table');
            this.active = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                underline: editor.isActive('underline'),
                strike: editor.isActive('strike'),
                code: editor.isActive('code'),
                codeBlock: editor.isActive('codeBlock'),
                blockquote: editor.isActive('blockquote'),
                bulletList: editor.isActive('bulletList'),
                orderedList: editor.isActive('orderedList'),
                link: editor.isActive('link'),
                h1: editor.isActive('heading', { level: 1 }),
                h2: editor.isActive('heading', { level: 2 }),
                h3: editor.isActive('heading', { level: 3 }),
            };
            this.can = {
                undo: editor.can().undo(),
                redo: editor.can().redo(),
            };
        },

        /**
         * aria-pressed as an explicit string: Alpine drops the attribute for a
         * false binding, which would leave the buttons looking untoggleable.
         */
        pressed(name) {
            return this.active[name] ? 'true' : 'false';
        },

        /** Run a chained command with focus restored to the editor. */
        run(callback) {
            if (!this.editor || this.editor.isDestroyed) {
                return;
            }

            callback(this.editor.chain().focus()).run();
            this.refreshState();
        },

        promptLink() {
            if (!this.editor || this.editor.isDestroyed) {
                return;
            }

            const previous = this.editor.getAttributes('link').href || '';
            const answer = window.prompt(this.labels.linkPrompt, previous);

            if (answer === null) {
                return;
            }

            const trimmed = answer.trim();

            if (trimmed === '') {
                this.run((chain) => chain.extendMarkRange('link').unsetLink());

                return;
            }

            const href = normalizeUrl(trimmed);

            if (href === null) {
                window.alert(this.labels.linkInvalid);

                return;
            }

            this.run((chain) => chain.extendMarkRange('link').setLink({ href }));
        },
    };
}
