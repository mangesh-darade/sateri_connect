<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Transport defaults for multi-provider email (SMTP, SendGrid, Cheerio Direct API).
 */
class EmailProviders extends BaseConfig
{
    /** Cheerio Direct API base (same host as WhatsApp Direct APIs). */
    public string $cheerioBaseUrl = 'https://newprod.api.cheerio.in/direct-apis';

    /** SendGrid Mail Send API v3. */
    public string $sendGridApiUrl = 'https://api.sendgrid.com/v3/mail/send';

    /** SendGrid Marketing Single Sends API root. */
    public string $sendGridSingleSendsApiUrl = 'https://api.sendgrid.com/v3/marketing/singlesends';

    public int $timeout = 30;

    public int $maxRetries = 2;

    public int $retryDelaySeconds = 1;

    /** Default campaign label for Cheerio single-email API calls. */
    public string $defaultCampaignName = 'app-direct';
}
