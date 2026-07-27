<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Volt::route('/', 'pages.admin.index')->name('index');
        Volt::route('/departments', 'pages.admin.departments')->name('departments');
        Volt::route('/users', 'pages.admin.users')->name('users');
        Volt::route('/roles', 'pages.admin.roles')->name('roles');
        Volt::route('/roles/{role}', 'pages.admin.role-edit')->name('roles.edit');
        Volt::route('/categories', 'pages.admin.categories')->name('categories');
        Volt::route('/settings', 'pages.admin.settings')->name('settings');
        Volt::route('/import', 'pages.admin.import')->name('import');
        Volt::route('/audit', 'pages.admin.audit')->name('audit');
        Volt::route('/deleted-tasks', 'pages.admin.deleted-tasks')->name('deleted-tasks');
    });
