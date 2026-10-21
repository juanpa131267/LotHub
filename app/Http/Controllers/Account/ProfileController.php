<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Catalogs\City;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * US-AUTH-007 Perfil del usuario: datos de contacto propios y cambio de contraseña.
 * US-AUTH-004 El mismo cambio de contraseña es obligatorio cuando el administrador asignó
 * una contraseña temporal (users.must_change_password; ver EnsurePasswordIsFresh).
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load('person.city', 'person.identificationType', 'roles');

        return view('account.profile', [
            'user'   => $user,
            'cities' => City::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $person = $request->user()->person;

        $data = $request->validate([
            'email'   => ['required', 'email', 'max:191', Rule::unique('people', 'email')->ignore($person->id)],
            'phone'   => ['nullable', 'string', 'max:20'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['email' => 'correo electrónico', 'phone' => 'teléfono', 'city_id' => 'municipio', 'address' => 'dirección']);

        $person->fill($data)->save();

        return back()->with('success', 'Tus datos de contacto se actualizaron.');
    }

    public function editPassword(Request $request): View
    {
        return view('account.change-password', ['forced' => $request->user()->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ], ['current_password' => 'contraseña actual', 'password' => 'nueva contraseña']);

        if (! Hash::check($request->current_password, $user->password_hash)) {
            return back()->withErrors(['current_password' => 'La contraseña actual no es correcta.']);
        }

        $forced = $user->must_change_password;
        $user->setOwnPassword($request->password);
        AuditLogger::log($forced ? 'CAMBIO_CLAVE_OBLIGATORIO' : 'CAMBIO_CLAVE', 'users', $user->id);

        return redirect()->route($forced ? 'home.redirect' : 'profile.show')->with('success', 'Contraseña actualizada.');
    }
}
