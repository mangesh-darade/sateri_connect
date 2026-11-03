<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\SocialOAuthService;
use App\Libraries\TenantConnection;
use App\Libraries\TenantContext;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Connect a YouTube channel to one client from the refresh token saved in .env (social.youtubeRefreshToken).
 * Same as Accounts → Connect YouTube with a blank token field.
 *
 *   php spark social:connect-youtube -tenant acme
 */
class SocialConnectYouTube extends BaseCommand
{
    protected $group       = 'Social';
    protected $name        = 'social:connect-youtube';
    protected $description = 'Connect the YouTube channel from the saved refresh token to one client.';
    protected $usage       = 'social:connect-youtube -tenant <key>';
    protected $options     = ['-tenant' => 'Client key (required)'];

    public function run(array $params)
    {
        $key = strtolower(trim((string) (CLI::getOption('tenant') ?? '')));
        if ($key === '') {
            CLI::error('Give the client with -tenant <key>.');

            return EXIT_ERROR;
        }

        try {
            if (! (new TenantConnection())->apply($key, 'cli')) {
                CLI::error('Could not connect to ' . $key);

                return EXIT_ERROR;
            }
            TenantContext::set($key, 'cli');
            // Built after the tenant switch so the models use this client's database.
            $r = (new SocialOAuthService())->connectYouTubeWithRefreshToken('', null);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write($r['message'], $r['ok'] ? 'green' : 'red');

        return $r['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
