<?php

namespace App\Http\Requests;

class ReplacePatientIdentifierRequest extends StorePatientIdentifierRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageIdentifiers', $this->route('patient'));
    }
}
