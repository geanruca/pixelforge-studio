<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_leads_guests_to_sign_in(): void
    {
        $this->get(route('home'))->assertRedirect(route('dashboard'));
        $this->followingRedirects()->get(route('home'))->assertOk()->assertSee('login');
    }

    public function test_the_home_page_leads_signed_in_users_to_the_generator(): void
    {
        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->get(route('home'))
            ->assertOk();
    }
}
