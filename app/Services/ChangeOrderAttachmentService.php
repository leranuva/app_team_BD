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
            throw ValidationException::withMessages(['attachments' => 'Attachments can only be added to drafts.']);
        }

        $files = array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
        $existing = $order->attachments()->count();
        if ($existing + count($files) > 5) {
            throw ValidationException::withMessages(['attachments' => 'A change order can have up to 5 attachments.']);
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
            'file.max' => 'Each attachment can be up to 20 MB.',
            'file.mimetypes' => 'Attach a PDF, JPEG or PNG file.',
        ])->validate();

        $mime = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw ValidationException::withMessages(['file' => 'The attachment format is invalid.']);
        $path = 'change-orders/'.$order->id.'/'.Str::uuid().'.'.$extension;

        if ($disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']) === false) {
            throw new RuntimeException('Could not save the attachment to S3.');
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
