<?php

namespace App\Http\Requests;

/** DSP-23: the CA sends the prepared case to the attending doctor; the retry key makes a double press harmless. */
class SendForDoctorCheckRequest extends DispensaryCaseActionRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [...parent::rules(), 'retry_key' => ['required', 'uuid']];
    }
}
