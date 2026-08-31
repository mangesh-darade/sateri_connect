# Meta Provider Setup Guide

हा document **Meta Cloud API** provider साठी आहे.  
जर तुला “मीच WhatsApp provider वापरणार, Cheerio नको” असा setup करायचा असेल, तर Meta side वर नेमकं काय लागतं ते इथे step-by-step दिलं आहे.

हा guide खालील गोष्टी cover करतो:

- Meta provider use कधी करायचा
- काय काय credentials लागतात
- test mode vs live mode
- recipient allow-list
- webhook setup
- templates
- 24-hour window rules
- app मध्ये exact कुठे काय save करायचं
- production go-live checklist

---

## 1. एका वाक्यात

Meta provider वापरायचा म्हणजे:

**Meta Developer App + WhatsApp product + access token + phone number ID + WABA ID + webhook** हे सगळं लागणार.

फक्त token टाकून full setup complete होत नाही.

---

## 2. Meta provider कधी निवडायचा

Meta provider use करा जर:

- तुम्ही direct **Meta Cloud API** वापरू इच्छित असाल
- Cheerio/BSP शिवाय स्वतःचा WhatsApp API flow हवा असेल
- Meta Developer dashboard access तुमच्याकडे असेल
- webhook Meta मधून स्वतः configure करायचा असेल

Meta provider use करू नका जर:

- तुमचा WABA Cheerio/BSP fully managed असेल
- API key-based simpler Cheerio flow हवा असेल

---

## 3. Meta setup साठी काय काय लागतं

Minimum required:

1. Meta Developer account
2. Meta App
3. WhatsApp product app ला add केलेला
4. Access Token
5. Phone Number ID
6. WABA ID

Full working setup साठी additionally:

7. Public HTTPS webhook URL
8. Webhook verify token
9. `messages` webhook subscription
10. At least one approved template
11. Correct recipient number format
12. Test mode असल्यास recipient allow-list

---

## 4. Meta provider app मध्ये कुठे सेट करायचा

App UI:

`Settings -> WhatsApp Provider`

तिथे:

- provider = `Meta`

नंतर `Settings -> Meta` section मध्ये credentials भरायचे.

Required fields:

- Meta Access Token
- Meta Phone Number ID
- Meta WABA ID
- Meta API Version
- Webhook Verify Token

Optional related fields:

- Page ID / Page Access Token
- Instagram / Messenger fields

---

## 5. Meta side वर exact काय create करायचं

### Step 1: Meta app create करा

1. [Meta for Developers](https://developers.facebook.com/apps/) open करा
2. `Create App`
3. Business type app निवडा
4. App तयार करा

### Step 2: WhatsApp product add करा

1. App open करा
2. `Add Product`
3. `WhatsApp` निवडा
4. Setup complete करा

यामुळे Meta खालील test assets देतो:

- test phone number
- temporary access token
- WABA

---

## 6. App मध्ये कोणते values save करायचे

Meta dashboard मधून खालील values copy करा:

### 6.1 Access Token

Use for:

- outbound API requests
- phone lookup
- template send

### 6.2 Phone Number ID

Use for:

- `/messages` endpoint
- phone-specific sends

### 6.3 WABA ID

Use for:

- templates
- webhook subscriptions
- account-level operations

### 6.4 API Version

Use recommended Meta Graph version  
उदा. `v21.0`

---

## 7. Test mode vs Live mode

Meta मध्ये दोन practical states असतात:

### Test mode

हे development/testing साठी असतं.

Restrictions:

- random customer ला message नाही
- recipient allow-list लागते
- OTP verification लागतो
- test phone number वापरला जातो

Typical error:

`(#131030) Recipient phone number not in allowed list`

### Live mode

Production customers साठी.

Live mode मध्ये तुला generally लागेल:

- business verification
- proper WABA setup
- live phone number
- templates approval
- Meta policies follow

---

## 8. Recipient allow-list म्हणजे काय

जर Meta app अजून test mode मध्ये असेल, तर message फक्त verified test recipients ला जाईल.

### Add recipient steps

1. Meta App -> `WhatsApp`
2. `API Setup`
3. `Step 1. Try it out`
4. `Recipient` / `To` section
5. Number add करा country code सहित:

   `+91 7744010738`

6. OTP verify करा

Important:

- App request मध्ये हा number **same normalized format** मध्ये जावा लागतो
- safest DB format:

  `917744010738`

wrong example:

- `7744010738`

correct example:

- `917744010738`

---

## 9. Webhook setup — mandatory for inbound replies

Meta provider मध्ये webhook **required** आहे जर तुला हे हवं असेल:

- inbound customer replies
- Live Chat messages
- delivery/read/failed statuses
- automations on reply

### Callback URL कुठून घ्यायचा

App मध्ये:

`Settings -> Webhooks`

तिथला public callback URL copy करा.

Examples:

- root deploy:
  `https://your-domain.com/webhooks`

- subfolder deploy:
  `https://your-domain.com/sateri_connect/public/webhooks`

### Meta dashboard मध्ये कुठे टाकायचा

Meta Developer:

`WhatsApp -> Configuration`

तिथे:

- Callback URL
- Verify Token

save/verify करा.

### Subscribe कोणते field करायचे

At minimum:

- `messages`

यातून inbound messages आणि status updates मिळू शकतात.

---

## 10. Verify token कसा काम करतो

Verify token हा तू invent केलेला shared secret असतो.

तो:

- app मध्ये save करायचा
- Meta dashboard मध्ये exact same द्यायचा

Verification flow:

1. Meta GET request पाठवतो
2. app token compare करते
3. success असेल तर challenge return करते

Mismatch असेल तर webhook verify fail होतो.

---

## 11. Webhook नसताना काय होईल

चालेल:

- outbound send
- template send
- connection test

चालणार नाही:

- customer replies app मध्ये दिसणार नाहीत
- Live Chat inbound येणार नाही
- delivered / read updates येणार नाहीत
- reply-based automation चालणार नाही

Simple rule:

`Token = send`

`Webhook = receive`

---

## 12. Templates — first message साठी required

Meta मध्ये first/cold outbound message साठी approved template लागतो.

फक्त free text ने random customer ला message करता येत नाही.

### Template required when:

- customer ने अजून reply केलेला नाही
- 24-hour window बंद आहे

Typical error:

`(#131047) Re-engagement message`

### Template setup flow

1. approved template Meta side ला असावा
2. app मध्ये sync झालेला असावा
3. chat मधून `Template` send करावा

---

## 13. 24-hour customer care window

जर customer ने गेल्या 24 तासात reply केला असेल:

- free text चालतो

जर नाही:

- free text blocked
- template send करावा लागतो

हे Meta rule आहे, code bug नाही.

---

## 14. Number formatting best practice

Meta provider मध्ये number formatting खूप important आहे.

Always store/send:

- digits only
- country code सहित

Examples:

- correct: `917744010738`
- wrong: `7744010738`

जर allow-list / recipient exact match असेल, आणि app ने short number पाठवला, तर send fail होऊ शकतो even if recipient already added असेल.

---

## 15. Production ला जायचं असेल तर काय लागेल

“मीच WhatsApp provider होणार” या अर्थाने Meta direct वापरून proper go-live साठी हे लागते:

1. Meta App ready
2. WhatsApp product ready
3. Business verification understood/completed
4. Live phone number
5. Permanent access token
6. Phone Number ID saved
7. WABA ID saved
8. Public HTTPS callback URL
9. Verify token set
10. `messages` field subscribed
11. Approved templates
12. App production environment

---

## 16. Suggested implementation sequence

### Stage A — local/dev test

1. Meta provider select करा
2. temporary token टाका
3. test phone number / phone ID वापरा
4. recipient add + OTP verify करा
5. template send करून बघा

### Stage B — inbound test

1. public webhook URL तयार करा
2. Meta dashboard मध्ये callback + token टाका
3. `messages` subscribe करा
4. business number ला WhatsApp वरून reply करा
5. Live Chat मध्ये inbound दिसतो का ते पहा

### Stage C — production

1. live domain
2. production `.env`
3. permanent token
4. real templates
5. real customer flow

---

## 17. Common errors

### Error: Recipient phone number not in allowed list

Cause:

- app test mode मध्ये आहे
- recipient allow-list मध्ये नाही
- किंवा request wrong number format पाठवत आहे

Fix:

- recipient add करा
- OTP verify करा
- DB number `91...` format मध्ये ठेवा

### Error: Re-engagement message

Cause:

- 24-hour window बंद

Fix:

- approved template send करा

### Error: No inbound replies in chat

Cause:

- webhook configured नाही
- verify token mismatch
- webhook subscribed नाही
- callback URL public नाही

Fix:

- callback + token + `messages` subscription complete करा

---

## 18. Meta provider checklist

- [ ] Meta provider selected
- [ ] Access token saved
- [ ] Phone Number ID saved
- [ ] WABA ID saved
- [ ] API version saved
- [ ] Verify token saved
- [ ] Public callback URL available
- [ ] Webhook verified
- [ ] `messages` subscribed
- [ ] Recipient allow-list done if test mode
- [ ] At least one approved template
- [ ] Contact numbers country code सहित stored
- [ ] Template send works
- [ ] Inbound reply appears in Live Chat

---

## 19. Final conclusion

Meta provider properly चालवायचा असेल तर फक्त token पुरेसा नाही.

तुला हे complete करावंच लागेल:

- token
- phone number ID
- WABA ID
- recipient verification (test mode)
- template readiness
- webhook callback
- verify token
- messages subscription

जर हे सगळं complete असेल तर:

- outbound send
- template send
- inbound replies
- Live Chat
- delivery statuses

सगळं व्यवस्थित चालू शकतं.

---

## 20. Related docs

- `docs/PROVIDER_SETUP_GUIDE.md`
- `docs/WHATSAPP_PROVIDERS_API.md`
- `docs/WEBHOOK_SETUP.md`
- `docs/SETTINGS.md`
