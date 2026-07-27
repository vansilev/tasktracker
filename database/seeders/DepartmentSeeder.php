<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'Операционный отдел',
            'Отдел маркетинга',
            'Отдел продаж',
            'Учебный отдел',
            'Финансовый отдел',
            'IT',
        ];

        foreach ($departments as $name) {
            Department::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => true, 'auto_assign_enabled' => false]
            );
        }
    }
}
