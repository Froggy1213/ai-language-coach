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

    public function test_login_regenerates_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->withSession(['pre_existing' => 'val']);
        $oldSessionId = session()->getId();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                login(email: "'.$user->email.'", password: "secret-password") {
                    id
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        $this->assertNotSame($oldSessionId, session()->getId());
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

    public function test_register_regenerates_session(): void
    {
        $this->withSession(['pre_existing' => 'val']);
        $oldSessionId = session()->getId();

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(
                    name: "RegenUser"
                    email: "regen@example.com"
                    password: "secret-password"
                    targetLanguage: "en"
                ) {
                    id
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        $this->assertNotSame($oldSessionId, session()->getId());
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

    public function test_login_is_throttled_after_ten_attempts(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        for ($i = 0; $i < 10; $i++) {
            $this->graphQL(/** @lang GraphQL */ '
                mutation {
                    login(email: "'.$user->email.'", password: "secret-password") { id }
                }
            ', [], [], self::SPA_HEADERS)->assertGraphQLErrorFree();
        }

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                login(email: "'.$user->email.'", password: "secret-password") { id }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorMessage('Rate limit for Mutation.login exceeded. Try again later.');
    }

    public function test_logout_deletes_personal_access_token_when_authenticated_by_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token');

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                logout
            }
        ', [], [], ['Authorization' => 'Bearer '.$token->plainTextToken])
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.logout', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_update_profile_updates_name_and_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        // Updating only name while keeping the same email succeeds.
        $this->graphQL(/** @lang GraphQL */ '
            mutation ($name: String!, $email: String!) {
                updateProfile(name: $name, email: $email) {
                    id
                    name
                    email
                }
            }
        ', [
            'name' => '  Nickname  ',
            'email' => 'old@example.com',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.updateProfile.name', 'Nickname')
            ->assertJsonPath('data.updateProfile.email', 'old@example.com');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        // Changing email updates it and clears email_verified_at.
        $this->graphQL(/** @lang GraphQL */ '
            mutation ($name: String!, $email: String!) {
                updateProfile(name: $name, email: $email) {
                    id
                    name
                    email
                }
            }
        ', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.updateProfile.name', 'New Name')
            ->assertJsonPath('data.updateProfile.email', 'new@example.com');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_update_profile_rejects_duplicate_email_from_another_user(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);
        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($name: String!, $email: String!) {
                updateProfile(name: $name, email: $email) {
                    id
                }
            }
        ', [
            'name' => 'Updated',
            'email' => $other->email,
        ], [], self::SPA_HEADERS)
            ->assertGraphQLValidationError('email', 'The email has already been taken.');

        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_update_password_changes_password_when_current_password_matches(): void
    {
        $user = User::factory()->create(['password' => 'old-secret']);
        Sanctum::actingAs($user);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($currentPassword: String!, $newPassword: String!) {
                updatePassword(currentPassword: $currentPassword, newPassword: $newPassword)
            }
        ', [
            'currentPassword' => 'old-secret',
            'newPassword' => 'new-secret-123',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.updatePassword', true);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $user->password));
    }

    public function test_update_password_rejects_wrong_current_password_with_password_mismatch(): void
    {
        $user = User::factory()->create(['password' => 'correct-secret']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($currentPassword: String!, $newPassword: String!) {
                updatePassword(currentPassword: $currentPassword, newPassword: $newPassword)
            }
        ', [
            'currentPassword' => 'wrong-secret',
            'newPassword' => 'new-secret-123',
        ], [], self::SPA_HEADERS);

        $this->assertSame('PASSWORD_MISMATCH', $response->json('errors.0.extensions.code'));
        $this->assertTrue(Hash::check('correct-secret', $user->fresh()->password));
    }
}
