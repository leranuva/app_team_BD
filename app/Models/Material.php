<?php

namespace App\Models;

use App\Observers\MaterialObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(MaterialObserver::class)]
class Material extends Model
{
    use HasFactory;

    private static bool $auditManualEdits = true;

    protected $fillable = ['code', 'name', 'unit', 'category', 'is_active', 'notes'];

    public static function withoutManualAudit(callable $callback): mixed
    {
        $previous = self::$auditManualEdits;
        self::$auditManualEdits = false;

        try {
            return $callback();
        } finally {
            self::$auditManualEdits = $previous;
        }
    }

    public static function auditsManualEdits(): bool
    {
        return self::$auditManualEdits;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(MaterialListItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)->orWhere('code', 'like', $like);
        });
    }

    public function displayLabel(): string
    {
        return "{$this->name} ({$this->unit})";
    }

    public function referencePhotoUrl(): ?string
    {
        $photo = config('materials.sheetrock_photos')[$this->code] ?? null;

        return $photo === null ? null : asset("material-photos/sheetrock/{$photo}.jpg");
    }
}
