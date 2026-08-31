@props([
    'model',
    'placeholder' => '',
    'ariaLabel' => null,
    'minHeight' => '8rem',
    'key' => null,
    'enableMentions' => false,
    'enableInlineAttachments' => false,
    'enableCommentQuotes' => false,
    'inlineUploadUrl' => null,
])

@php
    $editorKey = $key ?? 'rte-'.$model;

    // Split out rather than appended per button so no two conflicting Tailwind
    // utilities (px-*, text-*) ever land on the same element.
    $buttonBase = 'inline-flex h-8 min-w-[2rem] items-center justify-center gap-1 rounded-md border border-transparent leading-none transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 aria-pressed:border-indigo-200 aria-pressed:bg-indigo-50 aria-pressed:text-indigo-700 disabled:pointer-events-none disabled:opacity-40';
    $neutral = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900';

    $btn = $buttonBase.' '.$neutral.' px-1.5 text-sm';
    $btnSmall = $buttonBase.' '.$neutral.' px-1.5 text-xs font-semibold';
    $btnWide = $buttonBase.' '.$neutral.' px-2 text-xs';
    $btnDanger = $buttonBase.' text-red-600 hover:bg-red-50 hover:text-red-700 px-2 text-xs';

    $separator = 'mx-0.5 h-5 w-px shrink-0 bg-gray-200';

    $icon = 'h-4 w-4 shrink-0';
@endphp

{{-- wire:ignore.self keeps the Alpine/TipTap instance alive while still letting
     Livewire morph the optional banner (quote chip) between toolbar and editor.
     Toolbar + ProseMirror stay on nested wire:ignore so remorph does not rebuild TipTap. --}}
<div {{ $attributes->merge(['class' => 'rich-text-editor']) }}>
    <div class="overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
        <div
            wire:key="{{ $editorKey }}"
            wire:ignore.self
            @if ($enableInlineAttachments) data-inline-attachments="true" @endif
            x-data="richTextEditor({
                property: @js($model),
                placeholder: @js($placeholder),
                ariaLabel: @js($ariaLabel),
                enableMentions: @js((bool) $enableMentions),
                enableInlineAttachments: @js((bool) $enableInlineAttachments),
                enableCommentQuotes: @js((bool) $enableCommentQuotes),
                inlineUploadUrl: @js($inlineUploadUrl),
                labels: {
                    linkPrompt: @js(__('editor.link_prompt')),
                    linkInvalid: @js(__('editor.link_invalid')),
                    mentionList: @js(__('editor.mention_list')),
                    mentionEmpty: @js(__('editor.mention_empty')),
                    attach: @js(__('editor.attach')),
                    attachFailed: @js(__('editor.attach_failed')),
                },
            })"
            x-on:livewire:navigating.window="teardown()"
        >
        <div wire:ignore>
        <div
            role="toolbar"
            aria-label="{{ __('editor.toolbar') }}"
            class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50/80 px-1.5 py-1"
        >
            <button type="button" class="{{ $btn }} font-bold"
                    title="{{ __('editor.bold') }}" aria-label="{{ __('editor.bold') }}"
                    :aria-pressed="pressed('bold')"
                    @mousedown.prevent @click="run((c) => c.toggleBold())">Ж</button>

            <button type="button" class="{{ $btn }} italic font-serif"
                    title="{{ __('editor.italic') }}" aria-label="{{ __('editor.italic') }}"
                    :aria-pressed="pressed('italic')"
                    @mousedown.prevent @click="run((c) => c.toggleItalic())">К</button>

            <button type="button" class="{{ $btn }} underline"
                    title="{{ __('editor.underline') }}" aria-label="{{ __('editor.underline') }}"
                    :aria-pressed="pressed('underline')"
                    @mousedown.prevent @click="run((c) => c.toggleUnderline())">Ч</button>

            <button type="button" class="{{ $btn }} line-through"
                    title="{{ __('editor.strike') }}" aria-label="{{ __('editor.strike') }}"
                    :aria-pressed="pressed('strike')"
                    @mousedown.prevent @click="run((c) => c.toggleStrike())">З</button>

            <span class="{{ $separator }}"></span>

            @foreach ([1, 2, 3] as $level)
                <button type="button" class="{{ $btnSmall }}"
                        title="{{ __('editor.heading_'.$level) }}" aria-label="{{ __('editor.heading_'.$level) }}"
                        :aria-pressed="pressed('h{{ $level }}')"
                        @mousedown.prevent @click="run((c) => c.toggleHeading({ level: {{ $level }} }))">H{{ $level }}</button>
            @endforeach

            <span class="{{ $separator }}"></span>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.bullet_list') }}" aria-label="{{ __('editor.bullet_list') }}"
                    :aria-pressed="pressed('bulletList')"
                    @mousedown.prevent @click="run((c) => c.toggleBulletList())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>
            </button>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.ordered_list') }}" aria-label="{{ __('editor.ordered_list') }}"
                    :aria-pressed="pressed('orderedList')"
                    @mousedown.prevent @click="run((c) => c.toggleOrderedList())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 6h12M9 12h12M9 18h12M4 5h1v3M4 11h2l-2 2h2M4 16h2v1.5H4V19h2"/></svg>
            </button>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.blockquote') }}" aria-label="{{ __('editor.blockquote') }}"
                    :aria-pressed="pressed('blockquote')"
                    @mousedown.prevent @click="run((c) => c.toggleBlockquote())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5v6h4l-2 5M15 5v6h4l-2 5"/></svg>
            </button>

            <button type="button" class="{{ $btnSmall }} font-mono"
                    title="{{ __('editor.code') }}" aria-label="{{ __('editor.code') }}"
                    :aria-pressed="pressed('code')"
                    @mousedown.prevent @click="run((c) => c.toggleCode())">&lt;/&gt;</button>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.code_block') }}" aria-label="{{ __('editor.code_block') }}"
                    :aria-pressed="pressed('codeBlock')"
                    @mousedown.prevent @click="run((c) => c.toggleCodeBlock())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5h16v14H4z M9 10l-2 2 2 2M15 10l2 2-2 2"/></svg>
            </button>

            <span class="{{ $separator }}"></span>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.link') }}" aria-label="{{ __('editor.link') }}"
                    :aria-pressed="pressed('link')"
                    @mousedown.prevent @click="promptLink()">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.8 10.2a4 4 0 010 5.6l-2.8 2.9a4 4 0 01-5.7-5.7l1.5-1.4M10.2 13.8a4 4 0 010-5.6L13 5.3a4 4 0 015.7 5.7l-1.5 1.4"/></svg>
            </button>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.link_remove') }}" aria-label="{{ __('editor.link_remove') }}"
                    :disabled="!active.link"
                    @mousedown.prevent @click="run((c) => c.extendMarkRange('link').unsetLink())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.8 10.2a4 4 0 010 5.6l-2.8 2.9a4 4 0 01-5.7-5.7l1.5-1.4M10.2 13.8a4 4 0 010-5.6L13 5.3a4 4 0 015.7 5.7l-1.5 1.4M4 4l16 16"/></svg>
            </button>

            @if ($enableInlineAttachments)
                <button type="button" class="{{ $btn }}"
                        title="{{ __('editor.attach') }}" aria-label="{{ __('editor.attach') }}"
                        :disabled="uploading"
                        @mousedown.prevent @click="pickAttachment()">
                    <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                </button>
                <input type="file"
                       class="hidden"
                       x-ref="attachmentInput"
                       accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.doc,.docx,.xls,.xlsx"
                       @change="onAttachmentPicked($event)" />
            @endif

            <span class="{{ $separator }}"></span>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.table_insert') }}" aria-label="{{ __('editor.table_insert') }}"
                    :aria-pressed="inTable ? 'true' : 'false'"
                    @mousedown.prevent @click="run((c) => c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }))">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5h16v14H4z M4 10h16M4 15h16M10 5v14M15 5v14"/></svg>
            </button>

            <span class="{{ $separator }}"></span>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.undo') }}" aria-label="{{ __('editor.undo') }}"
                    :disabled="!can.undo"
                    @mousedown.prevent @click="run((c) => c.undo())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14L4 9l5-5M4 9h9a7 7 0 010 14h-3"/></svg>
            </button>

            <button type="button" class="{{ $btn }}"
                    title="{{ __('editor.redo') }}" aria-label="{{ __('editor.redo') }}"
                    :disabled="!can.redo"
                    @mousedown.prevent @click="run((c) => c.redo())">
                <svg class="{{ $icon }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 14l5-5-5-5M20 9h-9a7 7 0 000 14h3"/></svg>
            </button>
        </div>

        {{-- Table controls only exist while the caret is inside a table, so the
             main toolbar stays readable on narrow screens. --}}
        <div x-show="inTable" x-cloak
             role="toolbar"
             aria-label="{{ __('editor.table') }}"
             class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-indigo-50/60 px-1.5 py-1 text-xs">
            <span class="px-1 text-[11px] font-medium uppercase tracking-wide text-indigo-700">{{ __('editor.table') }}</span>
            <button type="button" class="{{ $btnWide }}"
                    title="{{ __('editor.row_add') }}"
                    @mousedown.prevent @click="run((c) => c.addRowAfter())">+ {{ __('editor.row_add') }}</button>
            <button type="button" class="{{ $btnWide }}"
                    title="{{ __('editor.row_delete') }}"
                    @mousedown.prevent @click="run((c) => c.deleteRow())">− {{ __('editor.row_delete') }}</button>
            <button type="button" class="{{ $btnWide }}"
                    title="{{ __('editor.column_add') }}"
                    @mousedown.prevent @click="run((c) => c.addColumnAfter())">+ {{ __('editor.column_add') }}</button>
            <button type="button" class="{{ $btnWide }}"
                    title="{{ __('editor.column_delete') }}"
                    @mousedown.prevent @click="run((c) => c.deleteColumn())">− {{ __('editor.column_delete') }}</button>
            <button type="button" class="{{ $btnDanger }}"
                    title="{{ __('editor.table_delete') }}"
                    @mousedown.prevent @click="run((c) => c.deleteTable())">{{ __('editor.table_delete') }}</button>
        </div>
        </div>

        <div>
            @isset($banner)
                {{ $banner }}
            @endisset
        </div>

        <div wire:ignore class="rte-surface relative">
            @if ($placeholder !== '')
                <p x-show="isEmpty" x-cloak
                   class="pointer-events-none absolute left-3 top-3 m-0 select-none text-sm text-gray-400">{{ $placeholder }}</p>
            @endif
            <div x-ref="editor"
                 class="prose prose-sm max-w-none px-3 pt-2 pb-3 text-gray-800"
                 style="min-height: {{ $minHeight }}"></div>
        </div>
        </div>
    </div>
</div>
