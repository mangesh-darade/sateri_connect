# Provider Audit Checklist

हा document WhatsApp provider setup audit साठी आहे.  
तुला हे बघायचं असेल:

- provider साठी काय **required** आहे
- आपल्या app/code मध्ये काय **already आहे**
- अजून काय **verify करायचं** आहे
- आणि ते **कसं verify करायचं**

तर हा document direct वापर.

हा doc दोन providers cover करतो:

1. Meta
2. Cheerio

---

## 1. कसा वापरायचा

प्रत्येक item साठी 4 गोष्टी दिल्या आहेत:

- **Required** — लागणारं
- **Aplyakade aahe?** — app/code side ने support आहे का
- **Verify karaycha?** — अजून manually check करायचं का
- **Kasa baghaycha?** — exact कुठे check करायचं

Status meaning:

- `AAHE` = आपल्या code/app side ने support आहे
- `VERIFY` = dashboard/provider side वर check करायचं आहे
- `NAHI / PENDING` = अजून complete नाही किंवा proof नाही

---

## 2. Meta Audit Checklist

### 2.1 Meta provider select

- **Required:** provider = `Meta`
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> WhatsApp Provider`
  - active provider `Meta` आहे का बघा

---

### 2.2 Access token

- **Required:** valid Meta access token
- **Aplyakade aahe?** `AAHE` (field/support/code)
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Meta`
  - token saved आहे का
  - `Test connection` चालतो का

---

### 2.3 Phone Number ID

- **Required:** Meta Phone Number ID
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Meta`
  - Meta dashboard -> `WhatsApp -> API Setup`
  - ID same आहे का compare करा

---

### 2.4 WABA ID

- **Required:** WhatsApp Business Account ID
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Meta`
  - Meta dashboard -> `WhatsApp -> API Setup`

---

### 2.5 API version

- **Required:** Graph API version
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Meta`
  - current configured version check करा

---

### 2.6 Webhook callback URL

- **Required:** public HTTPS callback URL
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Webhooks`
  - callback URL copy करा
  - browser मध्ये URL open करून plain page/403 expected behavior पहा

---

### 2.7 Webhook verify token

- **Required:** verify token
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Meta` किंवा `Settings -> Webhooks`
  - token generated/saved आहे का
  - Meta dashboard मध्ये exact same token आहे का

---

### 2.8 Webhook verified in Meta dashboard

- **Required:** callback URL + token verified
- **Aplyakade aahe?** `PARTIAL`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta Developer -> `WhatsApp -> Configuration`
  - callback URL verified status check करा

---

### 2.9 `messages` subscription

- **Required:** `messages` field subscribed
- **Aplyakade aahe?** `PARTIAL` (code support आहे)
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta dashboard -> webhook configuration
  - `messages` enabled आहे का check करा

---

### 2.10 Approved template

- **Required:** at least one approved template
- **Aplyakade aahe?** `AAHE` (template flow/code/docs)
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Template Library`
  - status `APPROVED` आहे का
  - chat मधून template send होते का

---

### 2.11 Recipient allow-list (test mode only)

- **Required:** test mode असेल तर recipient verified
- **Aplyakade aahe?** `NOT APP-SIDE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta -> `WhatsApp -> API Setup -> Step 1. Try it out`
  - recipient number दिसतो का
  - OTP verified आहे का

---

### 2.12 Test mode vs live mode

- **Required:** production साठी live mode understood
- **Aplyakade aahe?** `APP TOGGLE NAHI`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta dashboard वर app/test setup indicators पहा
  - जर allow-list लागू आहे तर practicaly test mode आहे
  - live production sending होतंय का ते verify करा

---

### 2.13 Business verification

- **Required:** production readiness साठी needed असू शकते
- **Aplyakade aahe?** `UNKNOWN`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta Business Manager
  - business verification status पहा

---

### 2.14 Permanent token

- **Required:** production साठी preferred/required
- **Aplyakade aahe?** `UNKNOWN`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Meta token temporary आहे का permanent ते dashboard/documentation वर check करा
  - token expiry पाहा

---

### 2.15 Correct number format

- **Required:** country code सहित normalized number
- **Aplyakade aahe?** `PARTIAL`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - contacts table / Contacts UI
  - correct format: `917744010738`
  - wrong format: `7744010738`

---

### 2.16 Inbound replies work

- **Required:** webhook + messages subscription + number mapping
- **Aplyakade aahe?** `AAHE` (code side)
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - customer ने WhatsApp message पाठवावा
  - Live Chat मध्ये inbound दिसतो का बघा

---

### 2.17 Meta final audit summary

#### Already available

- provider support
- token support
- phone number ID support
- WABA ID support
- webhook handler
- template send
- chat integration

#### Must verify manually

- live vs test mode
- recipient allow-list if test mode
- business verification
- permanent token
- messages subscription
- webhook verified in dashboard
- contact number formatting

---

## 3. Cheerio Audit Checklist

### 3.1 Cheerio provider select

- **Required:** provider = `cheerio`
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> WhatsApp Provider`

---

### 3.2 Cheerio API key

- **Required:** valid API key
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Cheerio API`
  - `Test Cheerio Connection` run करा

---

### 3.3 Webhook callback URL

- **Required:** public HTTPS callback
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Webhooks`
  - callback URL copy करा

---

### 3.4 Verify token

- **Required:** webhook verify token
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Settings -> Cheerio API`
  - same token provider side वर आहे का बघा

---

### 3.5 Webhook secret

- **Required:** signature validation साठी recommended
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `OPTIONAL BUT RECOMMENDED`
- **Kasa baghaycha?**
  - App -> `Settings -> Cheerio API`
  - secret configured आहे का

---

### 3.6 Callback URL provider side ला configured आहे का

- **Required:** हो
- **Aplyakade aahe?** `PARTIAL`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Cheerio dashboard / WABA webhook settings
  - field नसल्यास Cheerio support ला confirm करा

---

### 3.7 `messages` / status events enabled

- **Required:** हो
- **Aplyakade aahe?** `PARTIAL`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Cheerio / WABA webhook configuration check करा
  - support team कडून confirmation घ्या

---

### 3.8 Approved templates

- **Required:** first/cold outbound साठी
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - App -> `Template Library`
  - sync नंतर statuses `APPROVED` आहेत का

---

### 3.9 Live WABA / premium status

- **Required:** production use साठी
- **Aplyakade aahe?** `UNKNOWN`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - Cheerio team / dashboard side वर confirm करा

---

### 3.10 Inbound replies work

- **Required:** webhook must be active
- **Aplyakade aahe?** `AAHE` (code side)
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - business number ला WhatsApp reply पाठवा
  - Live Chat मध्ये inbound येतो का बघा

---

### 3.11 Delivery/read statuses

- **Required:** webhook किंवा status polling
- **Aplyakade aahe?** `AAHE`
- **Verify karaycha?** `YES`
- **Kasa baghaycha?**
  - message send करा
  - status `sent -> delivered -> read` बदलतो का पाहा

---

### 3.12 Cheerio final audit summary

#### Already available

- API key support
- outbound send support
- webhook callback support in app
- verify token support
- secret support
- inbound parser
- template sync

#### Must verify manually

- callback URL actually configured on Cheerio/WABA side
- messages/status events enabled
- live WABA status
- premium/live account readiness

---

## 4. Meta vs Cheerio — simplest final rule

### Meta

- token = send
- webhook = receive
- test mode = allow-list
- live mode = business/dashboard readiness

### Cheerio

- API key = send
- webhook = receive
- callback field UI मध्ये नसलं तरी WABA side configuration लागते

---

## 5. Related docs

- `docs/META_PROVIDER_SETUP_GUIDE.md`
- `docs/PROVIDER_SETUP_GUIDE.md`
- `docs/CHEERIO_CONFIGURATION.md`
- `docs/WEBHOOK_SETUP.md`
- `docs/WHATSAPP_PROVIDERS_API.md`
