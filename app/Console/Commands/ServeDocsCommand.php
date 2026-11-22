<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ServeDocsCommand extends Command
{
    protected $signature = 'docs:serve {--port=3333 : The port to serve the documentation on} {--no-open : Do not automatically launch the browser}';

    protected $description = 'Serve the interactive documentation site locally and open it in your web browser';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $url = "http://localhost:{$port}";
        $router = base_path('docs_server.php');

        if (! file_exists(base_path('docs/index.html'))) {
            $this->error('Documentation viewer not found at docs/index.html');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('╔═════════════════════════════════════════════════════════════════════╗');
        $this->info('║            Platform Restaurants - Developer Documentation           ║');
        $this->info('╚═════════════════════════════════════════════════════════════════════╝');
        $this->line('');
        $this->info("  📖 Documentation site running at: <comment>{$url}</comment>");
        $this->line('  💡 Press <comment>Ctrl+C</comment> in this terminal to stop the server.');
        $this->line('');

        if (! $this->option('no-open')) {
            $this->openBrowser($url);
        }

        // Run PHP built-in web server with router script
        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router];
        $process = new Process($command, base_path(), null, null, null);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return self::SUCCESS;
    }

    protected function openBrowser(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("start \"\" \"{$url}\"");
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec("open \"{$url}\"");
        } else {
            exec("xdg-open \"{$url}\" > /dev/null 2>&1 &");
        }
    }
}
