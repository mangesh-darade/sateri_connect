# ElintOm — WhatsApp Meta Templates

**Language:** English (US) = `en_US`  
**Category:** Utility (unless noted)  
**Tenant:** `demoelintommetaapi.elintpos.in`  
**Platform:** Sateri Connect + ElintOm POS / Webshop

Meta rules applied in all bodies below:
- Body must **not** start or end with a variable
- Enough static words for Meta approval

Related: [ELINTOM_POS_INTEGRATION.md](ELINTOM_POS_INTEGRATION.md) — Send API + Live Chat inbox

---

## Priority — create these first

| # | Template name | Use |
|---|---------------|-----|
| 1 | `invoice_without_award_points` | **Required** — POS / Sales Invoice / Challan WhatsApp button |
| 2 | `payment_reminder` | Payment reminder (Customers Report) |
| 3 | `custom_text_message` | Service / raw WhatsApp messages |
| 4 | Webshop templates | Only if webshop module is used (see section 4–14 below) |

### After Meta approves

1. Open **ElintOm → System Settings → Meta WhatsApp**
2. **Template name** = exact name from this doc (lowercase, underscores)
3. **Template language** = `en_US`
4. Save, then test from POS invoice WhatsApp button
5. For Live Chat inbox: send via Sateri Connect API (see API examples below)

---

## API send format (all templates)

All ElintOm templates use **text headers** (no header variables).  
Send via Sateri Connect:

```
POST https://demoelintommetaapi.elintpos.in/api/messages/template
Authorization: Bearer <JWT>
Content-Type: application/json
```

**Common fields**

```json
{
  "to": "917744010738",
  "name": "Customer Name",
  "template_name": "TEMPLATE_NAME_HERE",
  "language": "en_US",
  "components": [
    {
      "type": "body",
      "parameters": [
        { "type": "text", "text": "value for {{1}}" },
        { "type": "text", "text": "value for {{2}}" }
      ]
    }
  ]
}
```

- Phone: country code, no `+` (e.g. `917744010738`)
- `template_name`: exact Meta-approved name
- `language`: always `en_US` for these templates
- Templates with **0 vars**: omit `components` or send `"components": []`

---

## 1) `invoice_without_award_points`

| | |
|---|---|
| **Use** | POS / Sales Invoice / Challan WhatsApp button |
| **Header** | Invoice |
| **Footer** | Thank you |
| **Vars** | 5 |

**Body (Meta submission)**

```
Hello {{1}}, thank you for shopping at {{2}}. Your invoice total is {{3}}. You can download or view your invoice using this link: {{4}}. Regards from {{5}}. Thank you for your business.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | Customer name |
| {{2}} | Demo company |
| {{3}} | Rs 300.00 |
| {{4}} | https://example.com/invoice |
| {{5}} | Demo company |

**API example**

```json
{
  "to": "917744010738",
  "name": "Mangesh",
  "template_name": "invoice_without_award_points",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Mangesh" },
      { "type": "text", "text": "Demo company" },
      { "type": "text", "text": "Rs 300.00" },
      { "type": "text", "text": "https://example.com/invoice/123" },
      { "type": "text", "text": "Demo company" }
    ]
  }]
}
```

---

## 2) `payment_reminder`

| | |
|---|---|
| **Use** | Payment reminder |
| **Header** | Payment Reminder |
| **Footer** | Thank you |
| **Vars** | 5 |

**Body**

```
Dear {{1}}, your total bill amount is {{2}}. You have paid {{3}} and the balance due is {{4}}. Please clear the pending amount at the earliest. Regards from {{5}}. Thank you.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | Customer name |
| {{2}} | Rs 1000.00 |
| {{3}} | Rs 400.00 |
| {{4}} | Rs 600.00 |
| {{5}} | Demo company |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "payment_reminder",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Mangesh" },
      { "type": "text", "text": "Rs 1000.00" },
      { "type": "text", "text": "Rs 400.00" },
      { "type": "text", "text": "Rs 600.00" },
      { "type": "text", "text": "Demo company" }
    ]
  }]
}
```

---

## 3) `custom_text_message`

| | |
|---|---|
| **Use** | Service / raw WhatsApp messages |
| **Header** | Message |
| **Footer** | Thank you |
| **Vars** | 1 |

**Body**

```
Hello, here is your update from our store. {{1}} Thank you for choosing us.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | Your service request has been updated. |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "custom_text_message",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Your service request has been updated." }
    ]
  }]
}
```

---

## 4) `elintom_webshop_order_received_msg_option`

| | |
|---|---|
| **Use** | Webshop order received |
| **Header** | Order Received |
| **Footer** | Thank you |
| **Vars** | 3 |

**Body**

```
Hi {{1}}, we have received your order number {{2}} placed on {{3}}. We will update you once it is ready. Thank you for ordering with us.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | Customer name |
| {{2}} | INV-1001 |
| {{3}} | 03-08-2026 21:00 |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "elintom_webshop_order_received_msg_option",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Mangesh" },
      { "type": "text", "text": "INV-1001" },
      { "type": "text", "text": "03-08-2026 21:00" }
    ]
  }]
}
```

---

## 5) `order_summary_yess`

| | |
|---|---|
| **Use** | Webshop order accepted (YES) |
| **Header** | Order Summary |
| **Footer** | Thank you |
| **Vars** | 6 |

**Body**

```
Your order items are {{1}}. Total amount is {{2}}. Payment status is {{3}}. Delivery address is {{4}}. View invoice at {{5}}. Track your order at {{6}}. Thank you for your order.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | Pizza(2), Coke |
| {{2}} | Rs 500.00 |
| {{3}} | Paid |
| {{4}} | 12 MG Road Pune |
| {{5}} | https://example.com/invoice |
| {{6}} | https://example.com/track |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_summary_yess",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Pizza(2), Coke" },
      { "type": "text", "text": "Rs 500.00" },
      { "type": "text", "text": "Paid" },
      { "type": "text", "text": "12 MG Road Pune" },
      { "type": "text", "text": "https://example.com/invoice" },
      { "type": "text", "text": "https://example.com/track" }
    ]
  }]
}
```

---

## 6) `order_summary_no`

| | |
|---|---|
| **Use** | Webshop order declined (NO) |
| **Header** | Order Update |
| **Footer** | Thank you |
| **Vars** | 2 |

**Body**

```
Your order could not be confirmed at this time. You can view the invoice at {{1}} and track details at {{2}}. Please contact the store for help. Thank you.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | https://example.com/invoice |
| {{2}} | https://example.com/track |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_summary_no",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "https://example.com/invoice" },
      { "type": "text", "text": "https://example.com/track" }
    ]
  }]
}
```

---

## 7) `pickup_ready_msg`

| | |
|---|---|
| **Use** | Pickup ready |
| **Header** | Pickup Ready |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Your order is ready for pickup. Please visit the store and collect your order. Thank you for ordering with us.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "pickup_ready_msg",
  "language": "en_US"
}
```

---

## 8) `delivery_ready_msg`

| | |
|---|---|
| **Use** | Out for delivery |
| **Header** | Out for Delivery |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Your order is out for delivery and will reach you shortly. Please keep your phone available. Thank you for ordering with us.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "delivery_ready_msg",
  "language": "en_US"
}
```

---

## 9) `order_status`

| | |
|---|---|
| **Use** | OTP / login |
| **Header** | OTP Verification |
| **Category** | **Authentication** (preferred) or Utility |
| **Footer** | Thank you |
| **Vars** | 2 |

**Body**

```
Your one time password is {{1}}. Please use it within {{2}} Do not share this OTP with anyone. Thank you.
```

**Sample values**

| Var | Sample |
|-----|--------|
| {{1}} | this otp: 123456 |
| {{2}} | 5 minutes. |

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_status",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "this otp: 123456" },
      { "type": "text", "text": "5 minutes." }
    ]
  }]
}
```

---

## 10) `elintom_webshop_order_received_msg`

| | |
|---|---|
| **Use** | Order received (status helper) |
| **Header** | Order Received |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Hi, we have received your order and it is being processed. Thank you for ordering with us.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "elintom_webshop_order_received_msg",
  "language": "en_US"
}
```

---

## 11) `order_in_progress_template`

| | |
|---|---|
| **Use** | Order in progress |
| **Header** | Order In Progress |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Your order is now in progress and being prepared. Thank you for your patience.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_in_progress_template",
  "language": "en_US"
}
```

---

## 12) `order_ready_template`

| | |
|---|---|
| **Use** | Order ready |
| **Header** | Order Ready |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Your order is ready. Please collect it or wait for delivery as selected. Thank you.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_ready_template",
  "language": "en_US"
}
```

---

## 13) `order_dispatched_template`

| | |
|---|---|
| **Use** | Order dispatched |
| **Header** | Order Dispatched |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
Your order has been dispatched and is on the way. Thank you for ordering with us.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_dispatched_template",
  "language": "en_US"
}
```

---

## 14) `order_generic_template`

| | |
|---|---|
| **Use** | Generic order update |
| **Header** | Order Update |
| **Footer** | Thank you |
| **Vars** | 0 |

**Body**

```
There is an update on your order. Please contact the store for more details. Thank you.
```

**API example**

```json
{
  "to": "917744010738",
  "template_name": "order_generic_template",
  "language": "en_US"
}
```

---

## Quick reference table

| # | Template name | Vars | Header | Category |
|---|---------------|------|--------|----------|
| 1 | `invoice_without_award_points` | 5 | Invoice | Utility |
| 2 | `payment_reminder` | 5 | Payment Reminder | Utility |
| 3 | `custom_text_message` | 1 | Message | Utility |
| 4 | `elintom_webshop_order_received_msg_option` | 3 | Order Received | Utility |
| 5 | `order_summary_yess` | 6 | Order Summary | Utility |
| 6 | `order_summary_no` | 2 | Order Update | Utility |
| 7 | `pickup_ready_msg` | 0 | Pickup Ready | Utility |
| 8 | `delivery_ready_msg` | 0 | Out for Delivery | Utility |
| 9 | `order_status` | 2 | OTP Verification | Authentication |
| 10 | `elintom_webshop_order_received_msg` | 0 | Order Received | Utility |
| 11 | `order_in_progress_template` | 0 | Order In Progress | Utility |
| 12 | `order_ready_template` | 0 | Order Ready | Utility |
| 13 | `order_dispatched_template` | 0 | Order Dispatched | Utility |
| 14 | `order_generic_template` | 0 | Order Update | Utility |

---

## Meta Business Manager — copy-paste block

Use this section when creating templates in Meta (Notepad-friendly).

```
================================================================================
ELINTOM - WHATSAPP META TEMPLATES
Language: English (US) = en_US
Category: Utility (unless noted)
================================================================================

1) invoice_without_award_points | Header: Invoice | Footer: Thank you | Vars: 5
Hello {{1}}, thank you for shopping at {{2}}. Your invoice total is {{3}}. You can download or view your invoice using this link: {{4}}. Regards from {{5}}. Thank you for your business.

2) payment_reminder | Header: Payment Reminder | Footer: Thank you | Vars: 5
Dear {{1}}, your total bill amount is {{2}}. You have paid {{3}} and the balance due is {{4}}. Please clear the pending amount at the earliest. Regards from {{5}}. Thank you.

3) custom_text_message | Header: Message | Footer: Thank you | Vars: 1
Hello, here is your update from our store. {{1}} Thank you for choosing us.

4) elintom_webshop_order_received_msg_option | Header: Order Received | Vars: 3
Hi {{1}}, we have received your order number {{2}} placed on {{3}}. We will update you once it is ready. Thank you for ordering with us.

5) order_summary_yess | Header: Order Summary | Vars: 6
Your order items are {{1}}. Total amount is {{2}}. Payment status is {{3}}. Delivery address is {{4}}. View invoice at {{5}}. Track your order at {{6}}. Thank you for your order.

6) order_summary_no | Header: Order Update | Vars: 2
Your order could not be confirmed at this time. You can view the invoice at {{1}} and track details at {{2}}. Please contact the store for help. Thank you.

7) pickup_ready_msg | Header: Pickup Ready | Vars: 0
Your order is ready for pickup. Please visit the store and collect your order. Thank you for ordering with us.

8) delivery_ready_msg | Header: Out for Delivery | Vars: 0
Your order is out for delivery and will reach you shortly. Please keep your phone available. Thank you for ordering with us.

9) order_status | Header: OTP Verification | Category: Authentication | Vars: 2
Your one time password is {{1}}. Please use it within {{2}} Do not share this OTP with anyone. Thank you.

10) elintom_webshop_order_received_msg | Header: Order Received | Vars: 0
Hi, we have received your order and it is being processed. Thank you for ordering with us.

11) order_in_progress_template | Header: Order In Progress | Vars: 0
Your order is now in progress and being prepared. Thank you for your patience.

12) order_ready_template | Header: Order Ready | Vars: 0
Your order is ready. Please collect it or wait for delivery as selected. Thank you.

13) order_dispatched_template | Header: Order Dispatched | Vars: 0
Your order has been dispatched and is on the way. Thank you for ordering with us.

14) order_generic_template | Header: Order Update | Vars: 0
There is an update on your order. Please contact the store for more details. Thank you.

PRIORITY: invoice_without_award_points → payment_reminder → custom_text_message → webshop (if used)
================================================================================
```

---

## Sync templates into Sateri Connect

After Meta approves templates:

1. **Settings → Meta** — confirm WABA + Phone Number ID
2. **Templates → Sync** (or `POST /api/templates/sync`) — pulls approved templates into local DB
3. Use exact `template_name` from this doc in API calls

See [ELINTOM_POS_INTEGRATION.md](ELINTOM_POS_INTEGRATION.md) for full Send API + Live Chat inbox setup.
