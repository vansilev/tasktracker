<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->fill([
            'name' => $validated['name'],
        ]);

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }
}; ?>

<section>
    <header>
        <h2 class="text-sm font-semibold text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-xs text-gray-500">
            {{ __('Update your account profile name.') }}
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-4 space-y-4">
        <div>
            <x-input-label for="name" :value="__('Name')" class="text-xs text-gray-500 font-medium" />
            <x-text-input wire:model="name" id="name" name="name" type="text" class="mt-1 block w-full text-sm rounded-lg" required autofocus autocomplete="name" />
            <x-input-error class="mt-1" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" class="text-xs text-gray-500 font-medium" />
            <x-text-input value="{{ Auth::user()->email }}" id="email" name="email" type="email" class="mt-1 block w-full text-sm rounded-lg bg-gray-50" disabled />
            <p class="mt-1 text-xs text-gray-500">{{ __('Email can only be changed by an administrator.') }}</p>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            <x-action-message class="me-3" on="profile-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
