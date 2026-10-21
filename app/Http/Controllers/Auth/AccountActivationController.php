<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Security\PasswordReset;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * US-AUTH-008 / US-CLI-003 Activar la cuenta del portal. El asesor habilita el acceso
 * (PortalAccessService) y el cliente recibe un enlace de un solo uso para crear su contraseña.
 */
class AccountActivationController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $activation = PasswordReset::findValid($token, PasswordReset::ACTIVATION);

        if (! $activation) {
            return redirect()->route('login')->with('error', 'El enlace de activación no es válido o ya venció. Pide a tu asesor uno nuevo.');
        }

        return view('auth.activate', ['token' => $token, 'user' => $activation->user]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['password' => 'contraseña']);

        $activation = PasswordReset::findValid($request->token, PasswordReset::ACTIVATION);

        if (! $activation) {
            return redirect()->route('login')->with('error', 'El enlace de activación no es válido o ya venció. Pide a tu asesor uno nuevo.');
        }

        $activation->user->setOwnPassword($request->password);
        $activation->update(['used_at' => now()]);
        AuditLogger::log('ACTIVAR_CUENTA', 'users', $activation->user_id, [], [], $activation->user_id);

        return redirect()->route('login')->with('success', 'Tu cuenta está activa. Ingresa con tu usuario '.$activation->user->username.'.');
    }
}
