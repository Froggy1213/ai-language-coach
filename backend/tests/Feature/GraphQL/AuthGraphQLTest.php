<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\EmailVerificationToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    public function test_register_sends_email_verification_notification(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'https://app.example.com');

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(
                    name: "Ada"
                    email: "verify-on-register@example.com"
                    password: "secret-password"
                    targetLanguage: "en"
                ) {
                    id
                    emailVerifiedAt
                }
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.register.emailVerifiedAt', null);

        $user = User::firstWhere('email', 'verify-on-register@example.com');
        $this->assertNotNull($user);

        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $this->assertSame('【Kirie】メールアドレスの確認', $mail->subject);
            $this->assertStringStartsWith('https://app.example.com/verify-email?', (string) $mail->actionUrl);

            parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

            return ($query['id'] ?? null) === (string) $user->id
                && ($query['hash'] ?? null) === sha1($user->email)
                && EmailVerificationToken::verifySignature(
                    (string) ($query['id'] ?? ''),
                    (string) ($query['hash'] ?? ''),
                    (int) ($query['expires'] ?? 0),
                    (string) ($query['signature'] ?? ''),
                );
        });
    }

    public function test_update_profile_sends_verification_notification_only_when_email_changes(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'same@example.com',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        // Changing only the name keeps email verified and sends no notification.
        $this->graphQL(/** @lang GraphQL */ '
            mutation ($name: String!, $email: String!) {
                updateProfile(name: $name, email: $email) {
                    id
                    emailVerifiedAt
                }
            }
        ', [
            'name' => 'New Name',
            'email' => 'same@example.com',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        Notification::assertNothingSent();

        // Changing the email clears verification and sends the verification mail to the new address.
        $this->graphQL(/** @lang GraphQL */ '
            mutation ($name: String!, $email: String!) {
                updateProfile(name: $name, email: $email) {
                    id
                    email
                    emailVerifiedAt
                }
            }
        ', [
            'name' => 'New Name',
            'email' => 'changed@example.com',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.updateProfile.email', 'changed@example.com')
            ->assertJsonPath('data.updateProfile.emailVerifiedAt', null);

        $user->refresh();
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

            return ($query['hash'] ?? null) === sha1('changed@example.com');
        });
    }

    public function test_send_email_verification_notification_requires_authentication_and_skips_verified_users(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                sendEmailVerificationNotification
            }
        ')
            ->assertGraphQLErrorMessage('Unauthenticated.');

        Notification::fake();

        $unverified = User::factory()->unverified()->create();
        Sanctum::actingAs($unverified);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                sendEmailVerificationNotification
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.sendEmailVerificationNotification', true);

        Notification::assertSentTo($unverified, VerifyEmailNotification::class);

        Notification::fake();

        $verified = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($verified);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                sendEmailVerificationNotification
            }
        ', [], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.sendEmailVerificationNotification', true);

        Notification::assertNothingSent();
    }

    public function test_verify_email_marks_user_as_verified_with_valid_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $url = EmailVerificationToken::makeUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $mutation = /** @lang GraphQL */ '
            mutation ($id: ID!, $hash: String!, $expires: Int!, $signature: String!) {
                verifyEmail(id: $id, hash: $hash, expires: $expires, signature: $signature)
            }
        ';

        $variables = [
            'id' => (string) $query['id'],
            'hash' => (string) $query['hash'],
            'expires' => (int) $query['expires'],
            'signature' => (string) $query['signature'],
        ];

        $this->graphQL($mutation, $variables, [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.verifyEmail', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Calling verifyEmail again on an already-verified account remains idempotent.
        $this->graphQL($mutation, $variables, [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.verifyEmail', true);
    }

    public function test_verify_email_rejects_expired_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = EmailVerificationToken::makeUrl($user, now()->subMinute()->getTimestamp());
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: ID!, $hash: String!, $expires: Int!, $signature: String!) {
                verifyEmail(id: $id, hash: $hash, expires: $expires, signature: $signature)
            }
        ', [
            'id' => (string) $query['id'],
            'hash' => (string) $query['hash'],
            'expires' => (int) $query['expires'],
            'signature' => (string) $query['signature'],
        ], [], self::SPA_HEADERS);

        $this->assertSame('EMAIL_VERIFICATION_EXPIRED', $response->json('errors.0.extensions.code'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verify_email_rejects_tampered_signature_or_wrong_email_hash(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'real@example.com']);
        $expires = now()->addMinutes(30)->getTimestamp();
        $validHash = sha1($user->email);
        $validSignature = EmailVerificationToken::sign((string) $user->id, $validHash, $expires);

        $mutation = /** @lang GraphQL */ '
            mutation ($id: ID!, $hash: String!, $expires: Int!, $signature: String!) {
                verifyEmail(id: $id, hash: $hash, expires: $expires, signature: $signature)
            }
        ';

        $tampered = $this->graphQL($mutation, [
            'id' => (string) $user->id,
            'hash' => $validHash,
            'expires' => $expires,
            'signature' => $validSignature.'0',
        ], [], self::SPA_HEADERS);

        $this->assertSame('EMAIL_VERIFICATION_INVALID', $tampered->json('errors.0.extensions.code'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $wrongHash = sha1('wrong@example.com');
        $wrongHashSignature = EmailVerificationToken::sign((string) $user->id, $wrongHash, $expires);

        $mismatched = $this->graphQL($mutation, [
            'id' => (string) $user->id,
            'hash' => $wrongHash,
            'expires' => $expires,
            'signature' => $wrongHashSignature,
        ], [], self::SPA_HEADERS);

        $this->assertSame('EMAIL_VERIFICATION_INVALID', $mismatched->json('errors.0.extensions.code'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_sends_reset_notification_and_hides_account_existence(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'https://app.example.com');

        $user = User::factory()->create(['email' => 'learner@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($email: String!) {
                forgotPassword(email: $email)
            }
        ', [
            'email' => $user->email,
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.forgotPassword', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $this->assertSame('【Kirie】パスワードの再設定', $mail->subject);
            $this->assertStringStartsWith('https://app.example.com/reset-password?', (string) $mail->actionUrl);

            parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

            return ($query['token'] ?? null) === $notification->token
                && ($query['email'] ?? null) === $user->email;
        });

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($email: String!) {
                forgotPassword(email: $email)
            }
        ', [
            'email' => 'unknown@example.com',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.forgotPassword', true);

        Notification::assertCount(1);
    }

    public function test_reset_password_updates_password_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'old-password',
            'remember_token' => 'initial-token',
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($email: String!) {
                forgotPassword(email: $email)
            }
        ', [
            'email' => $user->email,
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree();

        $capturedToken = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$capturedToken): bool {
            $capturedToken = $notification->token;

            return true;
        });
        $this->assertIsString($capturedToken);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($token: String!, $email: String!, $password: String!) {
                resetPassword(token: $token, email: $email, password: $password)
            }
        ', [
            'token' => $capturedToken,
            'email' => $user->email,
            'password' => 'new-secret-password',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.resetPassword', true);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $user->password));
        $this->assertNotSame('initial-token', $user->remember_token);

        // The reset token is single-use and rejected if replayed.
        $replay = $this->graphQL(/** @lang GraphQL */ '
            mutation ($token: String!, $email: String!, $password: String!) {
                resetPassword(token: $token, email: $email, password: $password)
            }
        ', [
            'token' => $capturedToken,
            'email' => $user->email,
            'password' => 'another-secret-password',
        ], [], self::SPA_HEADERS);

        $this->assertSame('PASSWORD_RESET_INVALID', $replay->json('errors.0.extensions.code'));
    }

    public function test_reset_password_rejects_invalid_token_and_validates_input(): void
    {
        $user = User::factory()->create([
            'email' => 'invalid-reset@example.com',
            'password' => 'keep-this-password',
        ]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation ($token: String!, $email: String!, $password: String!) {
                resetPassword(token: $token, email: $email, password: $password)
            }
        ', [
            'token' => 'not-a-valid-token',
            'email' => $user->email,
            'password' => 'new-secret-password',
        ], [], self::SPA_HEADERS);

        $this->assertSame('PASSWORD_RESET_INVALID', $response->json('errors.0.extensions.code'));
        $this->assertTrue(Hash::check('keep-this-password', $user->fresh()->password));

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($token: String!, $email: String!, $password: String!) {
                resetPassword(token: $token, email: $email, password: $password)
            }
        ', [
            'token' => 'not-a-valid-token',
            'email' => $user->email,
            'password' => 'short',
        ], [], self::SPA_HEADERS)
            ->assertGraphQLValidationKeys(['password']);
    }
}
