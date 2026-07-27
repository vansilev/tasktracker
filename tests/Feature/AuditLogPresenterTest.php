<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Services\AuditLogPresenter;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class AuditLogPresenterTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    public function test_action_label_returns_translated_name(): void
    {
        $presenter = app(AuditLogPresenter::class);

        $this->assertSame('Вход в систему', $presenter->actionLabel('auth.login'));
        $this->assertSame('Создана задача', $presenter->actionLabel('task.created'));
    }

    public function test_summarize_login_event(): void
    {
        $presenter = app(AuditLogPresenter::class);

        $summary = $presenter->summarize(null, [
            'email' => 'user@tcsavant.com',
            'method' => 'password',
        ], 'auth.login');

        $this->assertStringContainsString('user@tcsavant.com', $summary);
        $this->assertStringContainsString('пароль', $summary);
    }

    public function test_summarize_status_change(): void
    {
        $presenter = app(AuditLogPresenter::class);

        $summary = $presenter->summarize(
            ['status' => TaskStatus::New->value],
            ['status' => TaskStatus::InProgress->value],
            'task.status_changed',
        );

        $this->assertStringContainsString('Статус', $summary);
        $this->assertStringContainsString('→', $summary);
    }

    public function test_detail_json_returns_pretty_printed_payload(): void
    {
        $presenter = app(AuditLogPresenter::class);

        $json = $presenter->detailJson(['name' => 'Old'], ['name' => 'New']);

        $this->assertStringContainsString('"old"', $json);
        $this->assertStringContainsString('"new"', $json);
        $this->assertStringContainsString('Old', $json);
        $this->assertStringContainsString('New', $json);
    }
}
