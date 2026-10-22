# Kashier: مرجع الربط

> **الرموز:** 📘 من التوثيق الرسمي · 🧪 اتأكد بالتجربة (رقم التجربة في `docs/experiments.md`) · ❓ لسه محتاج تجربة
> **آخر مراجعة للتوثيق:** 2026-10-22 · **المصدر:** https://developers.kashier.io (ومعاه `llms.txt` فيه فهرس كل الصفحات)

## 1. البيئات والمفاتيح
| | Test | Live |
|---|---|---|
| API | `https://test-api.kashier.io` | `https://api.kashier.io` |
| FEP (refund/void) | `https://test-fep.kashier.io` | `https://fep.kashier.io` |

| المفتاح | بيستخدم في إيه | الـ header |
|---|---|---|
| Merchant ID (`MID-…`) | جوه الـ body | — |
| **Secret Key** | كل الـ API calls (sessions، orders، refund) | `Authorization: <secret>` |
| **Payment API Key** | 📘 توقيع الـ webhook، وتوقيع صفحة الرجوع، والـ order hash | `api-key` (في إنشاء الـ session) |

📘 مفتاح test بيشتغل على hosts الـ test بس، ومفتاح live على live بس. لو خلطت بينهم هيطلعلك `INVALID_HASH` حتى لو الـ string صح.
📘 مثال C# في التوثيق سمّى المتغير `secretKey`، بس المقصود Payment API Key.

## 2. Payment Sessions (اللي الحزمة بتستخدمه)
| العملية | الطلب |
|---|---|
| إنشاء | `POST /v3/payment/sessions` |
| حالة الـ session | `GET /v3/payment/sessions/{id}/payment` 📘 (ده التأكيد من السيرفر) |
| إلغاء (abandon) | `PATCH /v3/payment/sessions/{id}` بـ body `{}` 📘 ← `ABANDONED`. مبيحركش فلوس، والـ session اللي في حالة نهائية بتفضل زي ما هي |
| البحث في الـ orders | `GET /v3/payment/orders?…` (الحزمة بتستخدمه في الـ reconcile) |

حقول الإنشاء المهمة: `amount` (string عشري)، و`currency`، و`order` (merchantOrderId)، و`merchantRedirect`، و`serverWebhook`، و`expireAt` (ISO UTC)، و`maxFailureAttempts`، و`allowedMethods`، و`defaultMethod`، و`failureRedirect`، و`redirectMethod`، و`display`، و`enable3DS`، و`metaData`، و`customer{email,reference}`، و`type: one-time`، و`interactionSource: ECOMMERCE`.
الرد: `_id` (sessionId) + `sessionUrl` + `status` + `expireAt`.

📘 **test mode بيتجاهل `allowedMethods`** وبيعرض كارت ومحفظة دايماً.
❓ هل الـ session بعد ما اتعملها abandon لسه ينفع تتدفع؟ (E-K4)
❓ لو المحاولة فشلت وجرّبت تاني، دي نفس الـ session ولا جديدة؟ وبيطلع `transactionId` جديد؟ (E-K3)

## 3. صفحة الرجوع (merchantRedirect)
- 📘 بيرجع بـ query string فيها: `paymentStatus`، و`cardDataToken`، و`maskedCard`، و`merchantOrderId`، و`orderId`، و`cardBrand`، و`orderReference`، و`transactionId`، و`amount`، و`currency`، و`signature`، و`mode`
- 📘 **التوقيع**: HMAC-SHA256 hex بالـ **Payment API Key** على `key=value&…` **بالترتيب الثابت** اللي فوق (من غير `signature` و`mode`)، والقيمة اللي مش موجودة بتتكتب `null` كنص
- الحزمة: بتطبّق الترتيب الثابت، وبتقبل كمان "كل الـ params بالترتيب اللي جت بيه" كـ fallback. التوثيق بيقول "never from whatever is on the URL"، فالـ fallback ده تحت المراجعة (R18)
- 📘 صفحة الرجوع **مش** تأكيد للدفع. التأكيد من الـ webhook أو من `GET …/payment`

## 4. Webhooks
- 📘 الـ header: `x-kashier-signature`
- 📘 التوقيع: نرتّب `data.signatureKeys` أبجدياً، ونعمل `key=urlencode(value)` بالـ `&` (الـ URL-encode **للقيم بس**)، وبعدين HMAC-SHA256 hex بالـ Payment API Key. مثال: `amount=1&channel=online%20%7C%20e-commerce&currency=EGP…`
- 📘 الـ `signatureKeys` في المثال: `amount, channel, currency, kashierOrderId, merchantOrderId, method, orderReference, status, transactionId, transactionResponseCode`
- 📘 الـ events: `pay`، و`authorize`، و`capture`، و`refund`، و`partial_refund`، و`void`، و`reject`، و`reversal`. وإعادة الإرسال بتيجي كـ `event: "idempotency"`
- 📘 **"Branch on `data.status` (SUCCESS / FAILURE / PENDING), never on `event` alone"**. العملية الفاشلة بيجيلها webhook بنفس الـ event و`status=FAILURE`
- 📘 `data.hash` حقل داخلي، **متستخدمهوش** في التحقق
- 📘 **الردود**: `200` = استلمت، و`409` = اتعالج قبل كده (وKashier بيوقف الـ retry)، وأي حاجة تانية أو أكتر من **30 ثانية** = retry
- 📘 **الـ retries**: لحد 10 مرات (2 دقيقة، 10 دقايق، 30 دقيقة، ساعة، ساعتين، 4 ساعات، وبعدين كل 4 ساعات)
- 📘 Kashier بيشيل التكرار بـ `{transactionId}::{webhookUrl}::{status}`، ومع كده التكرار ممكن يحصل. لو حاولت تدفع تاني على order متدفع بيطلع `ORDER_PAID_BEFORE`
- 📘 `serverWebhook` ممكن يتحدد لكل session

**تطبيق الحزمة:** `eventId = kashier:{event}:{transactionId}:{status}`، والـ payment والـ refund **بيتأكدوا من الـ API** قبل ما يتطبقوا، والـ `reject` اللي مش موقّع بيتجاهل، و`merchantId` الموقّع لازم يطابق.

## 5. الحالات
| Kashier | الحزمة |
|---|---|
| CREATED, OPENED, PENDING, INITIATED, NEW | pending |
| PROCESSING, AUTHENTICATION_IN_PROGRESS, AUTHORIZED | processing |
| SUCCESS, CAPTURED, APPROVED, PAID | succeeded |

📘 النتيجة `UNKNOWN` بتتجمّع لـ order status `INITIATED` (مش FAILED). **اعمل reconcile قبل أي retry.**
📘 كود الخطأ `k_5` (رقم المحفظة مش متسجل): نهائي، ومش هيتحل لوحده.
📘 حقل الـ API مكتوب **`reconcilation`** (ناقص i)، والحزمة بتقراه صح ✓.

## 6. Refund / Void
📘 `PUT {FEP}/v3/orders/{orderId}` بـ header `Authorization: <secret>`، ومن غير Kashier-Hash. والـ API key محتاج صلاحية refund.
```json
{"apiOperation":"REFUND","reason":"any reason","transaction":{"amount":3}}
```
- `apiOperation`: `REFUND` أو `VOID`. لو مبعتش `amount` يبقى refund كامل. و`targetTransactionId` اختياري
- 📘 الـ refund مينفعش يكون أكبر من الأصل، وبيتخصم من الرصيد المتاح (الرصيد الـ pending مش محسوب)
- 📘 الحالات: `SUCCESS` / `FAILURE` / `PENDING` (لسه مستني رد الـ processor)
- ❓ بيجيلنا webhook بعد الـ refund؟ وشكله إيه؟ (E-K5)

## 7. الاختبار
| الكارت | الرقم | ملاحظة |
|---|---|---|
| MasterCard | `5123450000000008` | 3DS (مقترح للـ quick start) |
| MasterCard | `5111111111111118` / `5123456789012346` | |
| Visa | `4508750015741019` | 3DS |
| Visa | `4012000033330026` | |

📘 **النتيجة بتتحدد من تاريخ الانتهاء** (ومش بيتقري كتاريخ): `06/25` APPROVED، و`05/25` DECLINED، و`04/27` EXPIRED_CARD، و`08/28` TIMED_OUT، و`01/37` ACQUIRER_SYSTEM_ERROR، و`02/37` UNSPECIFIED_FAILURE، و`05/37` UNKNOWN.
📘 الـ CVV: `100` MATCH، و`101` NOT_PROCESSED، و`102` NO_MATCH.
📘 المحافظ: Vodafone Cash `01010101010`، وأي محفظة تانية `01001001001`.

## 8. إعداد لوحة Kashier (Checklist)
- [ ] Integrations: انسخ الـ Payment API Key والـ Secret Key (test وlive لوحدهم)
- [ ] Webhook: `https://<domain>/payment-gateway/webhooks/kashier` (أو `serverWebhook` لكل session)
- [ ] تأكد إن الـ API key ليه صلاحية Refund
- [ ] الوسائل المفعّلة في الحساب (card، wallet)

## 9. مشاكل معروفة ونصايح
- مينفعش تعيد استخدام نفس الـ `order` لـ session تانية (`ERR_ORD_02`)، والحزمة بتولّد `<ref>-A2-xxxx` لوحدها
- الـ webhook ممكن يوصل قبل صفحة الرجوع أو بعدها، والاتنين لازم يشتغلوا
- `failureRedirect=false`: العميل بيفضل على صفحة Kashier وبيحاول تاني لحد `maxFailureAttempts`
