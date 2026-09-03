@props([
    'comment',
    'mine' => false,
    'stacked' => false,
    'compact' => false,
    'canComment' => false,
    'canUploadAttachment' => false,
    'editing' => false,
    'inlineUploadUrl' => null,
    'task' => null,
])

@php
    $name = $comment->author?->name ?? '?';
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    $initials = $initials !== '' ? $initials : '?';
    $quotedList = collect();
    if (! $compact) {
        $quotedList = $comment->quotesAreInline()
            ? collect()
            : ($comment->relationLoaded('quotedComments') && $comment->quotedComments->isNotEmpty()
                ? $comment->quotedComments
                : collect($comment->parent ? [$comment->parent] : []));
    }
@endphp

<div
    {{ $attributes->merge(['class' => 'flex min-w-0 max-w-full gap-2 '.($mine ? 'flex-row-reverse' : '')]) }}
    data-ui="message"
    @if ($mine) data-mine="true" @endif
>
    @if ($stacked)
        <div class="w-7 shrink-0" aria-hidden="true"></div>
    @else
        <div @class([
            'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
            'bg-indigo-600 text-white' => $mine,
            'bg-gray-200 text-gray-700' => ! $mine,
        ])>
            {{ $initials }}
        </div>
    @endif

    <div @class(['min-w-0 w-full max-w-full', 'text-right' => $mine])>
        <div class="inline-block max-w-full text-left align-top">
        @unless ($stacked)
            <p @class(['mb-0.5 text-[11px] text-gray-500', 'text-right' => $mine])>
                @unless ($mine)
                    <span class="font-medium text-gray-700">{{ $name }}</span>
                    <span aria-hidden="true">·</span>
                @endunless
                <time datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->format('d.m.Y H:i') }}</time>
                @if ($comment->edited_at)
                    <span class="text-gray-400">({{ __('task.edited') }})</span>
                @endif
            </p>
        @endunless

        @if ($editing)
            <form wire:submit="saveCommentEdit" class="mt-1 space-y-2">
                <x-rich-text-editor
                    model="editCommentBody"
                    key="task-edit-comment-{{ $comment->id }}"
                    min-height="4rem"
                    :enable-mentions="true"
                    :enable-inline-attachments="$canUploadAttachment"
                    :inline-upload-url="$canUploadAttachment ? $inlineUploadUrl : null"
                    :aria-label="__('Comments')"
                />
                <x-input-error :messages="$errors->get('editCommentBody')" />
                <div class="flex gap-2">
                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="$set('editingCommentId', null)">{{ __('Cancel') }}</x-secondary-button>
                </div>
            </form>
        @else
            <div @class([
                'min-w-0 max-w-full overflow-hidden rounded-2xl px-3 py-2 text-sm text-gray-800',
                'rounded-br-md bg-indigo-50 ring-1 ring-indigo-100' => $mine,
                'rounded-bl-md bg-gray-50 ring-1 ring-gray-100' => ! $mine,
            ])>
                @foreach ($quotedList as $quoted)
                    <div @class([
                        'mb-1.5 max-w-full rounded-md py-1.5 pl-2.5 pr-2',
                        'border-l-4 border-indigo-400 bg-white/70' => $mine,
                        'border-l-4 border-gray-300 bg-white' => ! $mine,
                    ])>
                        <p class="text-xs font-semibold leading-snug text-gray-800">{{ $quoted->author?->name }}</p>
                        @if ($quoted->quoteExcerpt() !== '')
                            <p class="mt-0.5 text-xs leading-snug text-gray-600">{{ $quoted->quoteExcerpt() }}</p>
                        @endif
                    </div>
                @endforeach

                <div class="prose prose-sm mt-0 max-w-none break-words text-gray-800 [&_a]:break-all [&_p]:mb-1 [&_p:last-child]:mb-0 [&_pre]:max-w-full [&_table]:max-w-full">
                    {!! $comment->renderedBody() !!}
                </div>

                @if ($task && $comment->relationLoaded('attachments') && $comment->attachments->isNotEmpty())
                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($comment->attachments as $attachment)
                            <x-attachment-item :attachment="$attachment" :task="$task" class="w-full min-w-0" />
                        @endforeach
                    </ul>
                @endif
            </div>

            @if (! $compact && ($canComment || $comment->reactions->isNotEmpty() || $comment->canBeEditedBy(auth()->user())))
                <div @class(['mt-1 flex flex-wrap items-center gap-1', 'justify-end' => $mine])>
                    @if ($canComment || $comment->reactions->isNotEmpty())
                        @foreach ($comment->reactions->groupBy('emoji') as $emoji => $group)
                            @php
                                $reacted = $group->contains(fn ($reaction) => (int) $reaction->user_id === (int) auth()->id());
                            @endphp
                            @if ($canComment)
                                <button type="button"
                                        wire:click="toggleReaction({{ $comment->id }}, '{{ $emoji }}')"
                                        class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs leading-none {{ $reacted ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                                    <span>{{ $emoji }}</span>
                                    <span class="tabular-nums">{{ $group->count() }}</span>
                                </button>
                            @else
                                <span class="inline-flex items-center gap-0.5 rounded-full bg-gray-50 px-1.5 py-0.5 text-xs text-gray-600">
                                    <span>{{ $emoji }}</span>
                                    <span class="tabular-nums">{{ $group->count() }}</span>
                                </span>
                            @endif
                        @endforeach
                        @if ($canComment)
                            <div class="relative"
                                 x-data="{
                                     open: false,
                                     style: '',
                                     togglePicker() {
                                         this.open = ! this.open;
                                         if (! this.open) return;
                                         this.$nextTick(() => {
                                             const btn = this.$refs.trigger;
                                             const panel = this.$refs.panel;
                                             if (! btn || ! panel) return;
                                             const r = btn.getBoundingClientRect();
                                             const h = panel.offsetHeight || 108;
                                             const w = panel.offsetWidth || 216;
                                             let top = r.top - h - 6;
                                             if (top < 8) top = r.bottom + 6;
                                             if (top + h > window.innerHeight - 8) {
                                                 top = Math.max(8, window.innerHeight - h - 8);
                                             }
                                             let left = r.left;
                                             if (left + w > window.innerWidth - 8) {
                                                 left = Math.max(8, window.innerWidth - w - 8);
                                             }
                                             this.style = 'top:' + top + 'px;left:' + left + 'px';
                                         });
                                     }
                                 }"
                                 @click.outside="open = false">
                                <button type="button"
                                        x-ref="trigger"
                                        @click="togglePicker()"
                                        class="inline-flex h-6 w-6 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                        aria-label="{{ __('Add reaction') }}"
                                        title="{{ __('Add reaction') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </button>
                                <div x-ref="panel"
                                     x-show="open"
                                     x-cloak
                                     x-transition
                                     class="fixed z-50 w-56 rounded-lg bg-white p-1.5 shadow-lg ring-1 ring-black/10"
                                     :style="style"
                                     style="display: none;">
                                    <div class="grid grid-cols-8 gap-0.5">
                                        @foreach (\App\Models\TaskCommentReaction::EMOJIS as $emoji)
                                            <button type="button"
                                                    wire:click="toggleReaction({{ $comment->id }}, '{{ $emoji }}')"
                                                    @click="open = false"
                                                    class="flex h-7 w-7 items-center justify-center rounded-md text-base leading-none hover:bg-gray-100">{{ $emoji }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif

                    @if ($canComment || $comment->canBeEditedBy(auth()->user()))
                        @if ($canComment)
                            <button type="button"
                                    wire:click="quoteComment({{ $comment->id }})"
                                    @mousedown.prevent
                                    class="text-xs text-indigo-600 hover:text-indigo-800">{{ __('Quote') }}</button>
                        @endif
                        @if ($comment->canBeEditedBy(auth()->user()))
                            <button type="button" wire:click="startEditComment({{ $comment->id }})" class="text-xs text-indigo-600 hover:text-indigo-800">{{ __('Edit') }}</button>
                            <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}" class="text-xs text-red-600 hover:text-red-800">{{ __('Delete') }}</button>
                        @endif
                    @endif
                </div>
            @endif
        @endif
        </div>
    </div>
</div>
