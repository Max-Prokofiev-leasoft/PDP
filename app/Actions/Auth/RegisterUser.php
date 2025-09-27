<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;

class RegisterUser
{
    /**
     * Create a new user and dispatch the Registered event.
     */
    public function handle(string $name, string $email, string $plainPassword): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($plainPassword),
        ]);

        event(new Registered($user));

        return $user;
    }
}
