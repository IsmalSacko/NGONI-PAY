<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = str_contains($data['login'], '@')
            ? User::where('email', $data['login'])->first()
            : User::where('phone', $data['login'])->first();

        if (
            ! $user
            || ! Hash::check($data['password'], $user->password)
            || $user->role !== User::ROLE_SYSTEM_ADMIN
        ) {
            throw ValidationException::withMessages([
                'login' => 'Identifiants invalides.',
            ]);
        }

        $request->session()->regenerate();
        Auth::guard('web')->login($user);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
