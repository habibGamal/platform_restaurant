<?php

use App\Http\Middleware\EnsureAssistantAccess;
use App\Mcp\Servers\RestaurantServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth discovery (/.well-known/...) and dynamic client registration (/oauth/register) for MCP clients.
// Tokens are issued by Passport (/oauth/authorize, /oauth/token); owners sign in with their normal account.
Mcp::oauthRoutes();

// Remote server for Claude, ChatGPT and other MCP clients: acts as the user who approved the connection.
Mcp::web('/mcp/restaurants', RestaurantServer::class)
    ->middleware(['auth:api', CheckToken::using(Registrar::OAUTH_SCOPE), EnsureAssistantAccess::class]);

// Local stdio server for MCP clients on this machine: php artisan mcp:start restaurants
// The tools act as the user in MCP_USER_ID (see config/ai.php "mcp").
Mcp::local('restaurants', RestaurantServer::class);
