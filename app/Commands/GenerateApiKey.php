<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ApiTokenModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class GenerateApiKey extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:api-key';
    protected $description = 'Generate an external API key for testing and integrations.';
    protected $usage       = 'app:api-key [name]';

    public function run(array $params)
    {
        $name = $params[0] ?? 'CLI Test Key';
        $user = model(UserModel::class)->first();

        if (! $user) {
            CLI::error('No active users found in database.');
            return;
        }

        $tokenModel = model(ApiTokenModel::class);
        $res = $tokenModel->createToken((int) $user['id'], $name, ['*']);

        CLI::write('API Key Generated Successfully!', 'green');
        CLI::write('Key: ' . $res['plain_text'], 'yellow');
        CLI::write('User: ' . ($user['email'] ?? $user['name']));
    }
}
