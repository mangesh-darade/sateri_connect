# Meta WhatsApp — Publish / Go Live (All Steps in English)

**Who this is for:** Taking your Meta WhatsApp app from testing to published / live use  
**App example:** ElintOM Connect Api  
**Related:** [META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md) (API test calls only)

---

## First: which path are you on?

| Path | When to use | Do you need App Review? |
|------|-------------|-------------------------|
| **A — Direct developer (own business only)** | You send WhatsApp for **your own** company WABA only | Usually **No** Advanced Access / App Review |
| **B — Platform / Tech Provider** | Other businesses use your app (multi-tenant / Embedded Signup) | **Yes** — Business Verification + App Review + Advanced Access |

Most SaaS products (like Sateri Connect serving many customers) need **Path B**.  
If you only message customers of one company you own, use **Path A**.

---

# PATH A — Own business only (simpler)

## A1. Finish testing

1. Open [developers.facebook.com](https://developers.facebook.com) → your app  
2. WhatsApp → **API Setup**  
3. Add your real recipient numbers to the test list (OTP)  
4. Send a template message successfully  
5. Complete API calls in Graph API Explorer  
   → Full steps: [META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md)

## A2. Create a permanent System User token

Temporary tokens expire. For production:

1. Open [Meta Business Settings](https://business.facebook.com/settings)  
2. **Users → System users** → **Add**  
3. Create Admin system user  
4. **Assign assets**:
   - Your Meta App → full control  
   - Your WhatsApp Business Account → full control  
5. **Generate token** → select app → add permissions:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
   - `business_management`  
6. Copy token and store it safely (password manager / server env — **not** in git)

## A3. Add a real phone number (if still on test number)

1. WhatsApp Manager / Meta Business → WhatsApp accounts  
2. Add and verify a real business phone number  
3. Copy the new **Phone number ID** into your app Settings

## A4. Configure webhooks (production HTTPS)

1. App Dashboard → WhatsApp → **Configuration**  
2. Callback URL = your public HTTPS webhook  
3. Verify token = same value as in app Settings  
4. Subscribe to **messages** (and status fields you need)  
5. Confirm webhook verifies green

## A5. Save credentials in Sateri Connect

1. App → **Settings → WhatsApp Provider** = Meta  
2. Paste:
   - Access token (System User)  
   - Phone number ID  
   - WABA ID  
   - API version  
3. Click **Test Meta Connection**  
4. Sync templates  
5. Send a real template from Live Chat / Campaigns

## A6. Publish app mode (if required by dashboard)

1. App Dashboard → **App settings / App mode**  
2. Switch from **Development** to **Live** only after:
   - Privacy policy URL is set  
   - Required checklist items are green  
3. Confirm business is not restricted

**Path A done** when: permanent token works, real number sends, webhook receives inbound messages.

---

# PATH B — Publish for other businesses (App Review)

Use this if customers will connect **their** WhatsApp accounts through your product.

## B1. Complete app basic settings

1. App Dashboard → **App settings → Basic**  
2. Fill:
   - App icon  
   - Privacy Policy URL (must be public HTTPS)  
   - Terms of Service URL (recommended)  
   - App category  
   - Contact email  
3. Save changes

## B2. Verify your business (required before App Review)

1. [Business Settings → Security Center / Business info](https://business.facebook.com/settings)  
2. Start **Business verification**  
3. Provide:
   - Legal business name  
   - Address  
   - Phone / email  
   - Website  
   - Documents if Meta asks (registration, utility bill, etc.)  
4. Wait until status = **Verified**

You cannot submit App Review until verification is approved.

## B3. Complete API test calls (dashboard requirements)

Do every required permission test in Graph API Explorer:

1. `GET me?fields=id,name` → `public_profile`  
2. `GET {WABA_ID}?fields=id,name` → `whatsapp_business_management`  
3. `POST {PHONE_NUMBER_ID}/messages` → `whatsapp_business_messaging`  
4. `GET me/businesses` → `business_management`  

Full copy-paste steps: [META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md)

Then refresh **Permissions and features**. Counters may take **15–60 minutes** (sometimes longer) to update from `0`.

## B4. Prepare screen recordings (mandatory)

Meta rejects vague videos. Record **separate** videos for each permission.

### Video 1 — `whatsapp_business_messaging`

Show:

1. Login to **your product** (Sateri Connect)  
2. Open Live Chat / Campaigns  
3. Send a WhatsApp message from the UI  
4. Show the message arriving on WhatsApp mobile/web  
5. Keep faces/credentials out of the video if possible  

### Video 2 — `whatsapp_business_management`

Show:

1. Your product (or WhatsApp Manager via your flow)  
2. Creating / listing / syncing a **message template**  
3. Clear UI proof that the app manages WABA assets  

### Video 3 — `business_management` (if requested)

Show how your app uses business assets (business list / portfolio connection / onboarding).

**Tips**

- One permission = one video + one written explanation  
- Speak or add on-screen captions explaining what is happening  
- Do **not** request permissions you do not use  

## B5. Write permission justifications

For each permission in App Review form, explain in clear English:

**Example — `whatsapp_business_messaging`:**  
“Our SaaS platform lets verified business customers send WhatsApp template and session messages to their end customers through Meta Cloud API. Agents use Live Chat to reply within the customer service window.”

**Example — `whatsapp_business_management`:**  
“Our platform syncs and manages the customer’s WhatsApp Business Account assets (phone numbers, message templates, webhook subscription) after they connect their WABA.”

**Example — `business_management`:**  
“We use this permission to identify and manage the Meta Business Portfolio linked during customer onboarding.”

## B6. Submit App Review (Advanced Access)

1. App Dashboard → **App Review → Permissions and features**  
   (or Use case → Permissions → Request Advanced Access)  
2. For each needed permission click **Request Advanced Access** / **Complete form**  
3. Upload:
   - Written explanation  
   - Screen recording  
4. Submit for review  
5. Track status under **App Review → Requests**

Typical review time: a few days to a couple of weeks.

## B7. After approval

1. Regenerate tokens with the approved scopes (old tokens do not auto-upgrade)  
2. Create / refresh **System User** token with:
   - `whatsapp_business_messaging`  
   - `whatsapp_business_management`  
   - `business_management`  
3. Switch app to **Live** mode  
4. If you are a Tech Provider: finish Tech Provider / solution provider checklist in the dashboard  
5. Enable customer onboarding (Embedded Signup if you use it)

## B8. Production checklist in Sateri Connect

- [ ] Provider = Meta  
- [ ] Permanent System User token saved  
- [ ] Correct Phone Number ID + WABA ID  
- [ ] Public HTTPS webhook verified  
- [ ] Templates synced and approved  
- [ ] Test outbound template  
- [ ] Test inbound reply in Live Chat  
- [ ] Cron/queue workers running for campaigns (if used)

---

# Publishing requirements checklist (dashboard)

On **Connect with customers through WhatsApp**, Meta may list items like:

| Requirement | What to do |
|-------------|------------|
| API test calls for permissions | Complete Steps in [META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md) |
| Business verification | Path B2 |
| Privacy policy URL | Path B1 |
| App Review / Advanced Access | Path B4–B6 |
| App mode Live | After approvals |

Open the use-case page and clear every red / incomplete item one by one.

---

# Common mistakes

1. **Dashboard still shows API Calls = 0** after successful sends → wait and hard-refresh; successful Explorer JSON is the real proof.  
2. **POST to WABA ID** instead of `PHONE_NUMBER_ID/messages` → permissions / wrong-endpoint errors.  
3. **`me/businesses` with POST** → “parameter name is required”. Use **GET**.  
4. **Temporary token in production** → breaks after expiry. Use System User token.  
5. **Requesting unused permissions** → App Review rejection.  
6. **Videos that only show Graph API Explorer** → often rejected. Show **your product UI**.  
7. **Sharing access tokens in chat/email** → rotate them immediately.

---

# Quick order of work (recommended)

1. API Setup + test message works  
2. Graph API Explorer permission calls  
3. Privacy policy + app basic settings  
4. Business verification  
5. Record permission videos from your product  
6. Submit App Review (Advanced Access)  
7. System User permanent token  
8. Webhooks + Live mode  
9. Final end-to-end test in Sateri Connect  

---

# Official Meta links

- [WhatsApp Get Started](https://developers.facebook.com/docs/whatsapp/cloud-api/get-started)  
- [App Review (WhatsApp)](https://developers.facebook.com/docs/whatsapp/embedded-signup/app-review/)  
- [Graph API Explorer](https://developers.facebook.com/tools/explorer)  
- [Business Settings](https://business.facebook.com/settings)  

---

# Related project docs

- [META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md) — exact Explorer calls  
- [META_PROVIDER_SETUP_GUIDE.md](META_PROVIDER_SETUP_GUIDE.md) — app provider setup  
- [META_CONFIGURATION.md](META_CONFIGURATION.md)  
- [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)  
- [GUIDE_PRODUCTION.md](GUIDE_PRODUCTION.md)  
