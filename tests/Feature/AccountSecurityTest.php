<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\Security\PasswordReset;
use App\Models\Security\User;
use App\Services\Portal\PortalAccessService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Ajustes del Sprint 1: US-AUTH-004, US-AUTH-007, US-AUTH-008, US-CLI-003 y US-TEC-001. */
class AccountSecurityTest extends TestCase
{
    public function test_temporary_password_forces_a_change_before_using_the_system(): void
    {
        $user = $this->user(['ASESOR_COMERCIAL'], ['must_change_password' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('contraseña temporal');

        $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => 'Secreta123', 'password' => 'NuevaClave456', 'password_confirmation' => 'NuevaClave456',
        ])->assertRedirect(route('home.redirect'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('NuevaClave456', $user->password_hash));
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->user(['ASESOR_COMERCIAL']);

        $this->actingAs($user)->from(route('password.change'))->put(route('password.change.update'), [
            'current_password' => 'incorrecta', 'password' => 'NuevaClave456', 'password_confirmation' => 'NuevaClave456',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_admin_created_users_get_a_temporary_password(): void
    {
        $admin = $this->user(['ADMINISTRADOR']);

        $this->actingAs($admin)->post(route('users.store'), [
            'identification_type_id' => 1, 'identification_number' => '123456789', 'first_name' => 'Luis', 'last_name' => 'Gómez',
            'email' => 'luis.gomez@example.test', 'username' => 'lgomez', 'password' => 'Temporal123',
            'is_active' => 1, 'must_change_password' => 1, 'roles' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertTrue(User::where('username', 'lgomez')->first()->must_change_password);
    }

    public function test_user_updates_own_profile(): void
    {
        $user = $this->user(['AUXILIAR_CARTERA']);

        $this->actingAs($user)->get(route('profile.show'))->assertOk()->assertSee($user->username);
        $this->actingAs($user)->put(route('profile.update'), ['email' => 'nuevo.correo@example.test', 'phone' => '3001234567'])
            ->assertSessionHas('success');

        $this->assertSame('nuevo.correo@example.test', $user->person->fresh()->email);
    }

    public function test_portal_invitation_requires_data_consent(): void
    {
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $client = $this->client(consent: false);

        $this->actingAs($advisor)->post(route('clients.portal.invite', $client->id))->assertSessionHas('error');
        $this->assertNull(app(PortalAccessService::class)->userOf($client));
    }

    public function test_client_activates_portal_account_with_single_use_link(): void
    {
        Mail::fake();
        $advisor = $this->user(['ASESOR_COMERCIAL']);
        $client = $this->client();
        $access = app(PortalAccessService::class);

        $this->actingAs($advisor)->post(route('clients.portal.invite', $client->id))->assertSessionHas('success');

        $user = $access->userOf($client);
        $this->assertTrue($user->roles->contains('name', 'CLIENTE'));
        $this->assertSame(PortalAccessService::INVITED, $access->status($client));

        $url = null;
        Mail::assertSent(AccountActivationMail::class, function (AccountActivationMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });
        $token = basename(parse_url($url, PHP_URL_PATH));

        auth()->logout();
        $this->get(route('account.activate', $token))->assertOk()->assertSee($user->username);
        $this->post(route('account.activate.store'), ['token' => $token, 'password' => 'MiClave2026', 'password_confirmation' => 'MiClave2026'])
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('MiClave2026', $user->fresh()->password_hash));
        $this->assertSame(PortalAccessService::ACTIVE, $access->status($client));

        // El enlace es de un solo uso y no sirve para recuperar la contraseña.
        $this->get(route('account.activate', $token))->assertRedirect(route('login'));
        $this->assertNull(PasswordReset::findValid($token, PasswordReset::RECOVERY));
    }

    public function test_https_is_forced_when_enabled(): void
    {
        config(['lothub.force_https' => true]);

        $this->get('http://localhost/ingresar')->assertStatus(301)->assertRedirect('https://localhost/ingresar');
        $this->get('https://localhost/ingresar')->assertOk()->assertHeader('Strict-Transport-Security');
    }
}
