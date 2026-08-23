<?php

namespace App\Services;
# artinya class ini berada di app/Services/AuthService.php
# laravel menggunakan namespace untuk mengetahui lokasi class

use App\Models\User;
# mengambil model User agar dapat menggunakan create dan where
use Illuminate\Support\Facades\Hash;
# digunakan untuk hashing password (enkripsi)
use Illuminate\Validation\ValidationException;


class AuthServices
{
    public function register(array $data): array # artinya method menerima array $data dan mengembalikan array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password'])
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;
        #laravel membuat token yang nantinya akan di kirim ke frontend

        return [
            'user' => $user,
            'token' => $token
        ];
    }

    public function login(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah'],
            ]);
        }
        # ini merupakan proses cek dengan 2 kondisi yaitu user tidak di temukan atau password salah

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token

        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
        # ini akan menghapus token yang sedang digunakan
    }
}
