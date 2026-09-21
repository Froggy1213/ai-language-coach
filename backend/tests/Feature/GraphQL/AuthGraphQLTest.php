<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AuthGraphQLTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    /**
     * The SPA sends an Origin header, which is what makes Sanctum start a
     * session for the request instead of treating it as a token client.
     */
    private const SPA_HEADERS = ['Origin' => 'http://localhost:3000'];

    public function test_guests_cannot_read_their_profile(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query {
                me { id }
            }
        ')
            ->assertGraphQLErrorMessage('Unauthenticated.')
            ->assertJsonPath('data.me', null);
    }

    public function test_login_authenticates_the_user(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                login(email: "'.$user->email.'", password: "secret-password") {
                    id
                    name
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.login.id', (string) $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                login(email: "'.$user->email.'", password: "not-the-password") {
                    id
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLValidationError('email', __('auth.failed'));

        $this->assertGuest();
    }

    public function test_login_validates_its_input(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                login(email: "not-an-email", password: "") { id }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLValidationKeys(['email', 'password']);
    }

    public function test_register_creates_and_signs_in_the_user(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(
                    name: "Ada"
                    email: "ada@example.com"
                    password: "secret-password"
                    targetLanguage: "en"
                ) {
                    id
                    name
                    targetLanguage
                    currentLevel
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.register.name', 'Ada')
            ->assertJsonPath('data.register.targetLanguage', 'en')
            ->assertJsonPath('data.register.currentLevel', 'A1');

        $user = User::firstWhere('email', 'ada@example.com');

        $this->assertNotNull($user);
        $this->assertSame('en', $user->target_language);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(
                    name: "Ada"
                    email: "ada@example.com"
                    password: "secret-password"
                    targetLanguage: "en"
                ) { id }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLValidationError('email', 'The email has already been taken.');
    }

    public function test_register_rejects_an_unsupported_target_language(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(
                    name: "Ada"
                    email: "ada@example.com"
                    password: "secret-password"
                    targetLanguage: "klingon"
                ) { id }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLValidationKeys(['targetLanguage']);

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_logout_ends_the_session(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                logout
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.logout', true);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                logout
            }
        ')
            ->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_stateful_origin_gets_a_session(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query {
                me { id }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertCookie(config('session.cookie'));
    }
}
