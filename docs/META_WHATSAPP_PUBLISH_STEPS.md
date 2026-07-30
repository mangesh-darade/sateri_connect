# Meta WhatsApp Publish Steps (New Guide)

Fresh standalone checklist. Use this file only for publish / App Review testing progress.

**App:** ElintOM Connect Api  
**Date started:** 2026-07-30  
**Status page:** Connect with customers through WhatsApp → Testing in progress

---

## Your IDs (reference)

| Item | Value |
|------|--------|
| Phone Number ID | `1247741705089229` |
| WABA ID | `2201519787058843` |
| Test recipient | `917744010738` |
| Graph API Explorer | https://developers.facebook.com/tools/explorer |

---

## Current progress

| Permission | Dashboard status | Notes |
|------------|------------------|-------|
| `whatsapp_business_messaging` | **Completed** | Message send accepted |
| `whatsapp_business_management` | **Completed** | WABA / templates read OK |
| `business_management` | **0 of 1 required** | Do Step 3 again |
| `public_profile` | **0 API test call(s)** | Do Step 4 again |
| `whatsapp_business_manage_events` | **0 API test call(s)** | Optional (ads / CAPI only) |

---

## Before every Explorer call

1. Open https://developers.facebook.com/tools/explorer  
2. Meta App = **ElintOM Connect Api**  
3. User Token  
4. Add permissions you need, then click **Generate Access Token**  
5. Required permissions for remaining work:
   - `business_management`
   - `whatsapp_business_management`
   - `whatsapp_business_messaging`
   - `public_profile` (usually automatic)
   - `whatsapp_business_manage_events` (only if you need events)

---

## Step 1 — Messaging (DONE)

Method: **POST**  
Path:

```
1247741705089229/messages
```

JSON body:

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

Success = `"message_status": "accepted"`

- [x] Completed

---

## Step 2 — Management (DONE)

Method: **GET**  
Path:

```
2201519787058843?fields=id,name,message_templates.limit(1)
```

Success = WABA name + templates JSON

- [x] Completed

---

## Step 3 — business_management (DO THIS NOW)

Method: **GET** (not POST)  
Path:

```
me/businesses
```

JSON body: empty  

Success example:

```json
{
  "data": [
    { "id": "...", "name": "Swasthe Middle East LLC" }
  ]
}
```

Common mistakes:
- Method left as POST → error `parameter name is required`
- Token without `business_management` → Missing Permission

After success: wait 5–15 minutes → refresh Meta use-case page.

- [ ] Completed on dashboard

---

## Step 4 — public_profile (DO THIS NOW)

Method: **GET**  
Path:

```
me?fields=id,name
```

Success example:

```json
{
  "id": "1221...",
  "name": "Mangesh Darade"
}
```

- [ ] Completed on dashboard

---

## Step 5 — whatsapp_business_manage_events (OPTIONAL)

Only needed for Marketing Messages + Conversions API (ads events).  
Skip if you only need chat / templates / campaigns.

If required, try:

Method: **GET**  
Path:

```
2201519787058843/dataset
```

Or:

Method: **POST**  
Path:

```
2201519787058843/dataset
```

If Meta auto-approves this later with messaging Advanced Access, you can leave it for later.

- [ ] Done / Skipped

---

## Step 6 — After all required API tests are green

1. App settings → Basic  
   - Privacy Policy URL  
   - App icon  
   - Contact email  
2. Business verification (Business Settings)  
3. Record App Review videos from your product UI (not only Explorer):
   - Send message video → `whatsapp_business_messaging`  
   - Template / WABA manage video → `whatsapp_business_management`  
4. Submit **Advanced Access** / App Review for each permission  
5. Create **System User** permanent token  
6. Save token + Phone Number ID + WABA ID in Sateri Connect Settings (provider = Meta)  
7. Configure production HTTPS webhook  
8. Switch app to **Live** when checklist is complete  

---

## Today’s action list

1. [ ] Explorer → Generate token with `business_management`  
2. [ ] `GET me/businesses`  
3. [ ] `GET me?fields=id,name`  
4. [ ] Wait 5–15 min → refresh use-case page  
5. [ ] Confirm `business_management` and `public_profile` turn Completed  
6. [ ] Decide skip or do `whatsapp_business_manage_events`  
7. [ ] Continue Business verification + Privacy policy + videos  

---

## Official links

- Graph API Explorer: https://developers.facebook.com/tools/explorer  
- App Dashboard: https://developers.facebook.com/apps  
- Business Settings: https://business.facebook.com/settings  
- WhatsApp App Review docs: https://developers.facebook.com/docs/whatsapp/embedded-signup/app-review/
