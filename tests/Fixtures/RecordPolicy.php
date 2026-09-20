<?php

namespace NovaMcp\Tests\Fixtures;

class RecordPolicy
{
    public function viewAny($user): bool
    {
        return $user->name !== 'blocked';
    }

    public function view($user, $record): bool
    {
        return $record->name !== 'INVISIBLE';
    }

    public function create($user): bool
    {
        return $user->name !== 'reader';
    }

    public function update($user, $record): bool
    {
        return $user->name !== 'reader';
    }

    public function delete($user, $record): bool
    {
        return $user->name !== 'reader';
    }

    public function restore($user, $record): bool
    {
        return $user->name !== 'reader';
    }
}
