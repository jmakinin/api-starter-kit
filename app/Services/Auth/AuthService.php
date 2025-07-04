<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Traits\Response;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Login;
use App\Constants\AuthConstants;

class AuthService
{
    use Response;

    public function register($request): JsonResponse
    {
        try {

            $user = User::create([
                'firstname' => $request->firstname,
                'lastname' => $request->lastname,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
            ]);

            // Assign default role
            $role = AuthConstants::ADMIN;
            $user->assignRole($role);

            $token = $user->createToken($request->firstname)->plainTextToken;

            activity('new user')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('new user created')
            ;

            event(new Registered($user));

            return $this->successResponse([
                'user' => $user,
                'token' => $token,
            ], AuthConstants::REGISTRATION_SUCCESS, 201);
        } catch (\Throwable $e) {
            Log::error('Registration Error: ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e,
            ]);

            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function login($request): JsonResponse
    {
        try {
            $credentials = $request->only('email', 'password');
            $rememberMe = $request->boolean('rememberMe', false);

            if (!Auth::attempt($credentials, $rememberMe)) {
                return $this->errorResponse(AuthConstants::INVALID_CREDENTIALS, 401);
            }

            $user = Auth::user();

            // Set token expiration based on remember_me
            $tokenExpiration = $rememberMe
                ? now()->addWeeks(2)
                : now()->addHours(2);

            $token = $user->createToken('auth_token', ['*'], $tokenExpiration)->plainTextToken;

            $user->getPermissionsViaRoles();

            $userWithMedia = $this->loadUserMediaAssets($user);

            activity('login')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->log('User login')
            ;

            event(new Login('api', $user, $rememberMe));

            return $this->successResponse([
                'status' => true,
                'user' => $userWithMedia,
                'token' => $token,
                'token_expires_at' => $tokenExpiration->toDateTimeString()
            ], message: AuthConstants::LOGIN_SUCCESS, code: 200);
        } catch (\Throwable $e) {

            Log::error(AuthConstants::INVALID_CREDENTIALS . ': ' . $e->getMessage(), [
                'request' => $request->all(),
                'exception' => $e,
            ]);

            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            Auth::user()->currentAccessToken()->delete();
            return $this->successResponse(null, AuthConstants::LOGOUT_SUCCESS, 200);
        } catch (\Throwable $e) {
            Log::error('Logout error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    private function loadUserMediaAssets($user): array
    {
        // Load user with media assets grouped by type
        $user->load('mediaAssets');

        $userData = $user->toArray();

        // Group media assets by file type
        $mediaAssets = $user->mediaAssets->groupBy('file_type');
        $latestProfile = $mediaAssets->get('profile', collect())->sortByDesc('created_at')->first();

        // Structure the media assets data
        $userData['media_assets'] = [
            'profile' => $latestProfile ? [
                'id' => $latestProfile->id,
                'file_name' => $latestProfile->file_name,
                'file_url' => $latestProfile->file_url,
                'mime_type' => $latestProfile->mime_type,
                'size' => $latestProfile->size,
                'formatted_size' => $latestProfile->formatted_size,
                'is_image' => $latestProfile->is_image,
                'uploaded_at' => $latestProfile->created_at->toDateTimeString(),
            ] : null,

            'documents' => $mediaAssets->get('document', collect())->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'file_name' => $asset->file_name,
                    'file_url' => $asset->file_url,
                    'mime_type' => $asset->mime_type,
                    'size' => $asset->size,
                    'formatted_size' => $asset->formatted_size,
                    'is_document' => $asset->is_document,
                    'uploaded_at' => $asset->created_at->toDateTimeString(),
                ];
            })->values()->toArray(),

            'media' => $mediaAssets->get('media', collect())->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'file_name' => $asset->file_name,
                    'file_url' => $asset->file_url,
                    'mime_type' => $asset->mime_type,
                    'size' => $asset->size,
                    'formatted_size' => $asset->formatted_size,
                    'is_image' => $asset->is_image,
                    'uploaded_at' => $asset->created_at->toDateTimeString(),
                ];
            })->values()->toArray(),

            'general' => $mediaAssets->get('general', collect())->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'file_name' => $asset->file_name,
                    'file_url' => $asset->file_url,
                    'mime_type' => $asset->mime_type,
                    'size' => $asset->size,
                    'formatted_size' => $asset->formatted_size,
                    'uploaded_at' => $asset->created_at->toDateTimeString(),
                ];
            })->values()->toArray(),
        ];

        // Add summary counts
        $userData['media_summary'] = [
            'total_files' => $user->mediaAssets->count(),
            'profile_images' => $mediaAssets->get('profile', collect())->count(),
            'documents' => $mediaAssets->get('document', collect())->count(),
            'media_files' => $mediaAssets->get('media', collect())->count(),
            'general_files' => $mediaAssets->get('general', collect())->count(),
            'total_size' => $user->mediaAssets->sum('size'),
            'total_size_formatted' => $this->formatBytes($user->mediaAssets->sum('size')),
        ];

        // Remove the original mediaAssets relationship to avoid duplication
        unset($userData['media_assets_relation']);

        return $userData;
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
