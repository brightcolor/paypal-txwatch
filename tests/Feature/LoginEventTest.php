<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertSame(1, LoginEvent::count());
        $entry = LoginEvent::first();
        $this->assertTrue($entry->successful);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($user->email, $entry->email);
    }

    public function test_failed_login_is_recorded_with_attempted_email(): void
    {
        event(new Failed('web', null, ['email' => 'wrong@example.com', 'password' => 'x']));

        $this->assertSame(1, LoginEvent::count());
        $entry = LoginEvent::first();
        $this->assertFalse($entry->successful);
        $this->assertSame('wrong@example.com', $entry->email);
        $this->assertNull($entry->user_id);
    }
}
