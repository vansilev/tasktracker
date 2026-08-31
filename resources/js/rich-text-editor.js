import { Editor, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Mention from '@tiptap/extension-mention';
import Underline from '@tiptap/extension-underline';
import Blockquote from '@tiptap/extension-blockquote';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { NodeSelection, Plugin, TextSelection } from '@tiptap/pm/state';
import { createMentionSuggestion } from './mention-suggestion';

/**
 * Everything below is constrained by the server-side HTMLPurifier profile
 * `task_content` (config/purifier.php). Markup the profile does not allow is
 * discarded on write, so the editor must not be able to produce it:
 *
 *  - horizontalRule is off: <hr> is not allowlisted and would vanish silently.
 *  - tables are not resizable: resizing writes a `colwidth` attribute that is
 *    not allowlisted, so widths would be dropped anyway.
 *  - Image extension inserts <img src="/tasks/attachments/{id}/view"> only;
 *    HtmlContentService rejects any other img src after purify.
 *  - link schemes are limited to the same four the profile accepts.
 *  - mentions (when enabled) insert a span.mention chip; task_content allows
 *    class, data-id, data-label, data-type so the name keeps its spaces.
 */
const LINK_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

const PUSH_DEBOUNCE_MS = 300;

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';
const DOCUMENT_ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,application/pdf,'
    + 'application/msword,'
    + 'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
    + 'application/vnd.ms-excel,'
    + 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const ATTACHMENT_ACCEPT = `${IMAGE_ACCEPT},${DOCUMENT_ACCEPT}`;

function buildExtensions({ enableMentions = false, mentionSearch = null, mentionLabels = {}, onMentionPopup = null } = {}) {
    const extensions = [
        StarterKit.configure({
            horizontalRule: false,
            // Registered separately below so their options stay visible here.
            link: false,
            underline: false,
            heading: { levels: [1, 2, 3] },
            blockquote: false,
        }),
        Blockquote,
        commentQuoteNode(),
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
        Image.configure({
            inline: true,
            allowBase64: false,
        }),
        Table.configure({ resizable: false, allowTableNodeSelection: true }),
        TableRow,
        TableHeader,
        TableCell,
    ];

    if (enableMentions && typeof mentionSearch === 'function') {
        extensions.push(
            Mention.configure({
                HTMLAttributes: { class: 'mention' },
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

function parseQuoteInner(element) {
    const paragraphs = [...element.querySelectorAll('p')];
    if (paragraphs.length >= 2) {
        return {
            author: (paragraphs[0].textContent || '').trim(),
            excerpt: paragraphs.slice(1).map((p) => (p.textContent || '').trim()).filter(Boolean).join(' '),
        };
    }

    const strong = element.querySelector('strong');
    const author = (strong?.textContent || '').trim();
    const full = (element.textContent || '').replace(/\s+/g, ' ').trim();
    if (! author) {
        return { author: '', excerpt: full };
    }

    let excerpt = full;
    if (full.startsWith(author)) {
        excerpt = full.slice(author.length).replace(/^\s*[—–-]\s*/, '').trim();
    }

    return { author, excerpt };
}

function commentQuoteChildren(author, excerpt) {
    const children = [['p', {}, ['strong', {}, author]]];
    if (excerpt) {
        children.push(['p', {}, excerpt]);
    }

    return children;
}

function isCommentQuoteSelection(selection) {
    return selection instanceof NodeSelection && selection.node.type.name === 'commentQuote';
}

function caretAfterCommentQuote(view, text = '') {
    const pos = view.state.selection.to;
    const next = view.state.doc.resolve(pos).nodeAfter;
    if (next && next.type.name === 'paragraph') {
        const insertAt = pos + 1;
        let tr = text === ''
            ? view.state.tr.setSelection(TextSelection.create(view.state.doc, insertAt))
            : view.state.tr.insertText(text, insertAt);
        if (text !== '') {
            tr = tr.setSelection(TextSelection.create(tr.doc, insertAt + text.length));
        }
        view.dispatch(tr);

        return;
    }

    const schema = view.state.schema;
    const paragraph = text === ''
        ? schema.nodes.paragraph.create()
        : schema.nodes.paragraph.create(null, schema.text(text));
    let tr = view.state.tr.insert(pos, paragraph);
    const caret = text === '' ? pos + 1 : pos + 1 + text.length;
    tr = tr.setSelection(TextSelection.create(tr.doc, caret));
    view.dispatch(tr);
}

/**
 * Cited comment: a solid chip, not a normal blockquote.
 * Toolbar «Цитата» stays the editable Blockquote above.
 */
function commentQuoteNode() {
    return Node.create({
        name: 'commentQuote',
        group: 'block',
        atom: true,
        selectable: true,
        draggable: false,
        isolating: true,
        priority: 1000,
        addAttributes() {
            return {
                commentId: {
                    default: null,
                    parseHTML: (element) => element.getAttribute('data-quoted-comment-id'),
                    renderHTML: (attributes) => (
                        attributes.commentId
                            ? { 'data-quoted-comment-id': String(attributes.commentId) }
                            : {}
                    ),
                },
                author: {
                    default: '',
                    parseHTML: (element) => parseQuoteInner(element).author,
                    rendered: false,
                },
                excerpt: {
                    default: '',
                    parseHTML: (element) => parseQuoteInner(element).excerpt,
                    rendered: false,
                },
            };
        },
        parseHTML() {
            return [
                { tag: 'blockquote.comment-quote' },
                { tag: 'blockquote[data-quoted-comment-id]' },
            ];
        },
        renderHTML({ HTMLAttributes, node }) {
            return [
                'blockquote',
                mergeAttributes(HTMLAttributes, { class: 'comment-quote' }),
                ...commentQuoteChildren(node.attrs.author || '', node.attrs.excerpt || ''),
            ];
        },
        addNodeView() {
            return ({ node }) => {
                const dom = document.createElement('blockquote');
                dom.className = 'comment-quote';
                dom.setAttribute('contenteditable', 'false');
                if (node.attrs.commentId) {
                    dom.setAttribute('data-quoted-comment-id', String(node.attrs.commentId));
                }
                const authorEl = document.createElement('p');
                const strong = document.createElement('strong');
                strong.textContent = node.attrs.author || '';
                authorEl.appendChild(strong);
                dom.appendChild(authorEl);
                if (node.attrs.excerpt) {
                    const textEl = document.createElement('p');
                    textEl.textContent = node.attrs.excerpt;
                    dom.appendChild(textEl);
                }

                return { dom };
            };
        },
        addProseMirrorPlugins() {
            return [
                new Plugin({
                    props: {
                        handleTextInput(view, _from, _to, text) {
                            if (! isCommentQuoteSelection(view.state.selection)) {
                                return false;
                            }

                            caretAfterCommentQuote(view, text);

                            return true;
                        },
                        handlePaste(view, event) {
                            if (! isCommentQuoteSelection(view.state.selection)) {
                                return false;
                            }

                            caretAfterCommentQuote(view, event.clipboardData?.getData('text/plain') ?? '');

                            return true;
                        },
                        handleKeyDown(view, event) {
                            if (! isCommentQuoteSelection(view.state.selection)) {
                                return false;
                            }
                            if (event.key === 'Backspace' || event.key === 'Delete') {
                                return false;
                            }
                            if (event.key !== 'Enter') {
                                return false;
                            }

                            caretAfterCommentQuote(view, '');
                            event.preventDefault();

                            return true;
                        },
                    },
                }),
            ];
        },
    });
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

function quotePayload(detail) {
    if (Array.isArray(detail)) {
        if (detail.length === 1 && detail[0] && typeof detail[0] === 'object' && ! Array.isArray(detail[0])) {
            return detail[0];
        }

        return { id: detail[0], author: detail[1], excerpt: detail[2] };
    }

    return detail && typeof detail === 'object' ? detail : {};
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toRelativeAppPath(url) {
    if (!url || typeof url !== 'string') {
        return url;
    }

    if (url.startsWith('/')) {
        return url.split(/[?#]/)[0];
    }

    try {
        const parsed = new URL(url, window.location.origin);
        if (parsed.origin === window.location.origin) {
            return parsed.pathname;
        }
    } catch {
        // keep absolute fallback
    }

    return url;
}

function attachmentInsertHtml(payload) {
    const filename = payload.filename || 'file';
    const safeName = escapeHtml(filename);
    const viewUrl = toRelativeAppPath(payload.viewUrl);
    const downloadUrl = toRelativeAppPath(payload.downloadUrl || payload.viewUrl);

    if (payload.isImage) {
        return `<a href="${escapeHtml(viewUrl)}"><img src="${escapeHtml(viewUrl)}" alt="${safeName}"></a>`;
    }

    return `<a href="${escapeHtml(downloadUrl)}" title="${safeName}">📄 ${safeName}</a>`;
}

function clipboardImageFiles(event) {
    const items = event.clipboardData?.items;
    if (!items) {
        return [];
    }

    return Array.from(items)
        .filter((item) => item.type.startsWith('image/'))
        .map((item, index) => {
            const file = item.getAsFile();
            if (!file) {
                return null;
            }

            const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');

            return new File([file], `paste-${Date.now()}-${index}.${ext}`, { type: file.type });
        })
        .filter(Boolean);
}

/**
 * Alpine component backing <x-rich-text-editor>.
 *
 * CRITICAL: the TipTap `Editor` must live in this closure, NOT on the returned
 * Alpine data object. Alpine v3 wraps component properties in Vue's reactive
 * Proxy; dispatching a ProseMirror transaction through a proxied Editor throws
 * `RangeError: Applying a mismatched transaction` (TipTap #1515 / official
 * Alpine install guide). Keep `editor` / `uploadQueue` out of reactive state.
 *
 * Livewire lifecycle: the whole Alpine root is wire:ignore so morph never
 * tears the editor out mid-edit. Values reach the server via $wire.set(..., false).
 *
 * Inline attachments: POST to `inlineUploadUrl` (JSON) → insert into TipTap.
 * Deliberately NOT Livewire WithFileUploads: `_finishUpload` always re-renders
 * and races the editor (stale snapshot / remount → silent no-op insert).
 * TipTap is source of truth while editing; `applyIncoming` only mirrors a
 * server clear (e.g. commentBody = '' after submit). Sidecar paste handlers
 * skip editors with data-inline-attachments.
 */
export default function richTextEditor(config = {}) {
    // Per-instance, non-reactive. Alpine.data() invokes this factory once per
    // component, so multiple editors on one page each get their own closure.
    let editor = null;
    let uploadQueue = Promise.resolve();
    // Clicking «Цитировать» blurs the editor and Chrome often resets
    // ProseMirror's selection to the start. Remember the last real caret.
    let lastCaretPos = 1;

    return {
        property: config.property,
        placeholder: config.placeholder || '',
        labels: config.labels || {},
        enableMentions: Boolean(config.enableMentions),
        enableInlineAttachments: Boolean(config.enableInlineAttachments),
        enableCommentQuotes: Boolean(config.enableCommentQuotes),
        inlineUploadUrl: config.inlineUploadUrl || null,
        isEmpty: true,
        inTable: false,
        uploading: false,
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
        quoteListenerOff: null,

        init() {
            const initial = this.$wire?.get?.(this.property) ?? '';
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

            const self = this;
            const mount = this.$refs.editor;

            if (editor && !editor.isDestroyed) {
                editor.destroy();
            }
            editor = null;
            if (mount) {
                mount.innerHTML = '';
            }

            editor = new Editor({
                element: mount,
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
                    handlePaste(view, event) {
                        if (!self.enableInlineAttachments) {
                            return false;
                        }

                        const files = clipboardImageFiles(event);
                        if (files.length === 0) {
                            return false;
                        }

                        event.preventDefault();
                        files.forEach((file) => self.enqueueUpload(file));

                        return true;
                    },
                },
                onSelectionUpdate: () => this.refreshState(),
                onUpdate: () => {
                    this.refreshState();
                    this.schedulePush();
                },
                onBlur: () => {
                    this.rememberCaret();
                    // Clicking a mention row blurs the editor first. Syncing
                    // Livewire here remounts state and kills the popup.
                    if (this.mentionPopupEl?.isConnected) {
                        return;
                    }
                    this.flushPush();
                },
            });

            // Not via onCreate: that can fire before `editor` is assigned,
            // which would leave the placeholder covering pre-filled content.
            this.refreshState();

            if (typeof this.$watch === 'function' && this.property) {
                this.$watch(`$wire.${this.property}`, (value) => this.applyIncoming(value ?? ''));
            }

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
            this.registerQuoteListener();
        },

        destroy() {
            this.teardown();
        },

        registerQuoteListener() {
            if (! this.enableCommentQuotes || typeof this.$wire?.on !== 'function') {
                return;
            }

            this.quoteListenerOff = this.$wire.on('insert-comment-quote', (detail) => {
                this.insertCommentQuote(quotePayload(detail));
            });
        },

        rememberCaret() {
            if (! editor || editor.isDestroyed) {
                return;
            }

            lastCaretPos = editor.state.selection.to;
        },

        insertCommentQuote(detail = {}) {
            if (! editor || editor.isDestroyed) {
                return;
            }

            const id = Number(detail.id);
            if (! Number.isFinite(id) || id <= 0) {
                return;
            }

            const author = String(detail.author || '');
            const excerpt = String(detail.excerpt || '');
            const maxPos = editor.state.doc.content.size;
            let pos = editor.isFocused ? editor.state.selection.to : lastCaretPos;
            if (pos < 1 || pos > maxPos) {
                pos = maxPos;
            }

            // Do not focus() first: that can snap the caret to the start of the
            // document, which is why a second quote stacked under the first.
            editor.chain().insertContentAt(pos, {
                type: 'commentQuote',
                attrs: {
                    commentId: String(id),
                    author,
                    excerpt,
                },
            }).focus().run();
            this.rememberCaret();
            this.flushPush();
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

            if (typeof this.quoteListenerOff === 'function') {
                this.quoteListenerOff();
                this.quoteListenerOff = null;
            }

            if (this.mentionPopupEl) {
                this.mentionPopupEl.remove();
                this.mentionPopupEl = null;
            }

            if (editor && !editor.isDestroyed) {
                editor.destroy();
            }

            editor = null;

            if (this.$refs?.editor) {
                this.$refs.editor.innerHTML = '';
            }
        },

        /**
         * HTML to sync to Livewire. TipTap's isEmpty is text-based: an empty
         * table/image-only doc is "empty" even though it has markup we must keep.
         * Only the default blank paragraph collapses to '' for required rules.
         */
        currentHtml() {
            if (!editor || editor.isDestroyed) {
                return '';
            }

            const html = editor.getHTML();

            if (!editor.isEmpty) {
                return html;
            }

            if (/<(table|img|ul|ol|pre|blockquote)\b/i.test(html)) {
                return html;
            }

            return '';
        },

        /** Full document HTML (including text-empty structural markup). */
        rawHtml() {
            if (!editor || editor.isDestroyed) {
                return '';
            }

            return editor.getHTML();
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
            this.$wire?.set?.(this.property, html, false);
        },

        applyIncoming(incoming) {
            if (!editor || editor.isDestroyed) {
                return;
            }

            const next = incoming ?? '';

            if (next === this.lastSynced || next === this.currentHtml()) {
                return;
            }

            // TipTap owns the document while the user edits. Livewire snapshots
            // during upload/refresh are often stale and must not call setContent.
            // The only server write we mirror is clearing the field after submit
            // (addComment sets commentBody to '').
            if (next !== '') {
                return;
            }

            this.lastSynced = '';
            editor.commands.setContent('', { emitUpdate: false });
            this.refreshState();
        },

        refreshState() {
            if (!editor || editor.isDestroyed) {
                return;
            }

            this.rememberCaret();
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
            if (!editor || editor.isDestroyed) {
                return;
            }

            callback(editor.chain().focus()).run();
            this.refreshState();
        },

        promptLink() {
            if (!editor || editor.isDestroyed) {
                return;
            }

            const previous = editor.getAttributes('link').href || '';
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

        pickAttachment() {
            if (!this.enableInlineAttachments || this.uploading) {
                return;
            }

            this.$refs.attachmentInput?.click();
        },

        onAttachmentPicked(event) {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (!file) {
                return;
            }

            this.enqueueUpload(file);
        },

        enqueueUpload(file) {
            uploadQueue = uploadQueue
                .then(() => this.uploadAndInsert(file))
                .catch(() => {});
        },

        async uploadAndInsert(file) {
            if (!this.enableInlineAttachments || !editor || editor.isDestroyed) {
                return;
            }

            if (!this.inlineUploadUrl) {
                console.error('rich-text-editor: inlineUploadUrl is missing');
                window.alert(this.labels.attachFailed || 'Upload failed');

                return;
            }

            this.uploading = true;

            try {
                const body = new FormData();
                body.append('file', file);

                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch(this.inlineUploadUrl, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                });

                if (!response.ok) {
                    throw new Error(`upload failed (${response.status})`);
                }

                const payload = await response.json();

                if (!payload || !payload.viewUrl) {
                    throw new Error('missing attachment payload');
                }

                if (!editor || editor.isDestroyed) {
                    throw new Error('editor was destroyed during upload');
                }

                this.insertAttachment(payload);

                // Sidecar list only; TipTap content is already local + flushed.
                // Show refreshes the attachment sidebar; create page no-ops the same action.
                Promise.resolve(this.$wire?.call?.('refreshAttachments')).catch(() => {});
            } catch (error) {
                console.error('Inline attachment upload failed', error);
                window.alert(this.labels.attachFailed || 'Upload failed');
            } finally {
                this.uploading = false;
            }
        },

        insertAttachment(payload) {
            if (!editor || editor.isDestroyed) {
                throw new Error('editor unavailable');
            }

            const html = attachmentInsertHtml(payload);
            const ok = editor.chain().focus().insertContent(html).run();

            if (!ok) {
                throw new Error('insertContent rejected markup');
            }

            this.refreshState();
            this.flushPush();
        },

        attachmentAccept() {
            return ATTACHMENT_ACCEPT;
        },
    };
}
