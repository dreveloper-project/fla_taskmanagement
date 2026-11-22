<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-system') ?? false;
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
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[a-z0-9_.]+\z/', Rule::unique(User::class, 'username')->ignore($this->user())],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->user())],
            'phone' => ['required', 'string', 'max:30', 'regex:/\A\+?[0-9][0-9\s().-]{7,28}[0-9]\z/'],
            'profile_picture' => ['bail', 'nullable', Rule::prohibitedIf($this->boolean('remove_picture')), 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
            'remove_picture' => ['sometimes', 'boolean'],
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
            'profile_picture.prohibited' => 'Pilih foto baru atau hapus foto saat ini, bukan keduanya.',
            'profile_picture.image' => 'Foto profil harus berupa gambar.',
            'profile_picture.mimes' => 'Foto profil harus berupa JPG, PNG, atau WebP.',
            'profile_picture.max' => 'Ukuran foto profil maksimal 4 MB.',
            'profile_picture.dimensions' => 'Dimensi foto profil maksimal 4096 × 4096 piksel.',
            'remove_picture.boolean' => 'Pilihan hapus foto tidak valid.',
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
            'profile_picture' => 'Foto profil',
        ];
    }
}
