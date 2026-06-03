<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    function showLoginForm() {
        if (Auth::check()) {
            return redirect()->route('versions.index');
        }
        return view('auth.login');
    }

    function login(Request $request) {
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('versions.index'));
        }

        return back()
            ->withInput($request->only('email'))
            ->with('error', 'Credenciales inválidas.');
    }

    function logout(Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
