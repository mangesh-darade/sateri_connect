<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Copy public web assets to the project root for hosts whose DocumentRoot
 * is the project root (nginx/Plesk without public/ as docroot).
 *
 * Prefer DocumentRoot = public/ when possible. Use this when /assets 404s.
 */
class PublishWebroot extends BaseCommand
{
    protected $group       = 'WhatsApp';
    protected $name        = 'webroot:publish';
    protected $description = 'Publish public/assets (+ uploads, favicon) to project root for nginx docroot.';
    protected $usage       = 'webroot:publish [--force]';
    protected $options     = [
        '--force' => 'Overwrite existing root assets/uploads copies.',
    ];

    public function run(array $params)
    {
        $force = array_key_exists('force', $params)
            || CLI::getOption('force') !== null
            || in_array('--force', $_SERVER['argv'] ?? [], true);

        $root   = realpath(ROOTPATH) ?: rtrim(ROOTPATH, '\\/');
        $public = rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'public';

        if (! is_dir($public)) {
            CLI::error('public/ directory not found at ' . $public);

            return EXIT_ERROR;
        }

        CLI::write('Publishing web assets for project-root DocumentRoot…', 'yellow');
        CLI::write('Root:   ' . $root);
        CLI::write('Public: ' . $public);
        CLI::newLine();

        try {
            $this->publishTree(
                $public . DIRECTORY_SEPARATOR . 'assets',
                $root . DIRECTORY_SEPARATOR . 'assets',
                $force,
            );
            $this->publishTree(
                $public . DIRECTORY_SEPARATOR . 'uploads',
                $root . DIRECTORY_SEPARATOR . 'uploads',
                $force,
            );

            foreach (['favicon.ico', 'robots.txt'] as $file) {
                $src  = $public . DIRECTORY_SEPARATOR . $file;
                $dest = $root . DIRECTORY_SEPARATOR . $file;
                if (! is_file($src)) {
                    continue;
                }
                if (is_file($dest) && ! $force) {
                    CLI::write("skip {$file} (exists)", 'dark_gray');
                    continue;
                }
                if (! @copy($src, $dest)) {
                    CLI::error("Failed to copy {$file}");

                    return EXIT_ERROR;
                }
                CLI::write("copied {$file}", 'green');
            }
        } catch (Throwable $e) {
            CLI::error('webroot:publish failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::newLine();
        CLI::write('Done. Verify: https://YOUR-HOST/assets/css/app.css', 'green');
        CLI::write('Tip: Prefer DocumentRoot = public/ when Plesk allows it.', 'cyan');

        return EXIT_SUCCESS;
    }

    private function publishTree(string $src, string $dest, bool $force): void
    {
        if (! is_dir($src)) {
            CLI::write('skip missing ' . $src, 'dark_gray');

            return;
        }

        if (is_link($dest)) {
            CLI::write('skip ' . basename($dest) . ' (symlink already present)', 'dark_gray');

            return;
        }

        if (! is_dir($dest) && ! @mkdir($dest, 0755, true)) {
            throw new \RuntimeException('Cannot create ' . $dest);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $count = 0;
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($src) + 1);
            $target   = $dest . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0755, true)) {
                    throw new \RuntimeException('Cannot create ' . $target);
                }
                continue;
            }

            if (is_file($target) && ! $force) {
                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! @mkdir($parent, 0755, true)) {
                throw new \RuntimeException('Cannot create ' . $parent);
            }

            if (! @copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Cannot copy to ' . $target);
            }
            $count++;
        }

        CLI::write('published ' . basename($dest) . "/ ({$count} files)", 'green');
    }
}
