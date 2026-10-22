# Fawaterk (فواتيرك): مرجع الربط

> **الرموز:** 📘 من التوثيق الرسمي · 🧪 اتأكد بالتجربة (`docs/experiments.md`) · ❓ لسه محتاج تجربة
> **آخر مراجعة:** 2026-10-22 · **المصدر:** OpenAPI 3.1 الرسمي (`app.fawaterk.com/documentation/api/openapi.json`) + `app.fawaterk.com/llms.txt` + المرجع المقروء `app.fawaterk.com/documentation`
> نسخة من الـ OpenAPI متخزنة في `docs/gateways/vendor/fawaterk-openapi.json`. حدّثها لما الـ API يتغيّر.

## 1. البيئات والمصادقة
| | Staging | Live |
|---|---|---|
| Base | `https://staging.fawaterk.com` | `https://app.fawaterk.com` |

- 📘 **OAuth client credentials** (من الـ Dashboard: Integrations ← OAuth client credentials): `POST /oauth/token` بـ `grant_type=client_credentials` ← `Bearer` لكل `/api/v3/*`. الـ token صالح **سنة** (`expires_in: 31536000`)، وممكن يجي معاه `refresh_token`
- 📘 **Vendor API Key**: بيستخدم في HMAC بتاع كل الـ webhooks
- 📘 Domain hash (`FAWATERAK-HASH-KEY` + `FAWATERAK-DOMAIN`) بيستخدم للـ iframe من غير backend، والحزمة **مش** بتستخدمه
- الحزمة بتخزن الـ token في الـ cache، فأي `cache:clear` يعني token جديد (ودي حاجة عادية)

## 2. وسائل الدفع
📘 `GET /api/v3/getTrPaymentmethods` بيرجّع الوسائل اللي "Integration status" بتاعها مفعّل بس (Business settings ← Payment method):
`payment_method_id`، و`name_en`، و`name_ar`، و`logo`، و`redirect` (`"true"` = link mode، و`"false"` = direct-dispatch)، و`commission_on_customer` (1 = العمولة على العميل، و2 = على التاجر).

## 3. إنشاء دفع: `POST /api/v3/createTransaction`
**مطلوب:** `currency`، و`customer{first_name,last_name}`، و`cartItems[{name,price,quantity≥1}]`، و`cartTotal`.
**اختياري:** `payment_method_id`، و`mobileWalletNumber`، و`save_customer` (ولو اتبعت يبقى `customer_unique_id` مطلوب)، و`pay_load` (object بيرجع في الـ webhooks)، و`redirectionUrls{successUrl, failUrl, pendingUrl, backUrl, webhookUrl}`، و`sendEmail`، و`sendSMS`، و`due_date` (الافتراضي +2 يوم)، و`tr_number`، و`redirectOption`، و`authAndCapture` (0/1)، و`taxData`، و`discountData`، و`list_style` (h/v)، و`lang`.

| الطريقة | امتى | الرد |
|---|---|---|
| **Hosted** | من غير `payment_method_id` | `data.url` + `intent_key` + `expires_in` (المثال 2592000 = 30 يوم) |
| **Hosted + وسيلة متختارة** | `payment_method_id` + `redirectOption: true` | 📘 "forces link mode" ❓ العميل يقدر يغيّر الوسيلة؟ (E-F2) |
| **Direct** | `payment_method_id` والوسيلة `redirect="false"` | `data.payment_data` (شوف تحت) |

**أشكال `payment_data` في الـ direct:**
| الوسيلة | الحقول | المطلوب منك |
|---|---|---|
| كارت | `redirectTo` | تفتح الـ URL |
| فوري / أمان / مصاري | `referenceNumber`، و`expireDate`، و`expirationTime` | تعرض الكود والصلاحية |
| محفظة (Meeza RTP) | `systemReference`، و`isoQr` | QR أو "افتح تطبيق المحفظة" |

- 📘 **مفيش طريقة تحدد الوسائل لكل فاتورة** في الصفحة الـ hosted. هتظهر كل الوسائل المفعّلة في الحساب
- 🧪 (كلام صاحب المشروع) الصفحة الـ hosted بتعرض كود فوري بشكل بسيط وواضح
- 📘 `"No transactions row until the customer pays or direct-dispatch runs"`. يعني `getTransactionData` بيرجّع `transaction_id = 0` لحد ما يحصل كده
- الأخطاء: `422` validation، و`401` الـ vendor مش متعرّف (credentials)، و`503` الـ intent cache مش متاح (ده مؤقت، اعمل retry)

## 4. الحالة: `POST /api/v3/getTransactionData` بـ `{intent_key}`
📘 بيرجّع: `paid` (0/1)، و`status_text` (زي `unpaid` أو `paid`...)، و`total` **محوّل لجنيه**، و`currency` (EGP دايماً)، و`payment_method` (اسمه مترجم)، و`pay_load`، و`paid_at`، و`due_date`، و`transaction_link`، و`transaction_history[{method,amount,status,reference,date}]`، و`commission`.
- `422` لو الـ `intent_key` غلط
- 📘 `GET /api/v3/getTransactionsData?start_date&end_date&per_page&page&pay_load` بيرجّع export لكل الـ transactions (مفيد للـ reconcile الشامل)

## 5. Webhooks
📘 بتتظبط من Integrations: **Webhook** (paid/pending)، و**Failed webhook**، و**Cancellation webhook**، و**Refund webhook**. والـ paid/pending بس هو اللي ينفع يتحدد كمان لكل transaction (`redirectionUrls.webhookUrl`).
📘 **`_json` في الـ path** لـ paid **و**failed علشان الـ body يجي JSON (غير كده `x-www-form-urlencoded`). الـ cancel والـ refund JSON دايماً.

| النوع | URL في الحزمة | الحقول المهمة | التوقيع (HMAC-SHA256 بالـ Vendor API Key) |
|---|---|---|---|
| paid / pending | `/payment-gateway/webhooks/fawaterk/paid_json` | `transaction_key` (= `intent_key` 📘)، و`transaction_id`، و`status` (paid/pending)، و`payment_method`، و`pay_load` (string)، و`paidAmount`، و`paidCurrency`، و`paidAt`، و`referenceNumber`، و`customerData`، و`fees` | `transactionHashKey` = `TransactionId={id}&TransactionKey={key}&PaymentMethod={pm}` |
| failed | `/payment-gateway/webhooks/fawaterk/failed_json` | `transaction_key`، و`transaction_id`، و`payment_method`، و`amount`، و`errorMessage`، و`response` | `hashKey` = نفس الـ string |
| cancel | `/payment-gateway/webhooks/fawaterk/cancel` | `referenceId`، و`status` (EXPIRED/CANCELED)، و`paymentMethod`، و`transactionId`، و`transactionKey` | `hashKey` = `referenceId={id}&PaymentMethod={pm}` |
| refund | `/payment-gateway/webhooks/fawaterk/refund` | `transactionId`، و`amount`، و`currency`، و`status` (integer، 1 = approved)، و`reason`، و`approvedAt` | `hashKey` = `transactionId={id}&amount={a}&currency={c}` |

- 📘 "Fulfill orders only when `status` is `paid`". الـ pending معناه إن كود فوري لسه مستني يتدفع
- 📘 رد بـ **200** بسرعة (أي body)
- ⚠️ **الحاجات الموقّعة**: الـ ids والـ payment method بس. الـ `status` والمبالغ و`pay_load` **مش موقّعين**، علشان كده الحزمة بتأكد الـ paid من `getTransactionData`، وبتعامل `pay_load` كـ reference مش موثوق
- ⚠️ الـ failed بيتوقّع بنفس الـ string بتاع الـ paid، فممكن يتعمله replay على إنه paid، وده مش مشكلة لأننا بنتأكد من الـ API
- 📘 فيه `hashKey` قديم (v2) مبني على `InvoiceId`/`InvoiceKey`، والحزمة بتستخدم v3 بس
- ❓ سياسة الـ retry عند Fawaterk مش موثقة (E-F6)

## 6. Refund
- 📘 `POST /api/v3/refund/create`: `refund_type` (`"3"` = integration transaction ← ده بتاعنا)، و`refund_id` (= `transaction_id` الرقمي)، و`reason` (**لازم** يكون من `POST /api/v3/refund/refundReasons`)، و`refundable_amount` (≥ 1)، و`comment`. الرد `{"status":"success","message":"Refunded successfully"}`
- 📘 الـ refund ليه **approval flow**، فالنتيجة النهائية بتيجي من webhook الـ refund (لما يتوافق عليه)
- 📘 `POST /api/v3/refund/index` (10 في الصفحة: `id`، و`refundable_type`، و`refundable_id`، و`refundable_amount`، و`status`)، و`GET /refund/details/{id}`، و`POST /refund/delete`
- الحزمة بتعتبر الـ refund `pending` بعد الطلب، وبتأكده من الـ refund list

## 7. Void / Capture
📘 `POST /api/v3/payment/void` و`/capture` للـ **authorized holds** بس (`authAndCapture=1`). الـ void مش بيلغي كود فوري ولا transaction لسه متدفعش.
📘 **مفيش API لإلغاء transaction أو كود فوري.** الإلغاء عندنا محلي بس، والكود بيفضل صالح لحد ما مدته تخلص.

## 8. الاختبار (staging)
| | الكارت | الانتهاء | CVV |
|---|---|---|---|
| ✅ Mastercard | `5123450000000008` | 12/26 | 100 |
| ✅ Visa | `4005550000000001` | 12/26 | 100 |
| ✅ Meeza | `5078036246600381` | 12/26 | 100 |
| ❌ Mastercard | `5543474002249996` | 05/26 | 123 |
| ❌ Visa | `4222000006724235` | 12/26 | 123 |
| ❌ Meeza | `5078036242783546` | 12/26 | 123 |

⚠️ تواريخ الانتهاء دي قربت تخلص أو خلصت (05/26 خلص فعلاً). لو الكارت اترفض لسبب غريب، شوف التوثيق يمكن اتحدّث (E-F1).

## 9. إعداد لوحة Fawaterk (Checklist)
- [ ] Integrations ← OAuth client credentials (staging وlive لوحدهم)
- [ ] Integrations ← Vendor API Key
- [ ] Webhook: `…/webhooks/fawaterk/paid_json`
- [ ] Failed webhook: `…/webhooks/fawaterk/failed_json`
- [ ] Cancellation webhook: `…/webhooks/fawaterk/cancel`
- [ ] Refund webhook: `…/webhooks/fawaterk/refund`
- [ ] Business settings ← Payment method: فعّل "Integration status" للوسائل المطلوبة، وحدد مين بيدفع العمولة

## 10. نصايح
- `cartItems` لازم مجموعها يساوي `cartTotal` بالظبط. الحزمة بتبعت item واحد بالمبلغ كله لو مبعتش items
- `total` في الـ status دايماً بالجنيه، فمتقارنش المبلغ إلا لو الدفع نفسه بالجنيه (الحزمة بتعمل كده)
- `due_date` و`expires_in`: ❓ العلاقة بينهم (E-F3)
