@props([
    'attachment',
    'task',
])

@php
    $isImage = $attachment->isImage();
    $href = $isImage
        ? route('tasks.attachments.view', $attachment)
        : route('tasks.attachments.download', $attachment);
@endphp

<li {{ $attributes->merge(['class' => 'flex items-start justify-between gap-2 text-sm']) }}>
    <div class="min-w-0 flex-1">
        @if ($isImage)
            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="block group">
                <img
                    src="{{ $href }}"
                    alt="{{ $attachment->filename }}"
                    class="max-h-36 max-w-full rounded-lg border border-gray-200 object-contain bg-gray-50 group-hover:border-indigo-300 transition-colors"
                    loading="lazy"
                />
                <span class="mt-1 block text-xs text-indigo-600 group-hover:text-indigo-800 truncate">{{ $attachment->filename }}</span>
            </a>
        @else
            <a href="{{ $href }}" class="text-indigo-600 hover:text-indigo-800 hover:underline truncate block">
                {{ $attachment->filename }}
            </a>
        @endif
    </div>
    <div class="flex items-center gap-1.5 shrink-0 pt-0.5">
        <span class="text-gray-400 text-xs">{{ number_format($attachment->size / 1024, 1) }} KB</span>
        @can('deleteAttachment', [$task, $attachment])
            <button
                type="button"
                wire:click="deleteAttachment({{ $attachment->id }})"
                wire:confirm="{{ __('Delete this attachment?') }}"
                class="text-xs text-red-600 hover:text-red-800"
            >✕</button>
        @endcan
    </div>
</li>
