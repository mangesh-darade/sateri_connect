# Team Inbox — End-to-end Test Checklist

## Prerequisites

- [ ] Migration `AddOmnichannelInboxFields` applied (`php spark migrate`)
- [ ] WhatsApp provider configured (Cheerio or Meta) and inbound WA works
- [ ] Meta: Page ID + Page Access Token saved (encrypted)
- [ ] Toggles: Enable Instagram Inbox / Enable Messenger Inbox as needed
- [ ] Meta App: webhook URL = public `/webhooks`, subscribe Page + Instagram `messages`
- [ ] Permissions: `pages_messaging`, `instagram_manage_messages`, `pages_manage_metadata`

## Settings

- [ ] Save Page PAT → **Test Page Messaging** succeeds
- [ ] Instagram / Messenger toggles persist after reload

## Sidebar / routing

- [ ] Team Inbox → WhatsApp Inbox opens `/chat?channel=whatsapp`
- [ ] Instagram / Messenger links enabled when configured; otherwise muted + Settings hint
- [ ] Channel chips on Live Chat switch filters

## Inbound fixtures (optional POST to `/webhooks` in local/dev with unsigned allowed)

| Fixture | Expected |
|---------|----------|
| `tests/fixtures/webhooks/waba_inbound_text.json` | contact+conversation `channel=whatsapp`, external_id from wa_id |
| `tests/fixtures/webhooks/messenger_inbound_text.json` | `channel=messenger`, PSID |
| `tests/fixtures/webhooks/instagram_inbound_text.json` | `channel=instagram`, IGSID |

## Outbound

- [ ] WhatsApp text/template still via `service('whatsApp')`
- [ ] Instagram / Messenger reply via Page Messaging (no WA templates in UI)
- [ ] Outside 24h: WA shows template CTA; IG/Messenger show channel lock banner

## Regression

- [ ] Assign / close / reopen / unread / notes / export still work
- [ ] Cheerio WA + Meta IG/Messenger concurrent — no cross-send
- [ ] Unit: `phpunit tests/WebhookOmnichannelFixtureTest.php` (fixture shapes)
