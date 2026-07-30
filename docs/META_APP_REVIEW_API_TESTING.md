# Meta App Review — API Test Calls (Step by Step)

**Who this is for:** Completing Meta Developer “Permissions and features” API test requirements for WhatsApp  
**Time:** 20–40 minutes  
**Tool:** [Graph API Explorer](https://developers.facebook.com/tools/explorer)

> Full publish / go-live guide (English): **[META_PUBLISH_GO_LIVE.md](META_PUBLISH_GO_LIVE.md)**

> Messages / API calls can succeed even when the dashboard still shows **API Calls: 0**. Meta’s counter often updates after **15–60 minutes** (sometimes up to 24 hours). Trust a successful JSON response, not the counter alone.

---

## What you need ready

Copy these from **App Dashboard → WhatsApp → API Setup**:

| Item | Example (yours will differ) |
|------|-----------------------------|
| Meta App | ElintOM Connect Api |
| Phone Number ID | `1247741705089229` |
| WABA ID | `2201519787058843` |
| Recipient WhatsApp (with country code) | `917744010738` |
| Access Token | Generate in Graph API Explorer |

Also add the recipient as a **test number** on WhatsApp API Setup (OTP verify) before sending.

---

## Permissions to satisfy

| Permission | Required test call |
|------------|--------------------|
| `public_profile` | `GET me?fields=id,name` |
| `whatsapp_business_management` | `GET {WABA_ID}?fields=id,name` |
| `whatsapp_business_messaging` | `POST {PHONE_NUMBER_ID}/messages` |
| `business_management` | `GET me/businesses` |
| `whatsapp_business_manage_events` | Optional / later |

---

## Step 0 — Open Graph API Explorer

1. Open: https://developers.facebook.com/tools/explorer  
2. **Meta App** = your WhatsApp app (e.g. ElintOM Connect Api)  
3. **User or Page** = User Token  
4. **Permissions** → Add:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
   - `business_management`
   - `whatsapp_business_manage_events` (optional)
5. Click **Generate Access Token** and approve  
6. Whenever you add/remove a permission, click **Generate Access Token** again

---

## Step 1 — `public_profile`

1. Method = **GET**  
2. Path:
```
me?fields=id,name
```
3. Click **Submit**

**Success looks like:**
```json
{
  "id": "1221...",
  "name": "Your Name"
}
```

- [ ] Done

---

## Step 2 — `whatsapp_business_management`

1. Method = **GET**  
2. Path (replace with your WABA ID):
```
2201519787058843?fields=id,name,message_templates.limit(1)
```
3. Click **Submit**

**Success looks like:**
```json
{
  "id": "2201519787058843",
  "name": "Test WhatsApp Business Account",
  "message_templates": { "data": [ ... ] }
}
```

- [ ] Done

---

## Step 3 — `whatsapp_business_messaging`

> Common mistake: using **WABA ID** or leaving method as GET.  
> Use **Phone Number ID** + **POST** + message JSON body.

1. Method = **POST** (change from GET)  
2. Path (replace with your Phone Number ID — no `?fields=`):
```
1247741705089229/messages
```
3. In the JSON body panel, paste:
```json
{
  "messaging_product": "whatsapp",
  "to": "917744010738",
  "type": "template",
  "template": {
    "name": "hello_world",
    "language": {
      "code": "en_US"
    }
  }
}
```
4. Click **Submit**  
5. Check WhatsApp on the recipient phone

**Success looks like:**
```json
{
  "messaging_product": "whatsapp",
  "contacts": [{ "input": "917744010738", "wa_id": "917744010738" }],
  "messages": [{ "id": "wamid....", "message_status": "accepted" }]
}
```

### If you get errors

| Error | Cause | Fix |
|-------|--------|-----|
| `(#200) Permissions error` on WABA path with POST | Wrong path / wrong method | Use `PHONE_NUMBER_ID/messages` + POST |
| Recipient not in allow list | Test number not added | API Setup → add phone → OTP |
| Template not found | `hello_world` missing | Use an approved template name (e.g. `testing_mangesh`) |

- [ ] Done (phone received message)

---

## Step 4 — `business_management`

> Common mistake: leaving method as **POST** with a message JSON body.  
> This call must be **GET** with **empty body**.

1. Method = **GET** (change from POST)  
2. Path:
```
me/businesses
```
3. Clear / empty the JSON body  
4. Click **Submit**

**Success looks like:**
```json
{
  "data": [
    { "id": "...", "name": "Swasthe Middle East LLC" },
    { "id": "...", "name": "Sateri Digital Private Limited" }
  ]
}
```

### If you get errors

| Error | Cause | Fix |
|-------|--------|-----|
| `(#100) Missing Permission` | Token missing `business_management` | Add permission → Generate Access Token again |
| `(#100) The parameter name is required` | Method is still POST | Switch to **GET**, empty body |

- [ ] Done

---

## Step 5 — Check Meta dashboard

1. App Dashboard → **Use cases** → **Connect on WhatsApp** → **Permissions and features**  
2. Hard refresh: **Ctrl+F5**  
3. Wait 15–60 minutes (sometimes longer)  
4. Confirm API call counts move from `0` toward required values  

Also check any **publishing / testing requirements** page (not only the permissions table).

- [ ] Dashboard updated (or waiting for sync)

---

## Quick reference (copy-paste)

Replace IDs if yours differ.

| # | Method | Path |
|---|--------|------|
| 1 | GET | `me?fields=id,name` |
| 2 | GET | `2201519787058843?fields=id,name,message_templates.limit(1)` |
| 3 | POST | `1247741705089229/messages` + hello_world JSON |
| 4 | GET | `me/businesses` |

### Messaging JSON (Step 3)

```json
{
  "messaging_product": "whatsapp",
  "to": "917744010738",
  "type": "template",
  "template": {
    "name": "hello_world",
    "language": { "code": "en_US" }
  }
}
```

---

## Security note

Temporary tokens shown in chat / screenshots should be treated as exposed.

1. After testing, generate a new token  
2. Prefer a **System User** token for the app (Business Settings → System users)  
3. Never commit tokens to git or share them publicly

---

## Related docs

- [META_CONFIGURATION.md](META_CONFIGURATION.md)  
- [META_PROVIDER_SETUP_GUIDE.md](META_PROVIDER_SETUP_GUIDE.md)  
- [META_FLOW.md](META_FLOW.md)  
- [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)  
