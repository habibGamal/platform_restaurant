<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;

final class LoginResponse extends FortifyLoginResponse
{
    public function toResponse($request)
    {
        return InertiaAwareRedirect::adapt($request, parent::toResponse($request));
    }
}
