<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Http\Responses\InertiaAwareRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class InertiaAwareRedirectTest extends TestCase
{
    public function test_an_inertia_login_headed_to_oauth_consent_becomes_a_full_page_visit(): void
    {
        // Inertia::location() reads the current request, which is the login request in a real run.
        $request = Request::create('/login', 'POST', server: ['HTTP_X_INERTIA' => 'true']);
        $this->app->instance('request', $request);

        $response = InertiaAwareRedirect::adapt($request, new RedirectResponse('http://localhost/oauth/authorize?client_id=abc'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('http://localhost/oauth/authorize?client_id=abc', $response->headers->get('X-Inertia-Location'));
    }

    public function test_other_redirects_are_left_alone(): void
    {
        $toDashboard = new RedirectResponse('http://localhost/dashboard');
        $plainLogin = new RedirectResponse('http://localhost/oauth/authorize');

        $this->assertSame($toDashboard, InertiaAwareRedirect::adapt(
            Request::create('/login', 'POST', server: ['HTTP_X_INERTIA' => 'true']),
            $toDashboard,
        ));
        $this->assertSame($plainLogin, InertiaAwareRedirect::adapt(Request::create('/login', 'POST'), $plainLogin));
    }
}
