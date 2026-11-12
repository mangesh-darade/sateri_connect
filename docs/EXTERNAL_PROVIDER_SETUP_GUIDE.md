# External (Cheerio) provider: setup, go-live and test guide

Use this guide when a client sends WhatsApp through the **External** provider (Cheerio Direct API) instead of Meta.
It covers what to deploy, what to fill in, how to connect the Cheerio webhook, how to connect the LMS, and how to
test that everything works. Follow the steps in order.

Related: [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md), [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md),
[Cheerio Postman collection](https://documenter.getpostman.com/view/13841235/2s9Y5Zvh9y).

---

## 1. How the two providers differ

| Topic | Meta active | External active |
|---|---|---|
| Sending | Meta Cloud API | Cheerio Direct API (`newprod.api.cheerio.in/direct-apis`) |
| Key used | Meta access token | External API key (`x-api-key`) |
| Delivered / seen ticks | Meta status webhook | Cheerio status webhook; if it does not arrive, the app asks Cheerio for the status while the chat is open |
| Campaigns | One message per contact through the queue | Bulk API in batches of 100 |
| Editing a sent message | Not possible in WhatsApp; the new text is sent as a new message | Same |
| Incoming messages, 24-hour window | Meta webhook | Cheerio webhook |

Only one provider is active per client. Workflows, the bot, auto-replies, campaigns, the inbox and the REST API all
use the active provider.

---

## 2. Before you start

1. The code with the External fixes is deployed on the server (step 3).
2. You have the client's **Cheerio API key** (Cheerio dashboard → Settings → API Key).
3. You know the client's **WhatsApp business number** with country code, digits only, for example `971563110791`.
4. You can log in to the Cheerio dashboard of that client to add a webhook.

---

## 3. Deploy

1. Commit and deploy the changes to the live server.
2. No database migration is needed for these changes.
3. Cron jobs stay as they are.

---

## 4. Settings in Sateri Connect (per External client)

Open **Settings → WhatsApp** in the client's workspace.

| Field | What to enter |
|---|---|
| Provider | **External** |
| External API Key | The Cheerio API key. A Meta access token no longer works in its place. |
| External / Elintom WhatsApp number | The business number with country code, digits only (`971563110791`). The first webhook is matched to the client by this number, so it must be correct. |
| Webhook Verify Token | Any value you choose. You will paste the same value in Cheerio. |
| Webhook Secret | A long random value. Paste the same value in Cheerio if Cheerio offers a secret / signature field. |

Save, then press **Test connection**. Both "External API key configured" and "Templates reachable" must be green.
Then open **Templates** and press **Sync templates** so the approved templates appear.

Copy the **Callback URL** shown under Settings → Webhooks ("Callback URL (editable — copy into provider)").
You need it in the next step.

---

## 5. Webhook in Cheerio

In the Cheerio dashboard of the client, open the webhook option and enter:

| Cheerio field | Value |
|---|---|
| Webhook / Callback URL | The Callback URL copied from Sateri Connect |
| Verify token | Same as "Webhook Verify Token" in Sateri Connect |
| Secret (if offered) | Same as "Webhook Secret" in Sateri Connect |
| Events | Turn on **messages** and **message status** (sent, delivered, read, failed) |

Notes:

- If customer replies already arrive in the inbox but ticks do not move, the webhook is connected but the
  **message status** event is off. Turn it on.
- On the live server a webhook without a valid signature is refused. Ask Cheerio support: "Do you sign webhooks
  with `X-Hub-Signature-256`, and with which secret?" If Cheerio does not sign, tell the development team before
  going live; do not switch off signature checking yourself.

---

## 6. LMS (ElintOm) chat panel

The LMS has one field, `whatsapp_api_key`, that it sends to Sateri Connect as the API key. For an External client
that field often still holds the Cheerio key, and then the LMS chat panel shows no chat and no templates.

In the LMS **System Settings** of the client:

| LMS field | Value |
|---|---|
| `whatsapp_api_key` | A Sateri Connect key that starts with `sc_live_`. Create it in Sateri Connect → Settings → **Developer REST API & Secret Keys**. |
| `whatsapp_tenant_key` | The workspace key of the client in Sateri Connect |

The Cheerio key stays only in Sateri Connect (step 4). With the tenant key set, the LMS sends all WhatsApp through
Sateri Connect, and Sateri Connect sends it through External.

---

## 7. Test after go-live

Do these on one External client. Use your own phone as the customer.

| # | Test | Expected result |
|---|---|---|
| 1 | From your phone, send "Hi" to the business number | The message appears in the inbox; the composer is unlocked (24-hour window open) |
| 2 | Reply with a text from the inbox | Your phone receives it; tick goes sent → delivered → seen while the chat is open |
| 3 | Send an approved template from the inbox | Received; ticks move as in test 2 |
| 4 | Trigger the bot / a workflow with buttons | Buttons received; ticks move |
| 5 | Edit a sent text and send | No error; the new text arrives as a new message; toast "Sent again as a new message." |
| 6 | Edit a text when the 24-hour window is closed | Clear message telling you to use a template; nothing is sent |
| 7 | Campaign with more than 100 contacts | Contacts are marked sent; delivered / read counts rise as statuses arrive |
| 8 | REST API `POST /api/v1/messages/send-template` without `language` | Sent; a template saved as `en` is found for the default `en_US` |
| 9 | Open a lead's chat panel in the LMS | Chat history and approved templates load |
| 10 | Repeat tests 2 and 3 on one Meta client | Works as before |

Quick API check (put your own key, number and template name):

```bash
curl -X POST https://YOUR-DOMAIN/index.php/api/v1/messages/send-template -H "X-API-Key: sc_live_YOUR_KEY" -H "Content-Type: application/json" -d '{"to":"+91XXXXXXXXXX","template_name":"your_template"}'
```

---

## 8. If something does not work

Look in `writable/logs/log-YYYY-MM-DD.log` on the server.

| What you see | Log line to look for | Meaning and fix |
|---|---|---|
| Ticks stay on "sent" | `External status answer not recognised` | Cheerio answered in a shape the app cannot read. Send the log line to the development team. |
| Ticks stay on "sent", no log line | none | Status webhook is off (step 5), or the message was sent before this update (old messages do not get ticks). |
| No incoming messages at all | `Webhook signature missing` / `Webhook signature mismatch` | Cheerio does not sign, or the secret differs. Make the secret the same on both sides, or ask Cheerio (step 5). |
| No incoming messages at all | `Webhook entry dropped: no tenant routes` | The business number in Settings (step 4) is empty or differs from the number Cheerio sends. Correct it and save. |
| Sending fails: "External API key is not configured" | same text | External API Key is empty (step 4). |
| LMS panel: "This is not an API key of this app…" | none | The LMS field holds the Cheerio key. Do step 6. |
| LMS panel: "API key not recognised…" | none | The `sc_live_` key was deleted, expired, or is from another workspace. Create a new one (step 6). |
| Template "was not found on this WhatsApp Business Account" | none | Sync templates. If a Meta WABA ID is also saved for this client and the External templates belong to another WABA, tell the development team (known open issue). |
| Campaign shows some contacts failed | `External bulk send failed` | One batch was refused; only that batch is failed. Do not resend the whole campaign, resend only the failed contacts. |

---

## 9. Rules that still apply (Meta policy)

- A free-form message (text, media, edited text) goes out only inside the 24-hour window. Outside it, use an
  approved template.
- STOP / opt-out, opt-in and template approval checks are the same for both providers.
- A send that the provider did not confirm (timeout or server error) is not repeated automatically, so a customer
  never gets the same message twice.

---

## 10. Known limits

- Messages sent on External before this update keep their old tick.
- The status check runs for the chat that is open, for that contact's latest 10 messages.
- Bulk campaigns on External get no message id per contact; a status is linked to the contact's latest campaign
  message from the last 3 days.
- The Cheerio status answer and webhook signing are not described in Cheerio's Postman collection, so both must be
  confirmed on the live account (steps 5, 7 and 8).

Automated checks for the code behind this guide: `php tests/ExternalProviderFixesTest.php`.
