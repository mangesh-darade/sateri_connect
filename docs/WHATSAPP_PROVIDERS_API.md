# Cheerio vs Meta — API Architecture (Deep Guide)

या document मध्ये आपण **कसे** WhatsApp messages पाठवतो / घेतो हे step-by-step समजून घेतो.  
Settings मध्ये **WhatsApp Provider** flag आहे: `cheerio` किंवा `meta`. त्या flag नुसार API client बदलतो.

---

## 1. एका वाक्यात

```
Chat / Campaign / Queue / KeywordBot
        ↓
service('whatsApp')  →  WhatsAppCloudAPI  (facade)
        ↓
   whatsapp_provider?
   ├── cheerio  →  CheerioDirectAPI   (newprod.api.cheerio.in)
   └── meta     →  MetaCloudAPI       (graph.facebook.com)
```

**महत्त्वाचं:** Chat, Campaigns, Templates, Queue यांना Cheerio/Meta माहित नसतं.  
ते फक्त `service('whatsApp')->sendText(...)` सारखी methods call करतात.  
Facade आतला driver निवडतो.

---

## 2. Setting flag कुठे असतो?

| Key | Value | Group |
|-----|--------|--------|
| `whatsapp_provider` | `cheerio` \| `meta` | `whatsapp` |

**UI:** Settings → **WhatsApp Provider** tab  
**Code:** `SettingsService::getWhatsAppProvider()`, `isCheerioProvider()`, `isMetaProvider()`  
**Helpers:** `whatsapp_provider()`, `is_cheerio_provider()`, `whatsapp_provider_label()`

दोन्ही providers चे credentials **वेगवेगळे** store होतात. Switch करणे safe आहे — दुसऱ्याचे keys erase होत नाहीत.

---

## 3. Files map (काय कुठे आहे)

| File | Role |
|------|------|
| `app/Libraries/WhatsAppCloudAPI.php` | Facade — driver निवड + `__call` forward |
| `app/Libraries/CheerioDirectAPI.php` | Cheerio HTTP client |
| `app/Libraries/MetaCloudAPI.php` | Meta Graph HTTP client |
| `app/Libraries/SettingsService.php` | Provider + credentials read/write |
| `app/Config/WhatsApp.php` | Base URLs, timeout, retries |
| `app/Libraries/WebhookValidator.php` | Inbound verify token + signature (active provider) |
| `app/Controllers/Webhooks.php` | Inbound messages/statuses process |
| `app/Config/Services.php` | `service('whatsApp')` factory |

---

## 4. Facade कसे काम करते?

`WhatsAppCloudAPI` constructor मध्ये:

1. Settings मधून `whatsapp_provider` वाचतो  
2. `meta` असल्यास `new MetaCloudAPI(...)`  
3. नाहीतर `new CheerioDirectAPI(...)`  
4. `sendText`, `sendTemplate`, `uploadMedia`, … सगळं `__call` ने driver कडे जाते

म्हणून पुरानं code (`Chat.php`, `QueueService.php`, …) बदलण्याची गरज नाही.

---

## 5. Cheerio Direct API — कसे लिहिलंय

### 5.1 Base + Auth

- **Base URL:** `https://newprod.api.cheerio.in/direct-apis`  
  (`Config\WhatsApp::$baseUrl`)
- **Auth header:** `x-api-key: {cheerio_api_key}`
- **Settings keys:**
  - `cheerio_api_key` (encrypted)
  - `cheerio_webhook_verify_token`
  - `cheerio_webhook_secret` (encrypted)

### 5.2 Request layer

`CheerioDirectAPI::request($method, $endpoint, $data)`:

1. API key check (`ensureConfigured`)
2. URL = `baseUrl + '/' + endpoint`
3. cURL (CodeIgniter `Services::curlrequest`)
4. JSON body (किंवा multipart media साठी)
5. Retry on `429 / 5xx` (exponential backoff)
6. Error → `RuntimeException` + log

### 5.3 मुख्य endpoints (आपण वापरतो)

| Action | Method | Endpoint |
|--------|--------|----------|
| Session text / image / … | `POST` | `v1/whatsapp/direct/send` |
| Template send | `POST` | `v1/whatsapp/template/send` |
| Upload → media id | `POST` | `v1/whatsapp/media-id` |
| Media helper | `POST` | `v1/whatsapp/media` |
| List templates | `GET` | `v1/getAllTemplates` |
| Create template | `POST` | `v1/whatsapp/create-template` |
| Get template | `GET` | `v1/whatsapp/template/{name}` |
| Message status | `GET` | `v1/whatsapp-status/{wamid}` |
| Contacts sync | `GET` | Cheerio contact APIs (`getContacts`) |
| Workflows sync | `GET` | Cheerio workflow APIs (`getWorkflows`) |

### 5.4 Text message example (Cheerio)

App call:

```php
service('whatsApp')->sendText('9198xxxxxxxx', 'Hello');
```

Cheerio driver आत `sendDirect` →:

```http
POST https://newprod.api.cheerio.in/direct-apis/v1/whatsapp/direct/send
Headers:
  x-api-key: YOUR_KEY
  Content-Type: application/json

{
  "to": "9198xxxxxxxx",
  "type": "text",
  "text": {
    "preview_url": false,
    "body": "Hello"
  }
}
```

### 5.5 Template send (Cheerio)

```http
POST .../v1/whatsapp/template/send

{
  "to": "9198xxxxxxxx",
  "data": {
    "name": "demo_july",
    "language": { "code": "en" },
    "components": [ ... ]
  }
}
```

**नोट:** Cheerio कधीकधी `components` undefined असताना crash होतो — म्हणून `ensureTemplateComponents()` ने header/body/button params auto-fill करतो (local template `raw_payload` वरून).

### 5.6 Cheerio-only features

- Contacts sync (`contacts/sync-cheerio`)
- Workflows sync (`automations/sync-cheerio`)
- Provider = Meta असताना हे buttons disable / backend reject

---

## 6. Meta Cloud API — कसे लिहिलंय

### 6.1 Base + Auth

- **Base URL:** `https://graph.facebook.com`  
  (`Config\WhatsApp::$graphBaseUrl`)
- **Version:** settings `meta_api_version` (default `v21.0`)
- **Auth header:** `Authorization: Bearer {meta_access_token}`
- **Settings keys:**
  - `meta_access_token` (encrypted)
  - `meta_phone_number_id`
  - `meta_waba_id`
  - `meta_api_version`
  - `meta_webhook_verify_token`
  - `meta_webhook_secret` (App Secret, encrypted)

### 6.2 Request layer

`MetaCloudAPI::request(...)` Cheerio सारखंच — पण:

- Header Bearer token
- URL = `https://graph.facebook.com/{version}/{endpoint}`

### 6.3 मुख्य endpoints

| Action | Method | Endpoint |
|--------|--------|----------|
| Send any message | `POST` | `/{phone-number-id}/messages` |
| Upload media | `POST` | `/{phone-number-id}/media` |
| Get media URL | `GET` | `/{media-id}` |
| List templates | `GET` | `/{waba-id}/message_templates` |
| Create template | `POST` | `/{waba-id}/message_templates` |
| Delete template | `DELETE` | `/{waba-id}/message_templates?name=` |
| Phone info / test | `GET` | `/{phone-number-id}?fields=...` |
| Mark read | `POST` | `/{phone-number-id}/messages` (`status: read`) |

### 6.4 Text message example (Meta)

```php
service('whatsApp')->sendText('9198xxxxxxxx', 'Hello');
```

Meta driver → `sendMessage`:

```http
POST https://graph.facebook.com/v21.0/{PHONE_NUMBER_ID}/messages
Headers:
  Authorization: Bearer EAAG...
  Content-Type: application/json

{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "9198xxxxxxxx",
  "type": "text",
  "text": {
    "preview_url": false,
    "body": "Hello"
  }
}
```

### 6.5 Template send (Meta)

```json
{
  "messaging_product": "whatsapp",
  "to": "9198xxxxxxxx",
  "type": "template",
  "template": {
    "name": "demo_july",
    "language": { "code": "en" },
    "components": [ ... ]
  }
}
```

### 6.6 Meta वर नसलेलं

- Cheerio-style **contacts directory** sync → empty / unsupported  
- Cheerio **workflows** sync → empty / unsupported  
(Meta कडे ही Direct APIs नाहीत.)

---

## 7. Same method names — वेगळं body

App नेहमी हेच public methods वापरते:

| Method | Cheerio आत | Meta आत |
|--------|------------|---------|
| `sendText` | `direct/send` | `/{phone}/messages` |
| `sendTemplate` | `template/send` | `/{phone}/messages` type=template |
| `sendImage` / video / doc / audio | `direct/send` | `/{phone}/messages` |
| `uploadMedia` | `media-id` | `/{phone}/media` |
| `getTemplates` | `getAllTemplates` | `/{waba}/message_templates` |
| `testConnection` | templates ping + checklist | GET phone number info |
| `getContacts` / `getWorkflows` | real Cheerio APIs | empty + `unsupported` |

म्हणून **business logic एकदा लिहावी** — transport switch फक्त Settings मधून.

---

## 8. Inbound webhooks (shared protocol)

दोन्ही providers **Meta-shaped** webhook वापरतात:

### Verify (GET)

```
GET /webhooks?hub.mode=subscribe&hub.verify_token=TOKEN&hub.challenge=12345
```

- Token match → plain text challenge return (HTTP 200)  
- Token **active provider** च्या settings मधून येतो  
  (`getActiveWebhookConfig()`)

### Receive (POST)

```
Header: X-Hub-Signature-256: sha256=HMAC(app_secret, raw_body)
Body: { "object": "whatsapp_business_account", "entry": [ ... ] }
```

- Signature check → active provider चा secret  
- Payload parse → contact upsert → message save → Live Chat / KeywordBot / Automations

**Shared URL:** `https://your-domain/.../webhooks`  
(Local: ngrok / cloudflared HTTPS)

---

## 9. App flow examples

### Outbound chat message

```
User types in Live Chat
  → Chat::send()
  → Queue / direct WhatsAppCloudAPI::sendText|sendTemplate|uploadMedia
  → CheerioDirectAPI OR MetaCloudAPI
  → Provider HTTP API
  → Message row saved (wa_message_id, status)
```

### Inbound customer reply

```
Customer sends WhatsApp
  → Cheerio OR Meta pushes webhook
  → Webhooks::receive
  → WebhookValidator (token/signature)
  → processPayload (messages / statuses)
  → contacts + messages + conversations
  → KeywordBot / AutomationEngine
  → Live Chat poll shows new message
```

### Template sync

```
Templates → Sync
  → WhatsAppCloudAPI::getTemplates()
  → Cheerio: getAllTemplates
     Meta:    /{waba}/message_templates
  → local `templates` table (column meta_id = remote id)
```

---

## 10. Credentials checklist

### Cheerio active असताना

1. Settings → Provider = Cheerio  
2. API Key paste → Save → Test connection  
3. Webhooks tab: verify token + public HTTPS URL  
4. Cheerio dashboard मध्ये Callback URL + same verify token  

### Meta active असताना

1. Settings → Provider = Meta  
2. Access Token, Phone Number ID, WABA ID, App Secret  
3. Save → Test connection (phone info येईल)  
4. Meta App → WhatsApp → Configuration: Callback URL + verify token  
5. Subscribe: `messages`, delivery/read fields  

---

## 11. Config defaults

`app/Config/WhatsApp.php`:

```php
public string $baseUrl = 'https://newprod.api.cheerio.in/direct-apis';
public string $graphBaseUrl = 'https://graph.facebook.com';
public string $graphApiVersion = 'v21.0';
public int $defaultTimeout = 30;
public int $maxRetries = 3;
```

Runtime credentials **DB settings** मधून येतात (encrypted where needed) — `.env` मध्ये API keys lock नाहीत.

---

## 12. Debug tips

| Problem | Check |
|---------|--------|
| Send fail | Active provider? Correct keys? `writable/logs/log-*.log` |
| Test connection fail | Cheerio: API key. Meta: token + phone number id |
| Webhook 403 on verify | Verify token mismatch (active provider bucket) |
| Webhook signature fail | Cheerio webhook secret / Meta App Secret |
| Contacts sync disabled | Provider Meta आहे — फक्त Cheerio support |
| Template sync empty (Meta) | `meta_waba_id` empty असू नये |

---

## 13. Short mental model

```
┌─────────────────────────────────────────┐
│  UI / Controllers / Queue / Automations │
│         service('whatsApp')             │
└──────────────────┬──────────────────────┘
                   │
         WhatsAppCloudAPI (facade)
                   │
     ┌─────────────┴─────────────┐
     │ whatsapp_provider setting │
     └─────────────┬─────────────┘
          cheerio  │  meta
           ▼              ▼
   CheerioDirectAPI   MetaCloudAPI
   x-api-key          Bearer token
   direct-apis/...    graph.facebook.com/{ver}/{phone|waba}/...
```

**एक flag → एक transport → सगळं app त्याच pipeline वर चालतं.**

---

## 14. Data कसा मागितला / पाठवला जातो? (Deep — Proper way)

### 14.1 Proper rule (हेच follow करा)

| ❌ चुकीचं | ✅ बरोबर |
|-----------|----------|
| Controller मध्ये `new CheerioDirectAPI` / `new MetaCloudAPI` | नेहमी `service('whatsApp')` |
| `if (cheerio) { curl cheerio... } else { curl meta... }` UI मध्ये | एकच method: `->sendText()`, `->getTemplates()` |
| दोन्ही providers एकाच वेळी call | फक्त **active** `whatsapp_provider` |
| Cheerio-only feature Meta वर force | `is_cheerio_provider()` check → disable / reject |

**का?**  
App च्या सर्व modules (Chat, Queue, Campaigns, Templates, Keywords, Automations) ला फक्त **एक common interface** माहित आहे.  
HTTP URL, headers, body shape — driver आत बदलतो. Caller बदलत नाही.

```php
// Proper — anywhere in app
$wa = service('whatsApp');
$wa->sendText('9198xxxxxxxx', 'Hello');
$wa->getTemplates();
$wa->uploadMedia($path, 'image/jpeg');

// Optional: who is active?
$wa->getProvider(); // 'cheerio' | 'meta'
```

Settings save केल्यावर नवीन request वर नवीन driver load होतो (`loadCredentials()` / नवा `service('whatsApp')` resolve).

---

### 14.2 Request layer — दोन्हीमध्ये समान pattern, वेगळं wire

दोन्ही clients मध्ये `request($method, $endpoint, $data)` आहे:

```
ensureConfigured()
  → buildUrl(endpoint)
  → curl + auth header
  → JSON (किंवा multipart)
  → retry 429/5xx
  → parse JSON → array
  → error → RuntimeException + log
```

| गोष्ट | Cheerio | Meta |
|--------|---------|------|
| Base | `https://newprod.api.cheerio.in/direct-apis` | `https://graph.facebook.com/{version}` |
| Auth | Header `x-api-key: {key}` | Header `Authorization: Bearer {token}` |
| Credentials source | `SettingsService::getCheerioConfig()` | `SettingsService::getMetaConfig()` |
| Extra IDs needed | फक्त API key (account API key ने scoped) | `phone_number_id` (send/media), `waba_id` (templates) |

---

### 14.3 Side-by-side: कोणता data कसा मागतो / पाठवतो

#### A) Text message पाठवणे

**App call (एकच):**
```php
service('whatsApp')->sendText($to, $text);
```

| | Cheerio | Meta |
|--|---------|------|
| Method | `POST` | `POST` |
| Path | `/v1/whatsapp/direct/send` | `/{phone_number_id}/messages` |
| Extra body | — | `messaging_product: whatsapp` |
| Type payload | `{ type, text: { body, preview_url } }` | same shape + Meta wrapper |

Cheerio body (concept):
```json
{
  "recipient_type": "individual",
  "to": "9198…",
  "type": "text",
  "text": { "preview_url": false, "body": "Hello" }
}
```

Meta body (concept):
```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "9198…",
  "type": "text",
  "text": { "preview_url": false, "body": "Hello" }
}
```

Image / video / document / audio / location / interactive — **same idea**: Cheerio → `direct/send`, Meta → `/{phone}/messages`.

---

#### B) Template message

**App call:**
```php
service('whatsApp')->sendTemplate($to, $name, $lang, $components);
```

| | Cheerio | Meta |
|--|---------|------|
| Path | `POST /v1/whatsapp/template/send` | `POST /{phone}/messages` |
| Body shape | `{ to, data: { name, language, components } }` | `{ messaging_product, to, type: "template", template: {…} }` |
| Components | **नेहमी** array (Cheerio `undefined.forEach` crash टाळण्यासाठी `ensureTemplateComponents`) | Optional — empty असल्यास skip |

---

#### C) Templates list (sync) — data **मागणे**

**App call:**
```php
service('whatsApp')->getTemplates();
```

| | Cheerio | Meta |
|--|---------|------|
| Path | `GET /v1/getAllTemplates?limit=500&after=…` | `GET /{waba_id}/message_templates?limit=100` |
| Scope | API key च्या account वर | WABA ID **अनिवार्य** — नाहीतर exception |
| Pagination | cursor `after` | Graph paging (`data` array) |
| Return | `{ data: [...] }` style normalize | `{ data, raw, provider: 'meta' }` |

**Proper:** Templates UI फक्त `getTemplates()` call करते — Cheerio/Meta URL माहित नसते.

---

#### D) Media upload / download

| Action | Cheerio | Meta |
|--------|---------|------|
| Upload | `POST /v1/whatsapp/media-id` (multipart `file`) | `POST /{phone}/media` (`messaging_product` + file) |
| Resolve URL | `POST /v1/whatsapp/media` `{ mediaId }` | `GET /{media_id}` |
| Download binary | GET resolved URL + `x-api-key` | GET resolved URL + `Bearer` |
| App return | साधारणतः `{ id: mediaId, … }` | `{ id, raw, provider: 'meta' }` |

**App call:**
```php
$id = service('whatsApp')->uploadMedia($path, $mime)['id'];
```

---

#### E) Contacts sync — Cheerio only

| | Cheerio | Meta |
|--|---------|------|
| API | `GET /v1/contact/getAll?page=&limit=&search=` | **नाही** — Graph वर Cheerio-style directory नाही |
| Driver | real list return | `{ data: [], unsupported: true }` |
| Controller gate | `Contacts` → `isCheerioProvider()` नाहीतर reject | Sync button disabled |

**Proper:**  
- Data fetch: `service('whatsApp')->getContacts()`  
- UI/Controller: `if (! is_cheerio_provider())` → disable / error message  
- Meta active असताना contacts **local DB** मधूनच (webhook/chat ने आलेले)

---

#### F) Workflows sync — Cheerio only

| | Cheerio | Meta |
|--|---------|------|
| API | `GET /v1/workflows` | unsupported empty |
| Gate | `Automations` controller + button disabled on Meta | — |

---

#### G) Connection test

| | Cheerio | Meta |
|--|---------|------|
| What it hits | templates / checklist (API key works?) | `GET /{phone_number_id}?fields=display_phone_number,…` |
| Settings UI | `POST /settings/test-cheerio` | `POST /settings/test-meta` |
| Note | दोन्ही test endpoints **वेगळे** — active provider नसला तरी credentials bucket test करता येतो | |

---

#### H) Inbound webhook data (receive)

Protocol **shared** (Meta-shaped). फरक फक्त **कोणत्या bucket** चे token/secret:

```
GET  ?hub.mode&hub.verify_token&hub.challenge
POST X-Hub-Signature-256 + WhatsApp entry payload
```

```php
$cfg = service('settingsService')->getActiveWebhookConfig();
// cheerio → cheerio_webhook_* 
// meta    → meta_webhook_* (App Secret)
```

Incoming message data shape दोन्हीकडून सारखीच parse होते (`Webhooks` controller) — provider switch येथे HTTP client नाही, फक्त auth.

---

### 14.4 Credentials buckets (data कुठून येतो)

| Setting key | Used by |
|-------------|---------|
| `whatsapp_provider` | Facade driver pick |
| `cheerio_api_key` | Cheerio `x-api-key` |
| `cheerio_webhook_verify_token` / `cheerio_webhook_secret` | Cheerio webhooks |
| `meta_access_token` | Meta Bearer |
| `meta_phone_number_id` | Meta send + media paths |
| `meta_waba_id` | Meta template list/create/delete |
| `meta_api_version` | Graph URL version |
| `meta_webhook_verify_token` / `meta_webhook_secret` | Meta webhooks |

Encrypted: API key, Cheerio secret, Meta token, Meta app secret.

**Proper:** दोन्ही buckets एकत्र save — switch केल्यावर दुसरं erase होत नाही. Active फक्त एक.

---

### 14.5 Feature matrix — कोणता data flow कुठे

| Feature | Cheerio | Meta | App कसं handle करते |
|---------|---------|------|---------------------|
| Send text/media/interactive | ✅ | ✅ | Facade methods |
| Send template | ✅ | ✅ | Facade (+ Cheerio component fill) |
| Sync templates | ✅ | ✅ (WABA ID) | Templates controller |
| Upload/download media | ✅ | ✅ | Facade |
| Sync contacts | ✅ | ❌ | Cheerio gate |
| Sync workflows | ✅ | ❌ | Cheerio gate |
| Webhook inbound | ✅ | ✅ | Active verify/secret |
| Message status poll | ✅ `whatsapp-status/{wamid}` | stub `unknown` | rare path |
| Phone display test | soft | ✅ Graph fields | test endpoints |

---

### 14.6 नवीन feature लिहिताना checklist (proper)

1. Controller/Library मध्ये **फक्त** `service('whatsApp')->methodName(...)`  
2. जर दोन्ही providers support करायचे → दोन्ही drivers मध्ये **same method name** implement करा  
3. जर फक्त Cheerio → Meta driver मध्ये empty/`unsupported` + UI/controller `is_cheerio_provider()` gate  
4. Response shape शक्यतो normalize करा (`id`, `data`, …) जेणेकरून caller provider-aware नसावा  
5. Settings UI मध्ये credentials **त्या provider च्या bucket** मध्येच  

---

### 14.7 एका दृष्टीक्षेपात data path

```
[Chat send / Campaign / Queue job]
        │
        ▼
 service('whatsApp')->sendText|sendTemplate|uploadMedia|…
        │
        ▼
 WhatsAppCloudAPI  (reads whatsapp_provider)
        │
        ├── cheerio → CheerioDirectAPI.request()
        │              headers: x-api-key
        │              URL: …/direct-apis/v1/...
        │
        └── meta    → MetaCloudAPI.request()
                       headers: Authorization Bearer
                       URL: …/graph.facebook.com/v21.0/{phone|waba|media}/...
        │
        ▼
 Provider HTTP response → array → save message / template / media id in OUR DB
```

**आपल्या MySQL मध्ये** contacts, messages, templates local store — provider फक्त **remote WhatsApp pipe** आहे.  
Local data model दोन्हीसाठी एकच; remote API वेगळी.

---

## 15. Messaging Channels (Team Inbox) — Instagram + Messenger

Cheerio/Meta-style **Team Inbox** मध्ये WhatsApp सोबत Instagram DMs आणि Facebook Messenger येतात.

### 15.1 Provider rule

| Channel | Outbound | Credentials |
|---------|----------|-------------|
| WhatsApp | `service('whatsApp')` → Cheerio **किंवा** Meta Cloud | `whatsapp_provider` + WA tokens |
| Instagram | `service('pageMessaging')` → Meta Page Messaging | `meta_page_id` + `meta_page_access_token` |
| Messenger | same Page Messaging client | same Page PAT |

Cheerio active असतानाही IG/Messenger **Meta Page PAT** वापरू शकतात (Settings → Meta → Team Inbox channels).

### 15.2 Settings keys

| Key | Notes |
|-----|--------|
| `meta_page_id` | Facebook Page ID |
| `meta_page_access_token` | encrypted Page Access Token |
| `meta_instagram_account_id` | optional IG business account id |
| `inbox_instagram_enabled` | gate for Instagram Inbox |
| `inbox_messenger_enabled` | gate for Messenger Inbox |

**Test:** Settings → **Test Page Messaging** → `POST /settings/test-page-messaging`

### 15.3 Schema

- `contacts.channel` = `whatsapp|instagram|messenger`
- `contacts.external_id` = wa_id / IGSID / PSID
- unique `(channel, external_id)`
- `conversations.channel`, optional `page_id`
- `messages.channel`, `external_message_id` (+ legacy `wa_message_id`/`wamid` for WA)

### 15.4 Webhooks

Same HTTPS endpoint `/webhooks`:

- `object === whatsapp_business_account` → existing WABA path
- `object === page` / `instagram` → `entry[].messaging[]` → unified contact/message pipeline

Meta App Dashboard मध्ये Page + Instagram वर `messages` subscribe करा. Permissions: `pages_messaging`, `instagram_manage_messages`, `pages_manage_metadata`.

### 15.5 Chat UI

- `/chat?channel=whatsapp|instagram|messenger|all`
- Sidebar **Team Inbox** flyout: WhatsApp / Instagram / Messenger (grey → Settings if not configured)
- WhatsApp-only templates; IG/Messenger use 24h messaging window without WA templates

### 15.6 Fixtures / checklist

Fixtures: `tests/fixtures/webhooks/`

Manual checklist:

1. Save Page PAT → Test Page Messaging
2. Enable IG/Messenger toggles
3. Sidebar opens each channel filter
4. Assign / close / unread / export per channel
5. Cheerio WA + Meta IG/Messenger concurrent (no cross-wire)

---

*Last updated for omnichannel Team Inbox (`whatsapp` | `instagram` | `messenger`).*
