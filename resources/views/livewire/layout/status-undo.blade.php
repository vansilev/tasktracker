<?php

use App\Services\TaskWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    #[On('task-undo-status')]
    public function undo(string $token): void
    {
        try {
            $done = app(TaskWorkflowService::class)->undo(auth()->user(), $token);
            if ($done < 1) {
                $this->js('window.uiToast('.json_encode(__('Nothing to undo')).')');

                return;
            }

            $this->js('window.uiToast('.json_encode(__('Status restored')).')');
            $this->dispatch('task-peek-updated');
            $this->dispatch('task-status-undone');
        } catch (\InvalidArgumentException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage() ?: __('Nothing to undo')).')');
        } catch (AuthorizationException $e) {
            $this->js('window.uiToast('.json_encode($e->getMessage() ?: __('Nothing to undo')).')');
        }
    }
}; ?>

<div class="hidden" data-ui="status-undo" aria-hidden="true"></div>
