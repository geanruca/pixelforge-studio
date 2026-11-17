<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    /**
     * The signed-in user of a route behind the `auth` middleware.
     */
    protected function authenticatedUser(Request $request): User
    {
        return $request->user() ?? abort(Response::HTTP_UNAUTHORIZED);
    }
}
