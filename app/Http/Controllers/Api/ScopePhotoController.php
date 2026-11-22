<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ScopePhotoOriginalMissing;
use App\Http\Controllers\Controller;
use App\Http\Requests\ArchiveScopePhotoRequest;
use App\Http\Requests\QueryScopePhotosRequest;
use App\Http\Requests\StoreScopePhotoRequest;
use App\Http\Resources\ScopePhotoResource;
use App\Models\ScopePhoto;
use App\Models\Scopes\LogisticsVisibilityScope;
use App\Services\ScopePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ScopePhotoController extends Controller
{
    public function index(QueryScopePhotosRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('status');
        $photos = ScopePhoto::withoutGlobalScope(LogisticsVisibilityScope::class)
            ->forScope($request->scope()->id)
            ->with('uploadedBy:id,name')
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ScopePhotoResource::collection($photos);
    }

    public function store(StoreScopePhotoRequest $request, ScopePhotoService $photos): JsonResponse
    {
        $photo = $photos->store(
            $request->scope(),
            $request->user(),
            $request->file('file'),
            $request->safe()->only(['caption', 'lat', 'lng', 'taken_at']),
        );

        return (new ScopePhotoResource($photo->load('uploadedBy:id,name')))->response()->setStatusCode(201);
    }

    public function archive(ArchiveScopePhotoRequest $request, ScopePhotoService $photos): JsonResponse
    {
        $photo = $photos->archive($request->photo(), $request->user(), $request->validated('reason'));

        return (new ScopePhotoResource($photo->load('uploadedBy:id,name')))->response();
    }

    public function restore(Request $request, int $photo, ScopePhotoService $photos): JsonResponse
    {
        $record = ScopePhoto::withoutGlobalScope(LogisticsVisibilityScope::class)->findOrFail($photo);
        Gate::authorize('restore', $record);

        try {
            $restored = $photos->restore($record, $request->user());
        } catch (ScopePhotoOriginalMissing $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['photo' => [$exception->getMessage()]],
            ], 422);
        }

        return (new ScopePhotoResource($restored->load('uploadedBy:id,name')))->response();
    }
}
