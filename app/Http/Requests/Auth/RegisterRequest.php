<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! User::query()->exists();
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower(trim($this->input($field)))]);
            }
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'fullname' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-z0-9_.]+\z/', 'unique:'.User::class.',username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class.',email'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/\A\+?[0-9][0-9\s().-]{7,28}[0-9]\z/'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'max' => ':attribute melebihi batas yang diizinkan.',
            'username.min' => 'Username minimal 3 karakter.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, dan garis bawah.',
            'username.unique' => 'Username sudah digunakan.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'email.unique' => 'Email sudah digunakan.',
            'phone.regex' => 'Masukkan nomor telepon yang valid.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min' => 'Password minimal :min karakter.',
            'profile_picture.image' => 'Foto profil harus berupa gambar.',
            'profile_picture.mimes' => 'Foto profil harus berupa JPG, PNG, atau WebP.',
            'profile_picture.max' => 'Ukuran foto profil maksimal 4 MB.',
            'profile_picture.dimensions' => 'Dimensi foto profil maksimal 4096 × 4096 piksel.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'fullname' => 'Nama lengkap',
            'username' => 'Username',
            'email' => 'Email',
            'phone' => 'Nomor telepon',
            'password' => 'Password',
            'profile_picture' => 'Foto profil',
        ];
    }
}
