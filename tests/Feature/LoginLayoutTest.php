<?php

namespace Tests\Feature;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LoginLayoutTest extends TestCase
{
    public function test_login_hands_old_input_and_validation_errors_to_the_form(): void
    {
        $this->withSession([
            '_old_input' => ['email' => 'resident@example.test', 'remember' => '1'],
            'errors' => (new ViewErrorBag)->put(
                'default',
                new MessageBag(['email' => ['Check your email address.']]),
            ),
        ])->get('/login')
            ->assertOk()
            ->assertSee('id="login-app"', false)
            ->assertSee('data-action="'.route('login.store').'"', false)
            ->assertSee('data-email="resident@example.test"', false)
            ->assertSee('data-remember="1"', false)
            ->assertSee('data-email-error="Check your email address."', false);
    }
}
