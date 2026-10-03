<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 */
class Services extends BaseService
{
    /**
     * Cheerio Direct API WhatsApp client.
     *
     * @return \App\Libraries\WhatsAppCloudAPI
     */
    public static function whatsApp(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('whatsApp');
        }

        return new \App\Libraries\WhatsAppCloudAPI();
    }

    /**
     * Meta Page Messaging (Instagram + Messenger) client.
     *
     * @return \App\Libraries\MetaPageMessagingAPI
     */
    public static function pageMessaging(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('pageMessaging');
        }

        return new \App\Libraries\MetaPageMessagingAPI();
    }

    /**
     * Outbound message queue service.
     *
     * @return \App\Libraries\QueueService
     */
    public static function queueService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('queueService');
        }

        return new \App\Libraries\QueueService();
    }

    /**
     * JWT authentication service.
     *
     * @return \App\Libraries\JwtService
     */
    public static function jwtService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('jwtService');
        }

        return new \App\Libraries\JwtService();
    }

    /**
     * Application settings service.
     *
     * @return \App\Libraries\SettingsService
     */
    public static function settingsService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('settingsService');
        }

        return new \App\Libraries\SettingsService();
    }

    /**
     * Automation rule engine.
     *
     * @return \App\Libraries\AutomationEngine
     */
    public static function automationEngine(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('automationEngine');
        }

        return new \App\Libraries\AutomationEngine();
    }

    /**
     * Keyword / menu bot.
     *
     * @return \App\Libraries\KeywordBot
     */
    public static function keywordBot(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('keywordBot');
        }

        return new \App\Libraries\KeywordBot();
    }

    /**
     * Encryption helper for Cheerio API keys / secrets.
     *
     * @return \App\Libraries\EncryptionService
     */
    public static function encryptionService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('encryptionService');
        }

        return new \App\Libraries\EncryptionService();
    }

    /**
     * Campaign lifecycle service.
     *
     * @return \App\Libraries\CampaignService
     */
    public static function campaignService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('campaignService');
        }

        return new \App\Libraries\CampaignService();
    }

    /**
     * WhatsApp policy guard: opt-in/out, suppression, frequency cap, account health.
     */
    public static function contactAttributes(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('contactAttributes');
        }

        return new \App\Libraries\ContactAttributeService();
    }

    public static function quickReplies(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('quickReplies');
        }

        return new \App\Libraries\QuickReplyService();
    }

    public static function whatsAppConsent(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('whatsAppConsent');
        }

        return new \App\Libraries\WhatsAppConsentService();
    }

    /**
     * Multi-provider outbound email facade (SMTP, SendGrid, Cheerio).
     *
     * @return \App\Libraries\EmailProvider
     */
    public static function emailProvider(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('emailProvider');
        }

        return new \App\Libraries\EmailProvider();
    }

    /**
     * AI Assistant & Gemini Bot service.
     *
     * @return \App\Libraries\AiService
     */
    public static function aiService(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('aiService');
        }

        return new \App\Libraries\AiService();
    }
}
