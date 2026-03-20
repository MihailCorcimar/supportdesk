<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserInvite;
use App\Support\UserActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InviteApiController extends Controller
{
    /**
     * Return invite metadata for the token.
     */
    public function show(string $token): JsonResponse
    {
        $invite = $this->resolveValidInvite($token);

        return response()->json([
            'data' => [
                'user_name' => $invite->user->name,
                'user_email' => $invite->user->email,
                'expires_at' => $invite->expires_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Accept an invite and define the user password.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:40'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invite = $this->resolveValidInvite((string) $validated['token']);

        DB::transaction(function () use ($invite, $validated): void {
            $invite->user->forceFill([
                'password' => Hash::make($validated['password']),
                'email_verified_at' => $invite->user->email_verified_at ?? now(),
            ])->save();

            $invite->forceFill([
                'used_at' => now(),
            ])->save();

            UserActivityLogger::log(
                null,
                $invite->user,
                'invite_accepted',
                ['invite_id' => $invite->id]
            );
        });

        return response()->json([
            'message' => 'Invitation accepted. Password updated.',
        ]);
    }

    /**
     * Resolve a valid invite for token.
     */
    private function resolveValidInvite(string $plainToken): UserInvite
    {
        $hash = hash('sha256', $plainToken);

        return UserInvite::query()
            ->with('user')
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}