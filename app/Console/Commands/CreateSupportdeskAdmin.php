<?php

namespace App\Console\Commands;

use App\Models\Inbox;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSupportdeskAdmin extends Command
{
    protected $signature = 'supportdesk:create-admin
        {--name= : Operator full name}
        {--email= : Operator email}
        {--password= : Operator password (optional)}';

    protected $description = 'Create the first Supportdesk operator with inbox management access';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Operator name', 'Supportdesk Operator');
        $email = $this->option('email') ?: $this->ask('Operator email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');
            return self::FAILURE;
        }

        $password = $this->option('password');

        if (! $password) {
            $password = $this->secret('Operator password (leave empty to auto-generate)');
        }

        $generated = false;
        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $inboxes = Inbox::query()->pluck('id');
        if ($inboxes->isNotEmpty()) {
            $user->accessibleInboxes()->sync(
                $inboxes->mapWithKeys(fn (int $inboxId) => [$inboxId => ['can_manage_users' => true]])->all()
            );
        }

        $this->info("Operator created: {$user->email}");

        if ($generated) {
            $this->warn('Generated temporary password (save now, shown only once):');
            $this->line($password);
        }

        return self::SUCCESS;
    }
}
