<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SignatureConfirmation
{
    public function assertConfirmed(User $user, Request $request): void
    {
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => 'The account password does not match.',
            ]);
        }
    }
}
