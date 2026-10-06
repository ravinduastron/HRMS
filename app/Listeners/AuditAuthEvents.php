<?php

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;

// Auto-discovered by Laravel (methods starting with "handle", type-hinted with the event).
class AuditAuthEvents
{
    public function handleLogin(Login $event): void
    {
        Audit::log('auth.login', $event->user instanceof Model ? $event->user : null, 'Signed in', actor: $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof Model) {
            Audit::log('auth.logout', $event->user, 'Signed out', actor: $event->user);
        }
    }
}
