<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_the_login_screen_links_to_the_configured_discourse_login(): void
    {
        config(['services.discourse.url' => 'https://forum.example.test/']);

        $this->get('/')->assertOk()
            ->assertSee('Diễn đàn · Đăng nhập bằng Zalo')
            ->assertSee('href="https://forum.example.test/login"', false);
    }

    public function test_the_forum_button_is_disabled_until_the_forum_is_configured(): void
    {
        config(['services.discourse.url' => null]);

        $this->get('/')->assertOk()->assertSee('disabled>Diễn đàn chưa mở</button>', false);
    }
}
