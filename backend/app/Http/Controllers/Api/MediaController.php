<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conference;
use App\Models\Media;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

class MediaController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of media for a specific conference.
     */
    public function index(Request $request, Conference $conference): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'collection' => 'nullable|string|in:images,documents,general',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $query = $conference->media();

        // Filter by collection if specified
        if ($request->filled('collection')) {
            $query->where('collection_name', $request->collection);
        }

        // Search functionality - only search in file_name
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('file_name', 'like', "%{$search}%");
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $media = $query->paginate($perPage);

        // Transform media data
        $transformedMedia = $media->getCollection()->map(function ($mediaItem) use ($conference) {
            return [
                'id' => $mediaItem->id,
                'uuid' => $mediaItem->uuid,
                'collection_name' => $mediaItem->collection_name,
                'file_name' => $mediaItem->file_name,
                'mime_type' => $mediaItem->mime_type,
                'size' => $mediaItem->size,
                'size_human' => $mediaItem->humanReadableSize,
                'url' => route('api.media.serve', ['conference' => $conference->id, 'mediaId' => $mediaItem->id]),
                'download_url' => route('api.media.download', ['conference' => $conference->id, 'mediaId' => $mediaItem->id]),
                'conversions' => $this->getConversions($mediaItem),
                'uploaded_by' => $mediaItem->uploaded_by,
                'created_at' => $mediaItem->created_at,
                'updated_at' => $mediaItem->updated_at,
                'can_update' => auth()->user() ? auth()->user()->can('update', $mediaItem) : false,
                'can_delete' => auth()->user() ? auth()->user()->can('delete', $mediaItem) : false,
            ];
        });

        $media->setCollection($transformedMedia);

        return $this->paginatedResponse($media, null, 'Media retrieved successfully');
    }

    /**
     * Store a newly created media resource.
     */
    public function store(Request $request, Conference $conference): JsonResponse
    {
        // Check if user can create media for this conference
        if (!Gate::allows('create', [Media::class, $conference])) {
            return $this->errorResponse('You are not authorized to create media for this conference', 403);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:51200', // 50MB max
            'collection' => [
                'required',
                'string',
                Rule::in(['images', 'documents', 'general'])
            ],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $file = $request->file('file');
        $collection = $request->collection;

        // Validate file type based on collection
        $mimeValidation = $this->validateMimeType($file, $collection);
        if (!$mimeValidation['valid']) {
            return $this->errorResponse($mimeValidation['message'], 422);
        }

        try {
            $media = $conference->addMediaFromRequest('file')
                ->usingFileName($file->getClientOriginalName())
                ->withCustomProperties(['uploaded_by' => auth()->id()])
                ->toMediaCollection($collection);

            $transformedMedia = [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'collection_name' => $media->collection_name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'size_human' => $media->humanReadableSize,
                'url' => route('api.media.serve', ['conference' => $conference->id, 'mediaId' => $media->id]),
                'download_url' => route('api.media.download', ['conference' => $conference->id, 'mediaId' => $media->id]),
                'conversions' => $this->getConversions($media),
                'uploaded_by' => auth()->id(),
                'created_at' => $media->created_at,
                'updated_at' => $media->updated_at,
                'can_update' => auth()->user()->can('update', $media),
                'can_delete' => auth()->user()->can('delete', $media),
            ];

            return $this->successResponse($transformedMedia, 'Media uploaded successfully', 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to upload media: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified media resource.
     */
    public function show(Conference $conference, Media $media): JsonResponse
    {
        // Ensure media belongs to the conference
        if ($media->model_id !== $conference->id || $media->model_type !== Conference::class) {
            return $this->errorResponse('Media not found for this conference', 404);
        }

        $transformedMedia = [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'collection_name' => $media->collection_name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'size_human' => $media->humanReadableSize,
            'url' => route('api.media.serve', ['conference' => $conference->id, 'mediaId' => $media->id]),
            'download_url' => route('api.media.download', ['conference' => $conference->id, 'mediaId' => $media->id]),
            'conversions' => $this->getConversions($media),
            'uploaded_by' => $media->getCustomProperty('uploaded_by'),
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
            'can_update' => auth()->user() ? auth()->user()->can('update', $media) : false,
            'can_delete' => auth()->user() ? auth()->user()->can('delete', $media) : false,
        ];

        return $this->successResponse($transformedMedia, 'Media retrieved successfully');
    }

    /**
     * Update the specified media resource.
     */
    public function update(Request $request, Conference $conference, Media $media): JsonResponse
    {
        // Ensure media belongs to the conference
        if ($media->model_id !== $conference->id || $media->model_type !== Conference::class) {
            return $this->errorResponse('Media not found for this conference', 404);
        }

        // Check if user can update this media
        if (!Gate::allows('update', $media)) {
            return $this->errorResponse('You are not authorized to update this media', 403);
        }

        $validator = Validator::make($request->all(), [
            'file_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        try {
            // Update file_name if provided
            if ($request->filled('file_name')) {
                $media->file_name = $request->file_name;
            }

            $media->save();

            $transformedMedia = [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'collection_name' => $media->collection_name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'size_human' => $media->humanReadableSize,
                'url' => route('api.media.serve', ['conference' => $conference->id, 'mediaId' => $media->id]),
                'download_url' => route('api.media.download', ['conference' => $conference->id, 'mediaId' => $media->id]),
                'conversions' => $this->getConversions($media),
                'uploaded_by' => $media->getCustomProperty('uploaded_by'),
                'created_at' => $media->created_at,
                'updated_at' => $media->updated_at,
                'can_update' => auth()->user()->can('update', $media),
                'can_delete' => auth()->user()->can('delete', $media),
            ];

            return $this->successResponse($transformedMedia, 'Media updated successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update media: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified media resource.
     */
    public function destroy(Conference $conference, Media $media): JsonResponse
    {
        // Ensure media belongs to the conference
        if ($media->model_id !== $conference->id || $media->model_type !== Conference::class) {
            return $this->errorResponse('Media not found for this conference', 404);
        }

        // Check if user can delete this media
        if (!Gate::allows('delete', $media)) {
            return $this->errorResponse('You are not authorized to delete this media', 403);
        }

        try {
            $media->delete();
            return $this->successResponse(null, 'Media deleted successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete media: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Download the specified media file.
     */
    public function download(Conference $conference, $mediaId): mixed
    {
        // Find media by ID manually instead of relying on route model binding
        $media = $conference->media()->where('id', $mediaId)->first();
        
        if (!$media) {
            return $this->errorResponse('Media not found for this conference', 404);
        }

        try {
            $path = $media->getPath();
            
            // Check if file exists
            if (!file_exists($path)) {
                return $this->errorResponse('File not found on disk', 404);
            }
            
            // Check if file is readable
            if (!is_readable($path)) {
                return $this->errorResponse('File is not readable', 500);
            }
            
            return response()->download($path, $media->file_name, [
                'Content-Type' => $media->mime_type,
                'Content-Length' => $media->size,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Media download failed', [
                'media_id' => $mediaId,
                'conference_id' => $conference->id,
                'path' => $media->getPath(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse('Failed to download media: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Serve/display the specified media file inline.
     */
    public function serve(Conference $conference, $mediaId): mixed
    {
        // Find media by ID manually instead of relying on route model binding
        $media = $conference->media()->where('id', $mediaId)->first();
        
        if (!$media) {
            return $this->errorResponse('Media not found for this conference', 404);
        }

        try {
            $path = $media->getPath();
            
            // Check if file exists
            if (!file_exists($path)) {
                return $this->errorResponse('File not found on disk', 404);
            }
            
            // Check if file is readable
            if (!is_readable($path)) {
                return $this->errorResponse('File is not readable', 500);
            }
            
            // Get file contents
            $fileContent = file_get_contents($path);
            
            if ($fileContent === false) {
                return $this->errorResponse('Unable to read file', 500);
            }
            
            // Set appropriate headers for inline display
            $headers = [
                'Content-Type' => $media->mime_type,
                'Content-Length' => $media->size,
                'Content-Disposition' => 'inline; filename="' . $media->file_name . '"',
                'Cache-Control' => 'public, max-age=31536000', // Cache for 1 year
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000),
                'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', filemtime($path)),
                'ETag' => '"' . md5_file($path) . '"',
            ];
                        
            return response($fileContent, 200, $headers);
            
        } catch (\Exception $e) {
            \Log::error('Media serve failed', [
                'media_id' => $mediaId,
                'conference_id' => $conference->id,
                'path' => $media->getPath(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse('Failed to serve media: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get media conversions URLs.
     */
    private function getConversions(Media $media): array
    {
        $conversions = [];
        
        if ($media->hasGeneratedConversion('thumb')) {
            $conversions['thumb'] = $media->getUrl('thumb');
        }
        
        if ($media->hasGeneratedConversion('preview')) {
            $conversions['preview'] = $media->getUrl('preview');
        }

        return $conversions;
    }

    /**
     * Validate MIME type based on collection.
     */
    private function validateMimeType($file, string $collection): array
    {
        $allowedMimeTypes = [
            'images' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'documents' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'general' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'video/mp4', 'video/avi', 'video/mov'
            ]
        ];

        $fileMimeType = $file->getMimeType();
        $allowed = $allowedMimeTypes[$collection] ?? [];

        if (!in_array($fileMimeType, $allowed)) {
            return [
                'valid' => false,
                'message' => "File type {$fileMimeType} is not allowed for collection {$collection}"
            ];
        }

        return ['valid' => true];
    }
}