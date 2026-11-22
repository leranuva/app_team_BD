<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChangeOrderAttachment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'change_order_id', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $hidden = ['path'];

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ChangeOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function temporaryUrl(int $minutes = 15): ?string
    {
        $order = $this->relationLoaded('changeOrder') ? $this->changeOrder : $this->changeOrder()->withTrashed()->first();
        if ($order === null || $order->trashed() || $minutes < 1) {
            return null;
        }

        try {
            $disk = Storage::disk('s3');

            return $disk->providesTemporaryUrls() && $disk->exists($this->path)
                ? $disk->temporaryUrl($this->path, now()->addMinutes($minutes))
                : null;
        } catch (FilesystemException|RuntimeException $exception) {
            report($exception);

            return null;
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('change_order_attachments')
            ->logOnly(['original_name'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
