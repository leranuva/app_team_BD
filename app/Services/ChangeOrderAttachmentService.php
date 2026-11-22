<?php

namespace App\Services;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderAttachment;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ChangeOrderAttachmentService
{
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function storeMany(ChangeOrder $order, User $actor, array $files): array
    {
        $order = ChangeOrder::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($order->getKey());
        if (! $order->isEditable()) {
            throw ValidationException::withMessages(['attachments' => 'Solo un borrador admite adjuntos.']);
        }

        $files = array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
        $existing = $order->attachments()->count();
        if ($existing + count($files) > 5) {
            throw ValidationException::withMessages(['attachments' => 'Una orden admite como máximo 5 adjuntos.']);
        }

        $stored = [];
        $disk = Storage::disk('s3');

        try {
            foreach ($files as $file) {
                $stored[] = $this->storeOne($order, $actor, $file, $disk);
            }
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                rescue(fn () => $disk->delete($attachment->path));
                $attachment->delete();
            }
            throw $exception;
        }

        return $stored;
    }

    private function storeOne(ChangeOrder $order, User $actor, UploadedFile $file, $disk): ChangeOrderAttachment
    {
        validator(['file' => $file], [
            'file' => ['required', 'file', 'max:20480', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ], [
            'file.max' => 'Cada adjunto puede medir hasta 20 MB.',
            'file.mimetypes' => 'Adjunta un PDF, JPEG o PNG.',
        ])->validate();

        $mime = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw ValidationException::withMessages(['file' => 'El formato del adjunto no es válido.']);
        $path = 'change-orders/'.$order->id.'/'.Str::uuid().'.'.$extension;

        if ($disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']) === false) {
            throw new RuntimeException('No se pudo guardar el adjunto en S3.');
        }

        try {
            return DB::transaction(function () use ($order, $actor, $file, $disk, $path, $mime, $extension): ChangeOrderAttachment {
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

                return $order->attachments()->create([
                    'path' => $path,
                    'original_name' => Str::limit($name !== '' ? $name : 'adjunto.'.$extension, 255, ''),
                    'mime_type' => $mime,
                    'size_bytes' => $disk->size($path),
                    'uploaded_by' => $actor->id,
                ]);
            });
        } catch (Throwable $exception) {
            rescue(fn () => $disk->delete($path));
            throw $exception;
        }
    }
}
