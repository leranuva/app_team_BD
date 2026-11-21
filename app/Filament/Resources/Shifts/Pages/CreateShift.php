<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Services\ShiftService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateShift extends CreateRecord
{
    protected static string $resource = ShiftResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ShiftService::class)->save(Auth::user(), null, $data);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
