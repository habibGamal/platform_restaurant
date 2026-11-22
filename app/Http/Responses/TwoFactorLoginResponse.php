<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;

final class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    public function toResponse($request)
    {
        return InertiaAwareRedirect::adapt($request, parent::toResponse($request));
    }
}
