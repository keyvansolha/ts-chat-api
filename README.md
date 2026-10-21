# TehranSpeaker Chatbot API (`ts-chat-api`)

Server-to-server routes the chatbot calls, under `/wp-json/ts-chat/v1/`. Every
route requires one shared secret and stays closed (503 `unavailable`) until it
is configured.

| Route | Task | Purpose |
| --- | --- | --- |
| `POST /stock-alerts` | WP-110 | subscribe a mobile to the «موجود شد خبرم کن» SMS of an out-of-stock product |
| `GET /stock-alerts/status?product_id=&mobile=[&variation_id=]` | WP-110 | `{subscribed: bool}` |
| `POST /product-faq` | WP-116 | suggest a product FAQ item; a human approves it in wp-admin |
| `GET /campaigns` | WP-117 | active, public campaigns marked by marketing on WooCommerce coupons |

## Secret

```php
// wp-config.php, beside the other secrets
define( 'TS_CHAT_API_SECRET', '<random, at least 48 characters>' );
```

* Sent only as `Authorization: Bearer <secret>`; a query-string token is never
  read. Compared with `hash_equals` before any query, log or rate-limit work.
* Unset or shorter than 48 characters → every route answers `503 {"code":"unavailable"}`.
* The same value goes to the chatbot server (privately, never in a ticket).
* `.htaccess` already passes `HTTP_AUTHORIZATION` to PHP.

## `POST /stock-alerts`

Body `{product_id, variation_id?, mobile, source?, wp_user_id?}`. The mobile
accepts Persian/Arabic digits and `+98` / `0098` / `98` prefixes and is stored
as `09xxxxxxxxx`.

| case | status | body |
| --- | --- | --- |
| new subscription | 201 | `{"status":"subscribed","product_id":…}` |
| already subscribed | 200 | `{"status":"already_subscribed","product_id":…}` |
| in stock and purchasable | 409 | `{"code":"in_stock"}` |
| product/variation missing, unpublished, or variation of another product | 404 | `{"code":"not_found"}` |
| invalid mobile | 422 | `{"code":"invalid_mobile"}` |
| PWSMS back-in-stock off for the product | 409 | `{"code":"alerts_unavailable"}` |
| > 5 per mobile per hour, > 60 per minute overall | 429 | `{"code":"rate_limited"}` |
| bad/missing secret | 401 | `{"code":"unauthorized"}` |

The subscriber is written with the same `Contacts::insert_contact` /
`update_contact` calls the public `pwsms/notification/subscribe` route uses, in
the PWSMS `_in` («موجود شد») group of the product or variation — no second
subscriber table, and no SMS at subscribe time. The chatbot source is kept as
post meta `_ts_chat_stock_alert_mobiles` (last 10 digits) on that product, and
the option `ts_chat_api_stock_alert_signups` counts chatbot signups.

**Restock SMS.** When the product comes back in stock this plugin runs just
before PWSMS, sends the PWSMS back-in-stock text to the chatbot subscribers with
`?utm_source=chatbot&utm_medium=sms&utm_campaign=stock_alert` appended to the
product link, and archives it as the PWSMS in-stock event. PWSMS then skips
those numbers (its own 24-hour duplicate check) and sends its usual text to
everyone else. The UTM is added only when the PWSMS template contains
`{product_url}`.

## `POST /product-faq`

Body `{product_id, question, answer, source_ref}`. HTML is stripped from both
texts; question ≤ 300 and answer ≤ 2000 characters.

| case | status | body |
| --- | --- | --- |
| new | 201 | `{"id":123,"status":"pending"}` |
| same product + normalised question (also against the product's own FAQ field) | 200 | `{"status":"exists","id":123}` |
| unknown / unpublished / variation id | 404 | `{"code":"not_found"}` |
| empty or too long | 422 | `{"code":"invalid"}` |
| bad secret | 401 | `{"code":"unauthorized"}` |

Each suggestion is a `ts_product_faq` post (parent = product, status
`pending`) under **محصولات ← سؤالات پیشنهادی چت‌بات**. The list filters by
status (WordPress' own views) and by product id; «تأیید و انتشار» publishes,
the editor changes the text or the product, trash rejects. Only published items
are appended to the product page FAQ tab through the theme filter
`ts_post_faqs`, so they share the theme's renderer and its single `FAQPage`
JSON-LD (the theme owns FAQ schema; Rank Math does not emit a second one).

## `GET /campaigns`

Source: WooCommerce coupons. On the coupon edit screen marketing ticks
**کمپین عمومی** and fills title, optional discount text, optional slug and
optional start date; **نمایش کد** decides whether `publicCode` is returned.

A coupon is returned only when it is published, marked public, has an expiry
date, has started (start date at 00:00 site time, otherwise its creation time),
has not expired, is not restricted to customer e-mails and has uses left.
Products are reported as parent ids, categories as term ids, times as ISO 8601
with the site timezone. Usage counts, e-mail restrictions and customer data are
never returned. Responses carry an `ETag` (and answer `If-None-Match` with 304)
and `Cache-Control: private, max-age=60`.

## Tests

```sh
php tests/run.php    # stub WordPress + stub PWSMS, real plugin code
```

## Deploy

1. Add `TS_CHAT_API_SECRET` to `wp-config.php`.
2. Upload this folder to `wp-content/plugins/ts-chat-api/` and activate it.
3. Give the same secret to the chatbot server.
4. Probe: `curl -s -o /dev/null -w '%{http_code}' -X POST https://www.tehranspeaker.com/wp-json/ts-chat/v1/stock-alerts`
   → `401` (configured) — `503` means the secret is not set, `404` that the plugin is not active.
