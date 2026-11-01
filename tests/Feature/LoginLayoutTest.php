<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginLayoutTest extends TestCase
{
    public function test_login_uses_centered_card_without_promotional_panel(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back to MapR', false)
            ->assertSee('login-card', false)
            ->assertSee('password-toggle', false)
            ->assertSee('password-field', false)
            ->assertDontSee('hero-1.webp', false)
            ->assertDontSee('login-brand', false)
            ->assertDontSee('>Show</button>', false);
    }
}
