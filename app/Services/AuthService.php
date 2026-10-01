<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Passenger;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Account business rules: registration, login checks, and issuing/revoking tokens.
 * Controllers stay thin; every rule about accounts lives here.
 */
class AuthService
{
    public const TOKEN_LIFETIME_DAYS = 30;

    /**
     * A fixed bcrypt hash (cost 12, same as real passwords) of a throwaway string.
     * Checked when no user matches, so a failed login ALWAYS costs exactly one bcrypt check,
     * whether or not the account exists (no timing difference to exploit).
     */
    private const TIMING_DUMMY_HASH = '$2y$12$bA1iD27i.x7ZQ4DVyFDOFevlcvUyJWX2umhewjJS7/DhW7uC93IEq';

    /**
     * Creates the user AND its passenger/driver row in ONE transaction:
     * either both exist, or neither does (no half-registered accounts).
     *
     * @param  array{first_name:string,last_name:string,email:string,phone:string,password:string,role:string,device_name?:?string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = new User([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'], // hashed automatically by the model's 'hashed' cast
            ]);
            // Not mass-assignable on purpose: set explicitly, from the already-validated value.
            $user->role = UserRole::from($data['role']);
            $user->save();

            match ($user->role) {
                UserRole::Passenger => Passenger::forceCreate(['user_id' => $user->id]),
                UserRole::Driver => Driver::forceCreate(['user_id' => $user->id]), // starts pending_verification
                default => throw new \LogicException('Only passengers and drivers can register.'),
            };

            // Reload from the database: values MySQL filled in by itself (account_status default
            // 'active', timestamps) are not on the in-memory object after save(). Without this,
            // $user->account_status is null and the response crashes (caught by RegisterTest).
            return $user->refresh();
        });

        return ['user' => $user, 'token' => $this->issueToken($user, $data['device_name'] ?? null)];
    }

    /**
     * @return array{user: User, token: string}
     *
     * @throws ApiException INVALID_CREDENTIALS (422) or ACCOUNT_SUSPENDED (403)
     */
    public function login(string $login, string $password, ?string $deviceName): array
    {
        $user = $this->findByLogin($login);

        // Check the password even when no user was found (against the dummy hash), so a wrong
        // email and a wrong password take the same time and give the same answer.
        // Otherwise an attacker could learn which emails/phones have accounts.
        $passwordOk = Hash::check($password, $user?->password ?? self::TIMING_DUMMY_HASH);

        if (! $user || ! $passwordOk) {
            throw new ApiException(__('api.invalid_credentials'), 'INVALID_CREDENTIALS', 422);
        }

        if ($user->account_status === AccountStatus::Suspended) {
            throw new ApiException(
                __('api.account_suspended'),
                'ACCOUNT_SUSPENDED',
                403,
            );
        }

        return ['user' => $user, 'token' => $this->issueToken($user, $deviceName)];
    }

    /** Revokes only the token used for this request (other devices stay logged in). */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /** One token per login/device, expiring after 30 days (also enforced by config/sanctum.php). */
    private function issueToken(User $user, ?string $deviceName): string
    {
        return $user->createToken(
            name: $deviceName ?: 'mobile',
            expiresAt: now()->addDays(self::TOKEN_LIFETIME_DAYS),
        )->plainTextToken;
    }

    /** "login" can be an email or a PH mobile number in any common format. */
    private function findByLogin(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::where('email', strtolower(trim($login)))->first();
        }

        $phone = PhoneNumber::normalize($login);

        return $phone ? User::where('phone', $phone)->first() : null;
    }
}
