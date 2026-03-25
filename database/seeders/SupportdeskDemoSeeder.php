<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Entity;
use App\Models\Inbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\TicketActivityLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupportdeskDemoSeeder extends Seeder
{
    /**
     * Seed the supportdesk demo data.
     */
    public function run(): void
    {
        $inboxes = collect([
            ['name' => 'Comercial', 'slug' => 'comercial'],
            ['name' => 'Apoio Tecnico', 'slug' => 'apoio-tecnico'],
            ['name' => 'Recursos Humanos', 'slug' => 'recursos-humanos'],
        ])->map(function (array $item): Inbox {
            return Inbox::query()->firstOrCreate(
                ['slug' => $item['slug']],
                ['name' => $item['name'], 'is_active' => true]
            );
        });

        $operator = User::query()->firstOrCreate(
            ['email' => 'operador@supportdesk.local'],
            [
                'name' => 'Operador Demo',
                'password' => Hash::make('Supportdesk123!'),
                'role' => 'operator',
                'is_active' => true,
                'is_admin' => true,
            ]
        );

        if (! $operator->isOperator() || ! $operator->isAdmin()) {
            $operator->update(['role' => 'operator', 'is_active' => true, 'is_admin' => true]);
        }

        $client = User::query()->firstOrCreate(
            ['email' => 'cliente@supportdesk.local'],
            [
                'name' => 'Cliente Demo',
                'password' => Hash::make('Supportdesk123!'),
                'role' => 'client',
                'is_active' => true,
            ]
        );

        if (! $client->isClient() || (bool) $client->is_admin) {
            $client->update(['role' => 'client', 'is_active' => true, 'is_admin' => false]);
        }

        $operator->accessibleInboxes()->syncWithoutDetaching(
            $inboxes->mapWithKeys(fn (Inbox $inbox) => [
                $inbox->id => ['can_manage_users' => true],
            ])->all()
        );

        $entity = Entity::query()->firstOrCreate(
            ['slug' => 'cliente-demo'],
            [
                'type' => 'external',
                'name' => 'Cliente Demo Lda',
                'tax_number' => '999999990',
                'email' => 'geral@clientedemo.pt',
                'country' => 'PT',
                'is_active' => true,
            ]
        );

        $contact = Contact::query()->firstOrCreate(
            ['email' => 'cliente@supportdesk.local'],
            [
                'user_id' => $client->id,
                'name' => 'Cliente Demo',
                'is_active' => true,
            ]
        );

        $contact->fill([
            'user_id' => $client->id,
            'name' => 'Cliente Demo',
            'is_active' => true,
        ]);
        $contact->save();
        $contact->entities()->syncWithoutDetaching([$entity->id]);

        if (! Ticket::query()->exists()) {
            $ticket = Ticket::query()->create([
                'ticket_number' => 'TMP-'.Str::uuid(),
                'inbox_id' => $inboxes->first()->id,
                'entity_id' => $entity->id,
                'contact_id' => $contact->id,
                'created_by_user_id' => $client->id,
                'assigned_operator_id' => $operator->id,
                'subject' => 'Pedido inicial de demonstracao',
                'description' => 'Ticket criado automaticamente para validar o fluxo.',
                'status' => 'open',
                'priority' => 'medium',
                'type' => 'request',
                'last_activity_at' => now(),
            ]);

            $ticket->update([
                'ticket_number' => sprintf('TC-%06d', $ticket->id),
            ]);

            TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => 'contact',
                'author_contact_id' => $contact->id,
                'body' => 'Ticket criado automaticamente para validar o fluxo.',
                'is_internal' => false,
            ]);

            TicketActivityLogger::log(
                $ticket,
                'ticket_created',
                null,
                null,
                $ticket->ticket_number,
                'contact',
                null,
                $contact->id
            );
        }
    }
}
