<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Security\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * CU-01 Iniciar sesión / CU-03 Cerrar sesión.
 * RN-25: tras N intentos fallidos la cuenta queda bloqueada M minutos
 * (config lothub.auth.max_attempts / lock_minutes).
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['username' => 'usuario', 'password' => 'contraseña']);

        $user = User::where('username', $credentials['username'])->first();
        $fail = fn (string $message) => back()->withInput($request->only('username'))->withErrors(['username' => $message]);

        if (! $user) {
            return $fail('Usuario o contraseña incorrectos.');
        }

        if ($user->isLocked()) {
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);

            return $fail("Cuenta bloqueada por intentos fallidos. Intenta de nuevo en {$minutes} min o recupera tu contraseña.");
        }

        if (! Hash::check($credentials['password'], $user->password_hash)) {
            return $fail($this->registerFailedAttempt($user));
        }

        if (! $user->is_active) {
            return $fail('Tu cuenta está inactiva. Contacta al administrador.');
        }

        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->saveQuietly();

        Auth::login($user);
        $request->session()->regenerate();
        AuditLogger::log('LOGIN', 'users', $user->id, [], ['user_agent' => substr((string) $request->userAgent(), 0, 180)]);

        return redirect()->intended(route('home.redirect'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($id = Auth::id()) {
            AuditLogger::log('LOGOUT', 'users', $id);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Sesión cerrada correctamente.');
    }

    /** Destino según el perfil: panel para el personal, portal para clientes. */
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->hasPermission('panel.acceder')  => redirect()->route('dashboard'),
            $user->hasPermission('portal.acceder') => redirect()->route('portal.index'),
            default                                => redirect()->route('public.home'),
        };
    }

    private function registerFailedAttempt(User $user): string
    {
        $max = config('lothub.auth.max_attempts');
        $attempts = $user->failed_attempts + 1;

        if ($attempts >= $max) {
            $user->forceFill(['failed_attempts' => 0, 'locked_until' => now()->addMinutes(config('lothub.auth.lock_minutes'))])->saveQuietly();
            AuditLogger::log('CUENTA_BLOQUEADA', 'users', $user->id, [], ['ip' => request()->ip()], $user->id);

            return 'Superaste el número de intentos. La cuenta quedó bloqueada '.config('lothub.auth.lock_minutes').' minutos.';
        }

        $user->forceFill(['failed_attempts' => $attempts])->saveQuietly();
        AuditLogger::log('LOGIN_FALLIDO', 'users', $user->id, [], ['intento' => $attempts], $user->id);

        return 'Usuario o contraseña incorrectos. Te quedan '.($max - $attempts).' intento(s).';
    }
}
