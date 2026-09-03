@props([
    'options' => [],
    'placeholder' => '',
    'disabled' => false,
    'searchPlaceholder' => null,
])

@php
    $searchPlaceholder = $searchPlaceholder ?? __('Type to search...');
    $listId = 'combobox-list-'.substr(md5($placeholder.json_encode($options)), 0, 12);
@endphp

<div
    x-data="uiCombobox({
        options: {{ \Illuminate\Support\Js::from(array_values($options)) }},
        placeholder: {{ \Illuminate\Support\Js::from($placeholder) }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        searchPlaceholder: {{ \Illuminate\Support\Js::from($searchPlaceholder) }},
    })"
    x-modelable="value"
    x-on:keydown.escape.window="open && close()"
    x-on:mousedown.outside="close()"
    {{ $attributes->class('relative') }}
>
    <button
        type="button"
        x-ref="trigger"
        x-on:click.stop="toggle()"
        x-bind:disabled="disabled"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="listbox"
        aria-controls="{{ $listId }}"
        class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm shadow-sm transition-colors focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400"
    >
        <span class="min-w-0 truncate" x-bind:class="selected ? 'text-gray-900' : 'text-gray-500'" x-text="selectedLabel"></span>
        <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div
        data-ui="combobox-panel"
        x-bind:class="{ 'is-open': open }"
        class="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg"
        role="presentation"
    >
        <div class="border-b border-gray-100 p-1.5">
            <input
                x-ref="search"
                type="text"
                x-model="query"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseHighlighted()"
                x-on:keydown.tab="close()"
                class="w-full rounded-md border-0 bg-gray-50 px-2.5 py-1.5 text-sm text-gray-900 ring-1 ring-gray-200 placeholder:text-gray-400 focus:bg-white focus:ring-indigo-500"
                placeholder="{{ $searchPlaceholder }}"
                autocomplete="off"
            >
        </div>
        <ul
            id="{{ $listId }}"
            x-ref="list"
            class="max-h-56 overflow-y-auto p-1"
            role="listbox"
        >
            <template x-for="(option, index) in filtered" :key="String(option.value) + '-' + index">
                <li
                    role="option"
                    x-bind:data-active="(index === highlight).toString()"
                    x-bind:aria-selected="isSelected(option).toString()"
                    x-on:mousedown.prevent="select(option)"
                    x-on:mouseenter="highlight = index"
                    class="cursor-pointer rounded-md px-2.5 py-1.5 text-sm"
                    x-bind:class="index === highlight ? 'bg-indigo-50 text-indigo-900' : 'text-gray-900'"
                    x-text="option.label"
                ></li>
            </template>
            <li x-show="filtered.length === 0" class="px-2.5 py-2 text-sm text-gray-500">
                {{ __('No options found.') }}
            </li>
        </ul>
    </div>
</div>
