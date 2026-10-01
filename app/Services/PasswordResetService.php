<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Forgot-password with a 6-digit code (phase-5-authentication.md § 3.5–3.6).
 * Uses Laravel's password_reset_tokens table: email (PK), token (a HASH of the code), created_at.
 */
class PasswordResetService
{
    public const CODE_LIFETIME_MINUTES = 15;

    /** Same purpose as in AuthService: equal work whether or not the account exists. */
    private const TIMING_DUMMY_HASH = '$2y$12$bA1iD27i.x7ZQ4DVyFDOFevlcvUyJWX2umhewjJS7/DhW7uC93IEq';

    /**
     * Emails a new code if the account exists. Callers always answer the same way,
     * so this endpoint can't be used to discover which emails are registered.
     */
    public function sendCode(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            Hash::check('equal-work', self::TIMING_DUMMY_HASH); // similar cost to the real path

            return;
        }

        // random_int is cryptographically secure (rand/mt_rand are predictable).
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        // One active code per email: a new request REPLACES the old one. Only the hash is stored,
        // so even someone reading the database can't use the code.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        Mail::to($user)->send(new PasswordResetCodeMail($user, $code));
    }

    /**
     * @throws ApiException INVALID_OR_EXPIRED_CODE (422): one message for wrong, expired or missing codes
     */
    public function reset(string $email, string $code, string $newPassword): void
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $row
            && Carbon::parse($row->created_at)->gt(now()->subMinutes(self::CODE_LIFETIME_MINUTES))
            && Hash::check($code, $row->token);

        $user = $valid ? User::where('email', $email)->first() : null;

        if (! $user) {
            throw new ApiException(
                __('api.invalid_code'),
                'INVALID_OR_EXPIRED_CODE',
                422,
                ['code' => [__('api.invalid_code_field')]],
            );
        }

        DB::transaction(function () use ($user, $email, $newPassword) {
            $user->password = $newPassword; // hashed by the model's 'hashed' cast
            $user->save();

            // Anyone logged in with the OLD password (e.g. whoever stole it) is logged out everywhere.
            $user->tokens()->delete();

            // Single use: the code can't be replayed.
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });
    }
}
