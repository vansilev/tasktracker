<?php



namespace App\Services;



use App\Enums\AuthProvider;

use App\Enums\SystemType;

use App\Enums\TaskStatus;

use App\Models\Department;

use App\Models\Task;

use App\Models\User;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Hash;

use Illuminate\Validation\ValidationException;



class UserLifecycleService

{

    public function __construct(

        private TaskWorkflowService $workflow,

        private AuditLogService $audit,

    ) {}



    /**

     * @param  array{name: string, email: string, password: string, department_id?: int|null, system_type: string, telegram_chat_id?: string|null}  $data

     * @param  list<int|string>  $roleIds

     */

    public function createUser(array $data, array $roleIds = []): User

    {

        return DB::transaction(function () use ($data, $roleIds) {

            $user = User::query()->create([

                'name' => $data['name'],

                'email' => strtolower($data['email']),

                'password' => Hash::make($data['password']),

                'email_verified_at' => now(),

                'system_type' => SystemType::from($data['system_type']),

                'department_id' => $data['department_id'] ?? null,

                'telegram_chat_id' => $data['telegram_chat_id'] ?? null,

                'auth_provider' => AuthProvider::Password,

                'locale' => config('tasktracker.default_locale', 'ru'),

                'is_active' => true,

            ]);



            $user->syncRoles($roleIds);



            if ($user->system_type === SystemType::DeptHead && $user->department_id) {

                $this->syncDepartmentHead(Department::query()->findOrFail($user->department_id), $user->id);

            }



            $this->audit->log('user.created', auth()->user(), $user, null, $user->only([

                'name', 'email', 'system_type', 'department_id', 'is_active',

            ]));



            return $user;

        });

    }



    /**

     * @param  array{name: string, department_id?: int|null, system_type: string, telegram_chat_id?: string|null}  $data

     * @param  list<int|string>  $roleIds

     */

    public function updateUser(User $user, array $data, array $roleIds): void

    {

        DB::transaction(function () use ($user, $data, $roleIds) {

            $oldValues = $user->only(['name', 'department_id', 'system_type', 'telegram_chat_id']);

            $previousType = $user->system_type;

            $newType = SystemType::from($data['system_type']);

            $departmentId = $data['department_id'] ?: null;

            $previousDepartmentId = $user->department_id;



            if (
                $previousType === SystemType::Admin
                && $user->is_active
                && $newType !== SystemType::Admin
                && User::query()->where('system_type', SystemType::Admin)->where('is_active', true)->count() <= 1
            ) {
                throw ValidationException::withMessages([
                    'system_type' => [__('Cannot change the system type of the last administrator.')],
                ]);
            }



            if ($departmentId === null && $previousDepartmentId !== null) {
                $hasOpenTasks = Task::query()
                    ->where('assignee_id', $user->id)
                    ->whereIn('status', array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open()))
                    ->exists();

                if ($hasOpenTasks) {
                    throw ValidationException::withMessages([
                        'department_id' => [__('Cannot remove the department of a user with open assigned tasks.')],
                    ]);
                }
            }



            $user->update([

                'name' => $data['name'],

                'department_id' => $departmentId,

                'system_type' => $newType,

                'telegram_chat_id' => $data['telegram_chat_id'] ?? null,

            ]);



            $user->syncRoles($roleIds);



            if ($newType === SystemType::DeptHead && $departmentId) {

                Department::query()

                    ->where('head_user_id', $user->id)

                    ->where('id', '!=', $departmentId)

                    ->update(['head_user_id' => null]);



                $this->syncDepartmentHead(Department::query()->findOrFail($departmentId), $user->id);

            } elseif ($previousType === SystemType::DeptHead) {

                Department::query()->where('head_user_id', $user->id)->update(['head_user_id' => null]);

                $this->demoteHeadIfNeeded($user->id);

            }



            if ($previousDepartmentId !== $departmentId) {

                $this->syncAssigneeOpenTasksDepartment($user, $departmentId);

            }



            $this->audit->log('user.updated', auth()->user(), $user, $oldValues, $user->fresh()->only([

                'name', 'department_id', 'system_type', 'telegram_chat_id',

            ]));

        });

    }



    public function syncDepartmentHead(Department $department, ?int $newHeadUserId): void

    {

        DB::transaction(function () use ($department, $newHeadUserId) {

            if ($newHeadUserId) {

                $head = User::query()->findOrFail($newHeadUserId);



                if (! $head->is_active) {

                    throw ValidationException::withMessages([

                        'head_user_id' => [__('Cannot assign an inactive user as department head.')],

                    ]);

                }



                if ($head->department_id !== $department->id) {

                    throw ValidationException::withMessages([

                        'head_user_id' => [__('Department head must belong to this department.')],

                    ]);

                }

            }



            $previousHeadId = $department->head_user_id;



            if ($previousHeadId && $previousHeadId !== $newHeadUserId) {

                $this->demoteHeadIfNeeded($previousHeadId, $department->id);

            }



            $department->update(['head_user_id' => $newHeadUserId]);



            if (! $newHeadUserId) {

                return;

            }



            $head = User::query()->findOrFail($newHeadUserId);



            $updates = [];

            if (! $head->isAdmin()) {

                $updates['system_type'] = SystemType::DeptHead;

            }



            $head->update($updates);

        });

    }



    public function deactivate(User $user): void

    {

        if ($user->isAdmin() && User::query()->where('system_type', SystemType::Admin)->where('is_active', true)->count() <= 1) {

            throw ValidationException::withMessages([

                'user' => [__('Cannot deactivate the last administrator.')],

            ]);

        }



        DB::transaction(function () use ($user) {

            $this->reassignOpenTasks($user);



            $user->update(['is_active' => false]);



            if ($user->department_id) {

                Department::query()

                    ->where('head_user_id', $user->id)

                    ->update(['head_user_id' => null]);

            }



            $this->demoteHeadIfNeeded($user->id);



            $this->audit->log('user.deactivated', auth()->user(), $user, ['is_active' => true], ['is_active' => false]);

        });

    }



    public function activate(User $user): void

    {

        $user->update(['is_active' => true]);



        $this->audit->log('user.activated', auth()->user(), $user, ['is_active' => false], ['is_active' => true]);

    }



    private function reassignOpenTasks(User $user): void

    {

        $openStatuses = array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open());



        $hasOpenTasks = Task::query()

            ->where('assignee_id', $user->id)

            ->whereIn('status', $openStatuses)

            ->exists();



        if (! $hasOpenTasks) {

            return;

        }



        $user->loadMissing('department.head');

        $head = $user->department?->head;



        if (! $head || ! $head->is_active || $head->id === $user->id) {

            throw ValidationException::withMessages([

                'user' => [__('task.deactivation_requires_department_head')],

            ]);

        }



        Task::query()

            ->where('assignee_id', $user->id)

            ->whereIn('status', $openStatuses)

            ->each(function (Task $task) use ($head, $user) {

                $oldAssignee = (string) $task->assignee_id;



                $task->update([

                    'assignee_id' => $head->id,

                    'department_id' => $head->department_id ?? $task->department_id,

                ]);



                $this->workflow->logHistory(

                    $task,

                    'assignee_id',

                    $oldAssignee,

                    (string) $head->id,

                    auth()->user() ?? $user,

                );

            });

    }



    private function syncAssigneeOpenTasksDepartment(User $user, ?int $newDepartmentId): void

    {

        if ($newDepartmentId === null) {
            return;
        }

        $openStatuses = array_map(fn (TaskStatus $status) => $status->value, TaskStatus::open());

        $actor = auth()->user() ?? $user;



        Task::query()

            ->where('assignee_id', $user->id)

            ->whereIn('status', $openStatuses)

            ->each(function (Task $task) use ($newDepartmentId, $actor) {

                $oldDepartmentId = (string) $task->department_id;



                $task->update(['department_id' => $newDepartmentId]);



                $this->workflow->logHistory(

                    $task,

                    'department_id',

                    $oldDepartmentId,

                    (string) $newDepartmentId,

                    $actor,

                );

            });

    }



    private function demoteHeadIfNeeded(int $userId, ?int $exceptDepartmentId = null): void

    {

        $user = User::query()->find($userId);



        if (! $user || $user->isAdmin()) {

            return;

        }



        $stillHead = Department::query()

            ->where('head_user_id', $userId)

            ->when($exceptDepartmentId, fn ($q) => $q->where('id', '!=', $exceptDepartmentId))

            ->exists();



        if (! $stillHead && $user->system_type === SystemType::DeptHead) {

            $user->update(['system_type' => SystemType::User]);

        }

    }

}


