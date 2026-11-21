<?php

namespace App\Filament\Resources\Shifts\Pages;

use App\Filament\Resources\Shifts\ShiftResource;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('assignMany')->label('Asignar a varios trabajadores')
                ->authorize('create', Shift::class)
                ->schema(fn () => ShiftResource::fields(multiple: true))
                ->databaseTransaction(false)
                ->action(function (array $data): void {
                    $result = app(ShiftService::class)->assignMany(Auth::user(), Arr::except($data, 'workers'), $data['workers']);
                    $names = User::workersVisibleTo(Auth::user())->whereKey(array_keys($result))->pluck('name', 'id');
                    $message = [];
                    foreach ($result as $id => $error) {
                        $message[] = e($names[$id] ?? "Trabajador {$id}").' — '.e($error ?? 'Turno asignado');
                    }

                    Notification::make()->title('Resultado de la asignación')
                        ->body(implode('<br>', $message))
                        ->status(array_filter($result) === [] ? 'success' : 'warning')
                        ->persistent()->send();
                }),
        ];
    }
}
