<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(\App\Http\Requests\Auth\RegisterRequest $request, \App\Actions\Auth\RegisterUser $registerUser): RedirectResponse
    {
        $validated = $request->validated();

        $user = $registerUser->handle(
            $validated['name'],
            $validated['email'],
            $validated['password']
        );

        Auth::login($user);

        return to_route('dashboard');
    }
}
