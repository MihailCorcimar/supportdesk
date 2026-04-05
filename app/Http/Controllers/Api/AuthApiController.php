<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthApiController extends Controller
{
    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->serializeUser($user),
        ]);
    }

    /**
     * Update the authenticated user profile.
     */
    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => ['nullable', 'string', 'required_with:password'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['nullable', 'string', 'min:8', 'required_with:password'],
        ]);

        $updates = [];

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            if ($name !== '' && $name !== $user->name) {
                $updates['name'] = $name;
            }
        }

        if (array_key_exists('email', $validated)) {
            $email = mb_strtolower(trim((string) $validated['email']));
            if ($email !== '' && $email !== $user->email) {
                $updates['email'] = $email;
            }
        }

        $newPassword = (string) ($validated['password'] ?? '');
        if ($newPassword !== '') {
            if (! Hash::check((string) ($validated['current_password'] ?? ''), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Current password is invalid.',
                ]);
            }

            $updates['password'] = Hash::make($newPassword);
        }

        if ($updates === []) {
            return response()->json([
                'message' => 'No profile changes to apply.',
                'data' => $this->serializeUser($user),
            ]);
        }

        DB::transaction(function () use ($user, $updates): void {
            $user->forceFill($updates)->save();

            $contactUpdates = [];
            if (array_key_exists('name', $updates)) {
                $contactUpdates['name'] = $updates['name'];
            }
            if (array_key_exists('email', $updates)) {
                $contactUpdates['email'] = $updates['email'];
            }

            if ($contactUpdates !== []) {
                Contact::query()
                    ->where('user_id', $user->id)
                    ->update($contactUpdates);
            }
        });

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->serializeUser($user),
        ]);
    }

    /**
     * Update the authenticated user avatar.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Custom avatars are disabled. Generic avatar is used for all users.',
        ], 422);
    }

    /**
     * Remove the authenticated user avatar.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Custom avatars are disabled. Generic avatar is used for all users.',
        ], 422);
    }

    /**
     * Register a new user account as client.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $normalizedEmail = mb_strtolower(trim((string) $validated['email']));

            $user = User::query()->create([
                'name' => trim((string) $validated['name']),
                'email' => $normalizedEmail,
                'password' => Hash::make((string) $validated['password']),
                'role' => 'client',
                'is_active' => true,
                'is_admin' => false,
            ]);

            $entity = Entity::query()->create([
                'type' => 'external',
                'name' => $user->name,
                'slug' => $this->generateUniqueEntitySlug($user->name),
                'email' => $normalizedEmail,
                'country' => 'PT',
                'is_active' => true,
            ]);

            $contact = Contact::query()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $normalizedEmail,
                'is_active' => true,
            ]);

            $contact->entities()->syncWithoutDetaching([$entity->id]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->serializeUser($user),
        ], 201);
    }

    /**
     * Authenticate user and start session.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                'email' => 'Invalid credentials.',
            ]);
        }

        $request->session()->regenerate();

        if (! $request->user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Inactive account. Contact an operator.',
            ]);
        }

        return response()->json([
            'data' => $this->serializeUser($request->user()),
        ]);
    }

    /**
     * Send a password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink([
            'email' => mb_strtolower(trim((string) $validated['email'])),
        ]);

        return response()->json([
            'message' => 'Se a conta existir, o link de recuperação foi enviado.',
        ]);
    }

    /**
     * Reset password using token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ]);

        $status = Password::reset(
            [
                'email' => mb_strtolower(trim((string) $validated['email'])),
                'password' => (string) $validated['password'],
                'password_confirmation' => (string) $validated['password_confirmation'],
                'token' => (string) $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'O link de recuperação é inválido ou expirou.',
            ]);
        }

        return response()->json([
            'message' => 'Palavra-passe atualizada com sucesso.',
        ]);
    }

    /**
     * Logout user and invalidate session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Session ended.',
        ]);
    }

    /**
     * Serialize user for auth payload.
     *
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'is_admin' => (bool) $user->is_admin,
            'can_manage_users' => $user->canManageUsers(),
            'avatar_url' => url('/images/avatar-placeholder.svg'),
        ];
    }

    /**
     * Generate unique slug for auto-created client entity.
     */
    private function generateUniqueEntitySlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'cliente';
        $slug = $base;
        $suffix = 2;

        while (Entity::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
