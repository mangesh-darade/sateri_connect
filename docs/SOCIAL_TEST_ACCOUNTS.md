# Social scheduler test accounts

Public identifiers only. Secrets (App Secret, tokens, OAuth client secret) live in `.env` or Social Scheduler → Setup, never here.

| Platform | Account | Identifier |
|---|---|---|
| Facebook | Page "Mangesh insta page" (Artist) | Page ID `1321753344363646` (URL `profile.php?id=61595257930905` is the same Page) |
| Instagram | @sateri.mangesh (Business, linked to the Page) | Instagram ID `17841459555525887` |
| YouTube | @mangeshsateri "Mangesh sateri" | Channel ID `UCyKKASd5ZclrmrZn6CFiPZQ` |
| Meta app | shared by Facebook and Instagram | App ID `1389328076628792` |

## Connect status
- YouTube: Google OAuth client and refresh token verified against the live channel. Connect it from Accounts → Connect YouTube.
- Facebook / Instagram: needs the Meta App Secret (Setup) or a fresh access token (Accounts → Connect with an access token).
- Meta app must be in Development mode with this Facebook user as admin/tester until App Review.
