<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantHome;
use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('assistant/index', [
            ...AssistantHome::forUser($user),
            'panelUrl' => Filament::getPanel('app')->getUrl(),
        ]);
    }
}
