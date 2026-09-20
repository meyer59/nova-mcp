<?php

namespace NovaMcp\Tests\Fixtures;

use Laravel\Sanctum\HasApiTokens;

class ApiUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}
