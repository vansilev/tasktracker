<?php



namespace App\Policies;



use App\Enums\Permission;

use App\Enums\TaskStatus;

use App\Models\Task;

use App\Models\TaskAttachment;

use App\Models\User;

use App\Services\TaskVisibilityService;
use App\Services\TaskWorkflowService;



class TaskPolicy

{

    public function __construct(

        private TaskVisibilityService $visibility,

    ) {}



    public function view(User $user, Task $task): bool

    {

        return $this->visibility->canView($user, $task);

    }



    public function create(User $user): bool

    {

        return $user->hasPermission(Permission::CreateTask);

    }



    public function update(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($user->hasPermission(Permission::EditAnyTask)) {

            return true;

        }



        return $task->initiator_id === $user->id

            && $user->hasPermission(Permission::EditOwnTask);

    }



    public function changePriority(User $user, Task $task): bool

    {

        if ($this->update($user, $task)) {

            return true;

        }



        return $this->isDepartmentHeadOfTask($user, $task);

    }



    public function assign(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($user->hasPermission(Permission::AssignTask)) {

            return true;

        }



        return $this->isDepartmentHeadOfTask($user, $task);

    }



    public function comment(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        return $user->isAdmin() || $user->hasPermission(Permission::Comment);

    }



    public function transition(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        return $user->isAdmin()

            || $user->hasPermission(Permission::ChangeStatus)

            || $user->hasPermission(Permission::ReviewTask);

    }



    public function transitionTo(User $user, Task $task, TaskStatus $to): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($task->initiator_id === $user->id) {

            return in_array($to, app(TaskWorkflowService::class)->computeTransitions($user, $task), true);

        }



        if ($this->isReviewTransition($task->status, $to)) {

            return $user->hasPermission(Permission::ReviewTask);

        }



        return $user->hasPermission(Permission::ChangeStatus);

    }



    private function isReviewTransition(TaskStatus $from, TaskStatus $to): bool

    {

        return $from === TaskStatus::OnReview

            && in_array($to, [TaskStatus::Completed, TaskStatus::Rework], true);

    }



    public function manageChecklist(User $user, Task $task): bool

    {

        return $this->update($user, $task);

    }



    public function toggleChecklist(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($task->assignee_id === $user->id) {

            return true;

        }



        return $this->isDepartmentHeadOfTask($user, $task);

    }



    public function manageWatchers(User $user, Task $task): bool

    {

        return $this->update($user, $task);

    }



    public function updateResultUrl(User $user, Task $task): bool

    {

        if (! $this->visibility->canView($user, $task)) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($task->assignee_id === $user->id || $task->initiator_id === $user->id) {

            return true;

        }



        return $this->update($user, $task);

    }



    public function uploadAttachment(User $user, Task $task): bool

    {

        return $this->update($user, $task) || $this->comment($user, $task);

    }



    public function deleteAttachment(User $user, Task $task, TaskAttachment $attachment): bool

    {

        if ($attachment->task_id !== $task->id) {

            return false;

        }



        if ($user->isAdmin()) {

            return true;

        }



        if ($attachment->uploaded_by === $user->id) {

            return true;

        }



        return $this->update($user, $task);

    }



    public function editComment(User $user, Task $task, User $author): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($author->id !== $user->id) {
            return false;
        }

        return $this->comment($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }

    private function isDepartmentHeadOfTask(User $user, Task $task): bool
    {
        return $user->headedDepartments()
            ->where('id', $task->department_id)
            ->exists();
    }
}


