@props([
    'attachment',
    'task',
])

@php
    $isImage = $attachment->isImage();
    $isPdf = $attachment->isPdf();
    $href = ($isImage || $isPdf)
        ? route('tasks.attachments.view', $attachment)
        : route('tasks.attachments.download', $attachment);
    $previewKind = $isImage ? 'image' : ($isPdf ? 'pdf' : null);
@endphp

<li {{ $attributes->merge(['class' => 'flex items-start justify-between gap-2 text-sm']) }}>
    <div class="min-w-0 flex-1">
        @if ($isImage)
            <a href="{{ $href }}" @if ($previewKind) data-attachment-preview="{{ $previewKind }}" @endif class="block group">
                <img
                    src="{{ $href }}"
                    alt="{{ $attachment->filename }}"
                    class="max-h-36 max-w-full rounded-lg border border-gray-200 object-contain bg-gray-50 group-hover:border-primary-300 transition-colors"
                    loading="lazy"
                />
                <span class="mt-1 block text-xs text-primary-600 group-hover:text-primary-800 truncate">{{ $attachment->filename }}</span>
            </a>
        @else
            <a href="{{ $href }}" @if ($previewKind) data-attachment-preview="{{ $previewKind }}" @endif class="text-primary-600 hover:text-primary-800 hover:underline truncate block">
                {{ $attachment->filename }}
            </a>
        @endif
    </div>
    <div class="flex items-center gap-1.5 shrink-0 pt-0.5">
        <span class="text-gray-500 text-xs">{{ number_format($attachment->size / 1024, 1) }} KB</span>
        @can('deleteAttachment', [$task, $attachment])
            <button
                type="button"
                wire:click="deleteAttachment({{ $attachment->id }})"
                wire:confirm="{{ __('Delete this attachment?') }}"
                class="p-1 rounded text-gray-500 hover:text-red-700 hover:bg-red-50"
                aria-label="{{ __('Delete') }}"
            ><x-icon.x-mark /></button>
        @endcan
    </div>
</li>
