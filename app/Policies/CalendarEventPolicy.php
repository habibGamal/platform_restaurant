<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;

class CalendarEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('analytics.view') || $user->can('users.manage');
    }

    public function view(User $user, CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent->organization_id === null
            || $calendarEvent->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, CalendarEvent $calendarEvent): bool
    {
        return $user->can('users.manage')
            && $calendarEvent->organization_id === $user->organization_id;
    }

    public function delete(User $user, CalendarEvent $calendarEvent): bool
    {
        return $user->can('users.manage')
            && $calendarEvent->organization_id === $user->organization_id;
    }
}
