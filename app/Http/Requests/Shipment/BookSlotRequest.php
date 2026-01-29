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
            'slot_id' => 'nullable|string',
            'timeslot_id' => 'nullable|string',
            'date' => 'nullable|date',
            'time_from' => 'nullable|string',
            'time_to' => 'nullable|string',
        ];
    }
}
