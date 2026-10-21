<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\Security\PasswordReset;
use App\Models\Security\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * CU-02 Recuperar contraseña. El enlace lleva un token aleatorio; en la tabla
 * solo se guarda su SHA-256. Es de un solo uso y vence a los 30 minutos (RN-25).
 */
class PasswordResetController extends Controller
{
    public function showRequest(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['login' => ['required', 'string']], [], ['login' => 'usuario o correo']);

        $user = User::where('username', $request->login)
            ->orWhereHas('person', fn ($q) => $q->where('email', $request->login))
            ->first();

        if ($user && $user->is_active && $user->person?->email) {
            $token = PasswordReset::issue($user, PasswordReset::RECOVERY, config('lothub.auth.reset_token_minutes'));

            Mail::to($user->person->email)->send(new PasswordResetMail($user, route('password.reset', $token)));
        }

        // Respuesta idéntica exista o no la cuenta (no revela usuarios).
        return back()->with('success', 'Si los datos corresponden a una cuenta activa, enviamos un enlace a su correo.');
    }

    public function showReset(string $token): View|RedirectResponse
    {
        if (! PasswordReset::findValid($token, PasswordReset::RECOVERY)) {
            return redirect()->route('password.request')->with('error', 'El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        return view('auth.reset-password', compact('token'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['password' => 'contraseña']);

        $reset = PasswordReset::findValid($request->token, PasswordReset::RECOVERY);

        if (! $reset) {
            return redirect()->route('password.request')->with('error', 'El enlace no es válido o ya venció. Solicita uno nuevo.');
        }

        $reset->user->setOwnPassword($request->password);
        $reset->update(['used_at' => now()]);
        AuditLogger::log('RESTABLECER_CLAVE', 'users', $reset->user_id, [], [], $reset->user_id);

        return redirect()->route('login')->with('success', 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }
}
