# TehranSpeaker Chatbot API (`ts-chat-api`)

Routes the chatbot calls, under `/wp-json/ts-chat/v1/`. The server-to-server
routes require one shared secret and stay closed (503 `unavailable`) until it is
configured. `GET /products/{id}/companions` is public: the chat widget reads it
from the shopper's browser and it returns catalogue data only.

| Route | Task | Purpose |
| --- | --- | --- |
| `POST /stock-alerts` | WP-110 | subscribe a mobile to the «موجود شد خبرم کن» SMS of an out-of-stock product |
| `GET /stock-alerts/status?product_id=&mobile=[&variation_id=]` | WP-110 | `{subscribed: bool}` |
| `POST /product-faq` | WP-116 | suggest a product FAQ item; a human approves it in wp-admin |
| `GET /campaigns` | WP-117 | active, public campaigns marked by marketing on WooCommerce coupons |
| `GET /products/{id}/companions` (public) | WP-126 | the product's «اقلام همراه» |

The plugin also lets the chat origin read Store API products (WP-127, see below).

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
never returned. Responses carry an `ETag` and the same value in `X-TS-Content-Tag`
(the site's `.htaccess` strips `ETag`), answer `If-None-Match` with 304,
and `Cache-Control: private, max-age=60`.

## `GET /products/{id}/companions` (public)

Source: the **«اقلام همراه»** tab on the product edit screen (theme `amazing`,
`lib/Product/functions/product-tabs.php`), stored as post meta
`wbsIncludedProducts` on the product: an ordered list of
`{product: {id, attrs?}, title?}`. The same meta draws the «اقلام همراه» box on
the product page. To pair PartyBox 330 with its bag, open product 281821 →
«اقلام همراه» → «افزودن» → pick 291846 → «ذخیره تغییرات».

```json
{ "items": [
  { "productId": 291846, "name": "کیف اسپیکر پارتی باکس جی بی ال مدل 330",
    "image": "https://www.tehranspeaker.com/wp-content/uploads/…-200x200.jpg",
    "price": 4300000, "inStock": true, "isVariable": true }
] }
```

* At most 3 items: in-stock ones first, each group in the shop's order.
  Unpublished, deleted, duplicate and self references are skipped.
* `price`: whole Toman (the store currency is IRT with 0 decimals); the lowest
  variation price for a variable item; `null` without a price.
* `image`: the `woocommerce_thumbnail` URL forced to https, or `null`.
* No accompanying items → `200 {"items": []}`. Unknown, unpublished or variation
  id → `404 {"code":"not_found"}`. `Cache-Control: public, max-age=60`.
* CORS comes from WordPress core (`rest_send_cors_headers`), like every non-Store
  API route.

## Store API CORS for the chat origin (WP-127)

The Store API sends `Access-Control-Allow-Origin` only to origins accepted by
`is_allowed_http_origin()`. The plugin adds the chat origin
(`ts_chat_iframe_origin()` from the theme, default
`https://chat.tehranspeaker.com`, filter `ts_chat_api_chat_origin`) **only**
while the REST request being served is a `GET`/`HEAD` to

* `/wc/store/v1/products/{id}`
* `/wc/store/v1/products` (e.g. `?parent={id}&type=variation`)

Cart, checkout, order and every write stay closed to it; nothing is wildcarded,
and outside a REST request (admin-ajax, wp-login) the origin is never allowed.

```sh
curl -sI -H 'Origin: https://chat.tehranspeaker.com' https://www.tehranspeaker.com/wp-json/wc/store/v1/products/281821 | grep -i allow-origin
# access-control-allow-origin: https://chat.tehranspeaker.com
curl -sI -H 'Origin: https://chat.tehranspeaker.com' https://www.tehranspeaker.com/wp-json/wc/store/v1/cart | grep -ic allow-origin
# 0
```

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
5. Probe the public parts (no secret needed):
   `curl -s https://www.tehranspeaker.com/wp-json/ts-chat/v1/products/281821/companions` → `{"items":[…]}`,
   and the two CORS `curl -sI` lines above.
