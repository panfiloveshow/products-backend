<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;

class LoginRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email обязателен',
            'email.email' => 'Некорректный формат email',
            'password.required' => 'Пароль обязателен',
            'password.min' => 'Пароль должен быть не менее 6 символов',
        ];
    }
}
