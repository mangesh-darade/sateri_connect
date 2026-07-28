<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\WhatsApp as WhatsAppConfig;

/**
 * Provider-aware WhatsApp API facade.
 *
 * Callers keep using WhatsAppCloudAPI / service('whatsApp').
 * Active transport is chosen from settings `whatsapp_provider`:
 *   - cheerio → CheerioDirectAPI
 *   - meta    → MetaCloudAPI
 *
 * @method array sendText(string $to, string $text, bool $previewUrl = false)
 * @method array sendTemplate(string $to, string $templateName, string $language, array $components = [])
 * @method array ensureTemplateComponents(string $templateName, string $language, array $components)
 * @method array sendImage(string $to, string $linkOrId, ?string $caption = null, bool $byId = false)
 * @method array sendDocument(string $to, string $linkOrId, ?string $caption = null, ?string $filename = null, bool $byId = false)
 * @method array sendVideo(string $to, string $linkOrId, ?string $caption = null, bool $byId = false)
 * @method array sendAudio(string $to, string $linkOrId, bool $byId = false)
 * @method array sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null)
 * @method array sendInteractiveButtons(string $to, string $bodyText, array $buttons, ?string $header = null, ?string $footer = null)
 * @method array sendQuickReply(string $to, string $bodyText, array $buttons)
 * @method array sendInteractiveList(string $to, string $bodyText, string $buttonText, array $sections, ?string $header = null, ?string $footer = null)
 * @method array uploadMedia(string $filePath, string $mimeType)
 * @method array getMediaUrl(string $mediaId)
 * @method array downloadMedia(string $mediaId)
 * @method array getTemplates(?string $wabaId = null)
 * @method array createTemplate(array $payload)
 * @method array deleteTemplate(string $name, ?string $hsmId = null)
 * @method array getTemplateByNameOrId(string $nameOrId, string $by = 'name')
 * @method array getMessageStatus(string $wamid)
 * @method array getContacts(?string $search = null, int $maxPages = 50)
 * @method array getWorkflows()
 * @method array getPhoneNumberInfo()
 * @method array testConnection()
 * @method array markAsRead(string $messageId)
 * @method string normalizePhone(string $phone)
 * @method array request(string $method, string $endpoint, ?array $data = null, bool $isMultipart = false)
 */
class WhatsAppCloudAPI
{
    protected SettingsService $settings;
    protected WhatsAppConfig $config;
    protected CheerioDirectAPI|MetaCloudAPI $driver;

    /** Temporary override (e.g. reply on the number that received the webhook). */
    protected ?string $forcedProvider = null;

    public function __construct(?SettingsService $settings = null, ?WhatsAppConfig $config = null)
    {
        $this->settings = $settings ?? new SettingsService();
        $this->config   = $config ?? config(WhatsAppConfig::class);
        $this->driver   = $this->resolveDriver();
    }

    public function getProvider(): string
    {
        $this->ensureDriver();

        return $this->effectiveProvider();
    }

    /**
     * Force Cheerio or Meta for subsequent calls until clearForcedProvider().
     */
    public function forceProvider(?string $provider): self
    {
        if ($provider === null || $provider === '') {
            $this->forcedProvider = null;
        } else {
            $provider = strtolower(trim($provider));
            if (! in_array($provider, [SettingsService::PROVIDER_CHEERIO, SettingsService::PROVIDER_META], true)) {
                throw new \InvalidArgumentException('Invalid WhatsApp provider: ' . $provider);
            }
            $this->forcedProvider = $provider;
        }

        $this->driver = $this->resolveDriver();
        $this->driver->loadCredentials();

        return $this;
    }

    public function clearForcedProvider(): self
    {
        return $this->forceProvider(null);
    }

    public function getDriver(): CheerioDirectAPI|MetaCloudAPI
    {
        $this->ensureDriver();

        return $this->driver;
    }

    public function loadCredentials(): void
    {
        $this->driver = $this->resolveDriver();
        $this->driver->loadCredentials();
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->ensureDriver();

        if (! method_exists($this->driver, $name)) {
            throw new \BadMethodCallException(sprintf(
                'WhatsApp method %s() is not available on %s provider.',
                $name,
                $this->getProvider()
            ));
        }

        return $this->driver->{$name}(...$arguments);
    }

    /**
     * Re-bind driver if settings / forced provider changed (shared service / long-running CLI).
     */
    protected function ensureDriver(): void
    {
        $want = $this->effectiveProvider();
        $have = $this->driver instanceof MetaCloudAPI
            ? SettingsService::PROVIDER_META
            : SettingsService::PROVIDER_CHEERIO;

        if ($want !== $have) {
            $this->driver = $this->resolveDriver();
            $this->driver->loadCredentials();
        }
    }

    protected function effectiveProvider(): string
    {
        if ($this->forcedProvider !== null) {
            return $this->forcedProvider;
        }

        return $this->settings->getWhatsAppProvider();
    }

    protected function resolveDriver(): CheerioDirectAPI|MetaCloudAPI
    {
        if ($this->effectiveProvider() === SettingsService::PROVIDER_META) {
            return new MetaCloudAPI($this->settings, $this->config);
        }

        return new CheerioDirectAPI($this->settings, $this->config);
    }
}
