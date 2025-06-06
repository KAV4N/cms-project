<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\ConferenceLockService;
use App\Models\Conference;

class UserController extends Controller
{
    use ApiResponse;

    protected $lockService;

    public function __construct(ConferenceLockService $lockService)
    {
        $this->lockService = $lockService;
    }

    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermissionTo('access.admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        $query = User::query()->with(['roles', 'university']);

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->has('roles') && !empty($request->roles)) {
            $roles = explode(',', $request->roles);
            $query->whereHas('roles', function ($q) use ($roles) {
                $q->whereIn('name', $roles);
            });
        }

        // University filter
        if ($request->has('university_id') && !empty($request->university_id)) {
            $query->where('university_id', $request->university_id);
        }

        // Sort options
        $sortField = in_array($request->sort_field, ['name', 'email', 'created_at', 'updated_at'])
                ? $request->sort_field
                : 'created_at';
        
        $sortOrder = in_array(strtolower($request->sort_order), ['asc', 'desc'])
                ? strtolower($request->sort_order)
                : 'desc';
        
        $query->orderBy($sortField, $sortOrder);

        // Pagination
        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(max(intval($request->per_page ?? 10), 1), 100);
            $users = $query->paginate($perPage)->withQueryString();
            return $this->paginatedResponse($users, UserResource::collection($users));
        } else {
            $users = $query->get();
            return $this->successResponse(
                UserResource::collection($users),
                'Users retrieved successfully'
            );
        }
    

        if ($request->has('roles') && !empty($request->roles)) {
            $roles = explode(',', $request->roles);
            $query->whereHas('roles', function ($q) use ($roles) {
                $q->whereIn('name', $roles);
            });
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortField = in_array($request->sort_field, ['name', 'email', 'created_at', 'updated_at'])
                ? $request->sort_field
                : 'created_at';
        
        $sortOrder = in_array(strtolower($request->sort_order), ['asc', 'desc'])
                ? strtolower($request->sort_order)
                : 'desc';
        
        $query->orderBy($sortField, $sortOrder);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(max(intval($request->per_page ?? 15), 1), 100);
            $users = $query->paginate($perPage)->withQueryString();
            return $this->paginatedResponse($users, UserResource::collection($users));
        } else {
            $users = $query->get();
            return $this->successResponse(
                UserResource::collection($users),
                'Users retrieved successfully'
            );
        }
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'university']);

        return $this->successResponse(
            new UserResource($user),
            'User retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermissionTo('access.admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'nullable|string|min:8',
            'role' => 'required|string|in:admin,editor',
            'university_id' => 'required|exists:universities,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation error', 422, $validator->errors());
        }

        if ($request->role === 'admin' && !$request->user()->hasPermissionTo('manage.admin')) {
            return $this->errorResponse('You do not have permission to create admin users', 403);
        }
        
        if ($request->role === 'editor' && !$request->user()->hasPermissionTo('manage.editor')) {
            return $this->errorResponse('You do not have permission to create editor users', 403);
        }

        $password = $request->password ?? $this->generateRandomPassword();

        $newUser = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($password),
            'university_id' => $request->university_id,
            'must_change_password' => true,
        ]);

        $newUser->assignRole($request->role);

        return $this->successResponse(
            [
                'user' => new UserResource($newUser->load(['roles', 'university'])),
                'generated_password' => $request->password ? null : $password
            ],
            'User created successfully',
            201
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (!$request->user()->hasPermissionTo('access.admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        // Check if current user is trying to edit a super_admin or admin
        $currentUser = $request->user();
        if (!$currentUser->hasRole('super_admin')) {
            if ($user->hasRole('super_admin')) {
                return $this->errorResponse('You cannot edit super admin users', 403);
            }
            
            if ($user->hasRole('admin') && $currentUser->hasRole('admin')) {
                return $this->errorResponse('You cannot edit other admin users', 403);
            }
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|nullable|string|min:8',
            'role' => 'sometimes|required|string|in:admin,editor',
            'university_id' => 'required|exists:universities,id',
            'generate_password' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation error', 422, $validator->errors());
        }

        if ($request->has('role') && $request->role !== $user->roles->first()->name) {
            if ($request->role === 'admin' && !$request->user()->hasPermissionTo('manage.admin')) {
                return $this->errorResponse('You do not have permission to assign admin role', 403);
            }
            
            if ($request->role === 'editor' && !$request->user()->hasPermissionTo('manage.editor')) {
                return $this->errorResponse('You do not have permission to assign editor role', 403);
            }
        }

        $generatedPassword = null;
        
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        
        if ($request->has('generate_password') && $request->generate_password) {
            $generatedPassword = $this->generateRandomPassword();
            $user->password = Hash::make($generatedPassword);
            $user->must_change_password = true;
        } else if ($request->has('password') && $request->password) {
            $user->password = Hash::make($request->password);
            $user->must_change_password = true;
        }
        
        if ($request->has('university_id')) {
            $user->university_id = $request->university_id;
        }
        
        $user->save();

        if ($request->has('role') && !empty($user->roles) && $request->role !== $user->roles->first()->name) {
            $user->syncRoles([$request->role]);
        }

        $response = [
            'user' => new UserResource($user->load(['roles', 'university']))
        ];
        
        if ($generatedPassword) {
            $response['generated_password'] = $generatedPassword;
        }

        return $this->successResponse(
            $response,
            'User updated successfully'
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (!$request->user()->hasPermissionTo('access.admin')) {
            return $this->errorResponse('Unauthorized', 403);
        }
        
        // Check if current user is trying to delete a super_admin or admin
        $currentUser = $request->user();
        if (!$currentUser->hasRole('super_admin')) {
            if ($user->hasRole('super_admin')) {
                return $this->errorResponse('You cannot delete super admin users', 403);
            }
            
            if ($user->hasRole('admin') && $currentUser->hasRole('admin')) {
                return $this->errorResponse('You cannot delete other admin users', 403);
            }
        }
        
        if ($user->hasRole('admin') && !$request->user()->hasPermissionTo('manage.admin')) {
            return $this->errorResponse('You do not have permission to delete admin users', 403);
        }
        
        if ($user->hasRole('editor') && !$request->user()->hasPermissionTo('manage.editor')) {
            return $this->errorResponse('You do not have permission to delete editor users', 403);
        }
        
        if ($user->hasRole('super_admin')) {
            return $this->errorResponse('Super admin users cannot be deleted', 403);
        }
        
        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot delete your own account', 403);
        }

        $conferences = Conference::where('created_by', $user->id)
            ->orWhereHas('editors', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            })->get();
    
        foreach ($conferences as $conference) {
            $this->lockService->releaseLock($conference->id, $user->id);
        }

        $user->delete();

        return $this->successResponse(null, 'User deleted successfully');
    }
    
    protected function generateRandomPassword($length = 12): string
    {
        $uppercase = chr(rand(65, 90));
        $lowercase = chr(rand(97, 122));
        $number = (string)rand(0, 9);
        $special = ['!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '_', '+', '=', '[', ']', '{', '}', ';', ':', ',', '.', '?'];
        $specialChar = $special[array_rand($special)];
        
        $remaining = $length - 4;
        $rest = Str::random($remaining);
        
        $password = str_shuffle($uppercase . $lowercase . $number . $specialChar . $rest);
        
        return $password;
    }
}