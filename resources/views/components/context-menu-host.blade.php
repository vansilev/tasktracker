<div
    x-data="uiFloatHost('contextMenu')"
    data-ui="context-menu"
    data-ui-float="panel"
    class="fixed z-[90]"
    x-bind:class="{ 'is-open': $store.contextMenu.open }"
    x-bind:style="`left: ${$store.contextMenu.x}px; top: ${$store.contextMenu.y}px`"
    x-on:click.stop
    x-on:contextmenu.prevent
>
    <div
        x-ref="panel"
        class="w-64 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
        role="menu"
    >
        <template x-if="$store.contextMenu.view === 'root'">
            <div>
                <button type="button" class="flex w-full items-center px-3 py-1.5 text-left text-sm text-gray-900 hover:bg-indigo-50" x-on:click="$store.contextMenu.openTask()">
                    {{ __('Open') }}
                </button>
                <button type="button" class="flex w-full items-center px-3 py-1.5 text-left text-sm text-gray-900 hover:bg-indigo-50" x-on:click="$store.contextMenu.copyLink()">
                    {{ __('Copy link') }}
                </button>
                <template x-if="$store.contextMenu.payload?.transitions?.length">
                    <div>
                        <div class="my-1 border-t border-gray-100"></div>
                        <button type="button" class="flex w-full items-center justify-between px-3 py-1.5 text-left text-sm text-gray-900 hover:bg-indigo-50" x-on:click="$store.contextMenu.view = 'status'">
                            <span>{{ __('Change status') }}</span>
                            <span class="text-gray-400">›</span>
                        </button>
                    </div>
                </template>
                <template x-if="$store.contextMenu.payload?.canAssign && $store.contextMenu.payload?.assignees?.length">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-1.5 text-left text-sm text-gray-900 hover:bg-indigo-50" x-on:click="$store.contextMenu.view = 'assign'">
                        <span>{{ __('Assign') }}</span>
                        <span class="text-gray-400">›</span>
                    </button>
                </template>
            </div>
        </template>

        <template x-if="$store.contextMenu.view === 'status'">
            <div>
                <button type="button" class="flex w-full items-center px-3 py-1.5 text-left text-xs font-medium text-gray-500 hover:bg-gray-50" x-on:click="$store.contextMenu.view = 'root'">
                    ← {{ __('Change status') }}
                </button>
                <template x-for="item in ($store.contextMenu.payload?.transitions || [])" :key="item.value">
                    <button
                        type="button"
                        class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                        x-bind:class="item.destructive ? 'text-red-700' : 'text-gray-900'"
                        x-on:click="$store.contextMenu.pickStatus(item)"
                        x-text="item.label"
                    ></button>
                </template>
            </div>
        </template>

        <template x-if="$store.contextMenu.view === 'assign'">
            <div>
                <button type="button" class="flex w-full items-center px-3 py-1.5 text-left text-xs font-medium text-gray-500 hover:bg-gray-50" x-on:click="$store.contextMenu.view = 'root'">
                    ← {{ __('Assign') }}
                </button>
                <div class="max-h-56 overflow-y-auto">
                    <template x-for="person in ($store.contextMenu.payload?.assignees || [])" :key="person.id">
                        <button
                            type="button"
                            class="flex w-full items-center px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                            x-bind:class="Number(person.id) === Number($store.contextMenu.payload.assigneeId) ? 'text-indigo-700 font-medium' : 'text-gray-900'"
                            x-on:click="$store.contextMenu.pickAssignee(person)"
                            x-text="person.name"
                        ></button>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="$store.contextMenu.view === 'comment'">
            <div class="p-2">
                <p class="mb-1.5 px-1 text-xs text-gray-500" x-text="$store.contextMenu.commentTarget?.label"></p>
                <textarea
                    rows="3"
                    class="w-full rounded-md border-gray-200 text-sm"
                    placeholder="{{ __('task.comment_required') }}"
                    x-model="$store.contextMenu.comment"
                    x-on:keydown.enter.meta.prevent="$store.contextMenu.submitComment()"
                    x-on:keydown.enter.ctrl.prevent="$store.contextMenu.submitComment()"
                ></textarea>
                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" x-on:click="$store.contextMenu.view = 'root'">{{ __('Cancel') }}</button>
                    <button type="button" class="rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-500" x-on:click="$store.contextMenu.submitComment()">{{ __('Confirm') }}</button>
                </div>
            </div>
        </template>
    </div>
</div>
