<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $email = urlencode($user->getEmailForPasswordReset());

            return "{$baseUrl}/reset-password?token={$token}&email={$email}";
        });
    }
}
