<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Smoke checks for omnichannel webhook fixtures (shape + channel detection).
 *
 * @internal
 */
final class WebhookOmnichannelFixtureTest extends CIUnitTestCase
{
    private function loadFixture(string $name): array
    {
        $path = ROOTPATH . 'tests/fixtures/webhooks/' . $name;
        $this->assertFileExists($path);
        $json = file_get_contents($path);
        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertIsArray($data);

        return $data;
    }

    public function testWabaFixtureShape(): void
    {
        $payload = $this->loadFixture('waba_inbound_text.json');
        $this->assertSame('whatsapp_business_account', $payload['object']);
        $msg = $payload['entry'][0]['changes'][0]['value']['messages'][0];
        $this->assertSame('919876543210', $msg['from']);
        $this->assertSame('wamid.FIXTURE_WA_001', $msg['id']);
        $this->assertSame('Hello from WhatsApp fixture', $msg['text']['body']);
    }

    public function testMessengerFixtureShape(): void
    {
        $payload = $this->loadFixture('messenger_inbound_text.json');
        $this->assertSame('page', $payload['object']);
        $event = $payload['entry'][0]['messaging'][0];
        $this->assertSame('PSID_FIXTURE_001', $event['sender']['id']);
        $this->assertSame('m_FIXTURE_MESSENGER_001', $event['message']['mid']);
        $this->assertSame('Hello from Messenger fixture', $event['message']['text']);
    }

    public function testInstagramFixtureShape(): void
    {
        $payload = $this->loadFixture('instagram_inbound_text.json');
        $this->assertSame('instagram', $payload['object']);
        $event = $payload['entry'][0]['messaging'][0];
        $this->assertSame('IGSID_FIXTURE_001', $event['sender']['id']);
        $this->assertSame('m_FIXTURE_INSTAGRAM_001', $event['message']['mid']);
        $this->assertSame('Hello from Instagram fixture', $event['message']['text']);
    }

    public function testChannelRoutingHints(): void
    {
        $wa = $this->loadFixture('waba_inbound_text.json');
        $fb = $this->loadFixture('messenger_inbound_text.json');
        $ig = $this->loadFixture('instagram_inbound_text.json');

        $this->assertNotSame('page', $wa['object']);
        $this->assertSame('page', $fb['object']);
        $this->assertSame('instagram', $ig['object']);
    }
}
