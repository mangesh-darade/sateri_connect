# WhatsApp Provider Setup Guide

Cheerio आणि Meta हे दोन्ही providers या app मध्ये supported आहेत.  
हा document practical setup guide आहे: कोणत्या provider साठी काय लागते, कुठे काय भरायचे, काय feature चालते, आणि replies/inbound साठी काय extra लागते.

---

## 1. Quick summary

### Cheerio

- `cheerio_api_key` दिल्यावर outbound send चालू शकतो
- inbound replies साठी webhook callback URL लागतो
- callback option Cheerio API key page वर नसू शकतो; तो WABA/webhook side किंवा Cheerio team configure करू शकते

### Meta

- access token + phone number ID + WABA ID लागते
- test mode मध्ये recipient allow-list लागते
- inbound replies आणि status updates साठी webhook configuration लागते

---

## 2. Provider choose कधी करायचा

### Cheerio use करा जर:

- तुम्ही Cheerio Direct APIs वापरत असाल
- Cheerio dashboard / onboarding team तुमचा WABA manage करत असेल
- API key based outbound sending हवा असेल

### Meta use करा जर:

- तुम्ही direct Meta Cloud API वापरत असाल
- Meta Developer app, token, phone number ID, WABA ID तुमच्याकडे असेल
- webhook Meta dashboard मधून configure करायचा असेल

---

## 3. App मध्ये provider कुठे set करायचा

App UI:

`Settings -> WhatsApp Provider`

तिथे:

- `Cheerio`
- `Meta`

यापैकी एक active provider select करायचा.

Important:

- credentials वेगवेगळे save राहतात
- provider switch केल्यावर दुसऱ्याचे keys delete होत नाहीत

---

## 4. Cheerio setup

### 4.1 Required fields

App मध्ये `Settings -> Cheerio API` मध्ये:

- Cheerio API Key
- Webhook Verify Token
- Webhook Secret (optional but recommended)
- Cheerio / Elintom WhatsApp number

### 4.2 Minimum working setup

फक्त outbound send साठी:

- Cheerio API Key

### 4.3 Full working setup

send + receive + Live Chat + reply tracking साठी:

- Cheerio API Key
- Public HTTPS webhook URL
- Verify token
- Webhook secret/signature setup
- Correct business number mapping

### 4.4 Callback URL कुठे घ्यायचा

App मध्ये:

`Settings -> Webhooks`

तिथे callback URL copy करायचा.

Examples:

- production root:
  `https://your-domain.com/webhooks`
- subfolder deploy:
  `https://your-domain.com/sateri_connect/public/webhooks`

### 4.5 Cheerio मध्ये callback URL कुठे टाकायचा

Important:

Cheerio Direct API key page वर callback URL option दिसेलच असं नाही.

तो setting generally:

- Cheerio dashboard -> WhatsApp / WABA / Webhook settings
- किंवा Cheerio onboarding/support team configure करते

म्हणून practical flow:

1. App मधून callback URL copy करा
2. Verify token generate/save करा
3. Cheerio support ला callback URL + verify token पाठवा
4. त्यांना live WABA वर webhook attach करायला सांगा

### 4.6 Cheerio support ला काय पाठवायचं

```text
Hi Team,

We are using Cheerio Direct API in our app.
Outbound API sending works, but inbound customer replies are not reaching our system.

Please configure / attach our WABA webhook:

Callback URL:
https://YOUR-DOMAIN/.../webhooks

Verify Token:
YOUR_VERIFY_TOKEN

Required events:
- messages
- delivery / status updates

Please confirm once configured.
```

### 4.7 Without callback URL काय होईल

चालेल:

- outbound text/template send

चालणार नाही:

- inbound customer replies in Live Chat
- delivery/read/failed status updates
- reply-driven automations
- keyword bot on inbound

Simple rule:

`API key only = send only`

---

## 5. Meta setup

### 5.1 Required fields

App मध्ये `Settings -> Meta` मध्ये:

- Meta Access Token
- Meta Phone Number ID
- Meta WABA ID
- Meta API Version
- Webhook Verify Token

### 5.2 Minimum setup

Outbound API test साठी:

- access token
- phone number ID

### 5.3 Full working setup

send + receive + chat + statuses साठी:

- access token
- phone number ID
- WABA ID
- webhook callback URL
- verify token
- subscribed webhook fields

### 5.4 Meta webhook setup

Meta Developer:

`WhatsApp -> Configuration`

तिथे:

- Callback URL
- Verify Token

नंतर `messages` field subscribe करायचा.

### 5.5 Meta test mode rule

जर app test/development mode मध्ये असेल तर:

- recipient allow-list मध्ये number add करावा लागतो
- OTP verify करावा लागतो

नाहीतर हा error येतो:

`(#131030) Recipient phone number not in allowed list`

### 5.6 24-hour window rule

जर customer ने गेल्या ~24 तासात reply केलेला नसेल तर:

- normal free text blocked
- approved template send करावा लागतो

Typical error:

`(#131047) Re-engagement message`

---

## 6. Number formatting rules

दोन्ही providers साठी safest format:

- country code सहित digits only

Example:

- wrong: `7744010738`
- correct: `917744010738`

जर provider allow-list / recipient matching वापरत असेल, आणि app ने country code शिवाय number पाठवला, तर send fail होऊ शकतो.

---

## 7. Inbound replies actually कसे येतात

Inbound reply flow:

1. Customer business number ला message करतो
2. Provider webhook app च्या `/webhooks` endpoint ला POST करतो
3. App message save करते
4. Conversation update होते
5. Live Chat मध्ये message दिसतो
6. Status / automation triggers run होतात

म्हणून webhook नसताना app ला customer reply झाल्याचं कळतच नाही.

---

## 8. Provider mismatch problem

हा common issue आहे:

- inbound message Cheerio number वर येतो
- active provider Meta आहे

किंवा उलट

यामुळे:

- chat मध्ये confusion
- auto-replies skip
- wrong number वरून reply attempt

Best practice:

- ज्या number/provider वर customer chat करतो, त्याच provider flow मध्ये reply करा
- live use case साठी number mapping clean ठेवा

---

## 9. Local vs production

### Local

- `localhost` provider ला reachable नसतो
- ngrok / cloudflared / public tunnel लागतो

### Production

- public HTTPS domain वापरा
- `/webhooks` reachable असायला पाहिजे
- verify token provider side आणि app side same असला पाहिजे

---

## 10. Recommended setup checklist

### Cheerio checklist

- [ ] Cheerio API key saved
- [ ] Verify token saved
- [ ] Webhook secret saved
- [ ] Public callback URL copied from app
- [ ] Callback URL Cheerio / WABA side ला configured
- [ ] `messages` events enabled
- [ ] Business number correct format मध्ये saved
- [ ] Test outbound send works
- [ ] Test inbound reply appears in Live Chat

### Meta checklist

- [ ] Access token saved
- [ ] Phone Number ID saved
- [ ] WABA ID saved
- [ ] Webhook callback configured
- [ ] Verify token configured
- [ ] `messages` field subscribed
- [ ] Approved template exists
- [ ] Recipient allow-list done if test mode
- [ ] Contact number country code सहित saved
- [ ] Template send works

---

## 11. Troubleshooting

### Problem: Outbound works, inbound reply दिसत नाही

Likely causes:

- callback URL configured नाही
- webhook verify fail
- provider webhook wrong URL ला hit करतो
- local URL provider ला reachable नाही

### Problem: Meta template says recipient not allowed

Cause:

- test mode allow-list missing
- किंवा app wrong number format पाठवत आहे

### Problem: Free text blocked

Cause:

- 24-hour window closed

Fix:

- approved template send करा

### Problem: Chat मध्ये replies येतात पण automation होत नाही

Cause:

- inbound provider आणि active provider mismatch

---

## 12. Final rule

### Cheerio

- API key = send
- webhook = receive

### Meta

- token + phone number ID = send
- webhook + subscription = receive
- test mode = allow-list required

---

## 13. Related docs

- `docs/CHEERIO_CONFIGURATION.md`
- `docs/WEBHOOK_SETUP.md`
- `docs/WHATSAPP_PROVIDERS_API.md`
- `docs/SETTINGS.md`
- `docs/DEPLOYMENT.md`
