<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class BookSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slot_id' => 'required|string',
            'date' => 'required|date|after:today',
            'time_from' => 'required|date_format:H:i',
            'time_to' => 'required|date_format:H:i|after:time_from',
        ];
    }
}
