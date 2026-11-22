<?php

namespace App\Services;

use App\Exceptions\ScopePhotoOriginalMissing;
use App\Models\JobScope;
use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ScopePhotoService
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function store(JobScope $scope, User $uploader, UploadedFile $file, array $meta = []): ScopePhoto
    {
        $scope = JobScope::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($scope->getKey());
        Gate::forUser($uploader)->authorize('create', [ScopePhoto::class, $scope]);

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'No valid photo was received.']);
        }

        $data = Validator::make(['file' => $file] + $meta, [
            'file' => ['bail', 'required', 'max:10240', 'image', 'mimetypes:image/jpeg,image/png,image/webp'],
            'caption' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'taken_at' => ['nullable', 'date'],
        ], [
            'file.max' => 'The photo must be 10 MB or smaller.',
            'file.image' => 'Upload a JPEG, PNG or WebP image.',
            'file.mimetypes' => 'Upload a JPEG, PNG or WebP image.',
        ])->validate();

        $mime = $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw ValidationException::withMessages(['file' => 'The photo format is invalid.']);
        $takenAt = isset($data['taken_at'])
            ? CarbonImmutable::parse($data['taken_at'], config('app.business_timezone'))->utc()
            : ($this->exifTakenAt($file, $mime) ?? CarbonImmutable::now('UTC'));
        $directory = "scopes/{$scope->job_site_id}/{$scope->id}";
        $filename = Str::uuid().'.'.$extension;
        $disk = Storage::disk('s3');
        $path = $directory.'/'.$filename;

        try {
            if ($disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']) === false) {
                throw new RuntimeException('Could not save the photo to S3.');
            }
            $size = $disk->size($path);

            return DB::transaction(function () use ($scope, $uploader, $file, $data, $path, $mime, $extension, $takenAt, $size): ScopePhoto {
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
                $photo = new ScopePhoto(Arr::only($data, ['caption', 'lat', 'lng']) + [
                    'job_scope_id' => $scope->id,
                    'job_site_id' => $scope->job_site_id,
                    'uploaded_by' => $uploader->id,
                    'path' => $path,
                    'original_name' => Str::limit($name !== '' ? $name : 'photo.'.$extension, 255, ''),
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'taken_at' => $takenAt,
                ]);
                $photo->recordedBy($uploader)->save();

                return $photo;
            });
        } catch (Throwable $exception) {
            rescue(fn () => $disk->delete($path));
            throw $exception;
        }
    }

    public function archive(ScopePhoto $photo, User $actor, ?string $reason = null): ScopePhoto
    {
        return $this->setArchived($photo, $actor, true, $reason);
    }

    public function restore(ScopePhoto $photo, User $actor): ScopePhoto
    {
        return $this->setArchived($photo, $actor, false);
    }

    public function updateCaption(ScopePhoto $photo, User $actor, ?string $caption): ScopePhoto
    {
        $caption = $this->caption($caption);

        return DB::transaction(function () use ($photo, $actor, $caption): ScopePhoto {
            $record = ScopePhoto::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($photo->id);
            Gate::forUser($actor)->authorize('view', $record);
            Validator::make(['caption' => $caption], ['caption' => ['nullable', 'string', 'max:500']])->validate();

            if ($record->caption !== $caption) {
                $record->recordedBy($actor)->forceFill(['caption' => $caption])->save();
            }

            return $record;
        }, attempts: 3);
    }

    private function setArchived(ScopePhoto $photo, User $actor, bool $archive, ?string $reason = null): ScopePhoto
    {
        return DB::transaction(function () use ($photo, $actor, $archive, $reason): ScopePhoto {
            $record = ScopePhoto::withoutGlobalScope(LogisticsVisibilityScope::class)->lockForUpdate()->findOrFail($photo->id);
            Gate::forUser($actor)->authorize($archive ? 'archive' : 'restore', $record);

            if (($record->archived_at !== null) !== $archive) {
                if ($archive) {
                    Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:1000']])->validate();
                }
                if (! $archive && ! Storage::disk('s3')->exists($record->path)) {
                    Log::warning('The original file is no longer available', [
                        'photo_id' => $record->id,
                        'path' => $record->path,
                    ]);
                    throw new ScopePhotoOriginalMissing;
                }

                $record->recordedBy($actor, $reason)
                    ->forceFill(['archived_at' => $archive ? CarbonImmutable::now('UTC') : null])->save();
            }

            return $record;
        }, attempts: 3);
    }

    private function caption(?string $caption): ?string
    {
        $caption = $caption === null ? null : trim($caption);

        return $caption === '' ? null : $caption;
    }

    private function exifTakenAt(UploadedFile $file, string $mime): ?CarbonImmutable
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $exif = @exif_read_data($file->getPathname(), 'IFD0,EXIF', true);
        } catch (\ValueError|\ErrorException) {
            return null;
        }
        $date = $exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? null;
        $offset = $exif['EXIF']['OffsetTimeOriginal'] ?? null;
        if (! is_string($date) || ! preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})$/D', $date, $parts)) {
            return null;
        }

        $iso = "{$parts[1]}-{$parts[2]}-{$parts[3]}T{$parts[4]}";
        if (is_string($offset) && preg_match('/^[+-](?:[01]\d|2[0-3]):[0-5]\d$/D', $offset)) {
            $iso .= $offset;
        }
        $parsed = date_parse($iso);

        return $parsed['error_count'] === 0 && $parsed['warning_count'] === 0
            ? CarbonImmutable::parse($iso, config('app.business_timezone'))->utc() : null;
    }
}
