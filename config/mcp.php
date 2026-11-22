<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    // Only known AI clients may register: Claude (web/desktop/mobile), ChatGPT, the Gemini app (Google's
    // redirect service: it registers /r/ and /a/ URIs on oauth-redirect, -sandbox and -test hosts together,
    // and one disallowed URI rejects the whole registration), Gemini Enterprise, and localhost
    // callbacks used by CLI and desktop tools (Claude Code, Gemini CLI, MCP Inspector). Entries are URL
    // prefixes. Never "*": any site could then register a look-alike client and phish an owner's consent.
    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com,'
        .'https://oauth-redirect.googleusercontent.com,https://oauth-redirect-sandbox.googleusercontent.com,'
        .'https://oauth-redirect-test.googleusercontent.com,https://vertexaisearch.cloud.google.com,'
        .'http://localhost,http://127.0.0.1',
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        'cursor',
        'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | Here you may configure named MCP clients that connect to external MCP
    | servers. Every client may define a URL for the remote server, or a
    | command and arguments for local servers plus connection options.
    |
    */

    'clients' => [
        'github' => [
            'url' => env('MCP_GITHUB_URL', 'https://api.githubcopilot.com/mcp/'),
            'token' => env('MCP_GITHUB_TOKEN'),
            'headers' => [
                'X-MCP-Readonly' => 'true',
            ],
        ],

        'linear' => [
            'url' => env('MCP_LINEAR_URL', 'https://mcp.linear.app/mcp'),
            'token' => env('MCP_LINEAR_TOKEN'),
        ],

        'playwright' => [
            'command' => 'npx',
            'args' => ['-y', '@playwright/mcp@latest', '--headless'],
        ],
    ],

];
