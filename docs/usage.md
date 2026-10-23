# دليل استخدام `mo7mud/laravel-payment-gateway`

> الدليل ده بيشرح الإصدار الحالي زي ما هو في الكود. أي حاجة لسه متعملتش متعلّم عليها **(مخطط)**، ورقمها من المراجعة (R1، R3، R16...).

## 1. الفكرة في سطرين
الحزمة بتغلّف أكتر من بوابة (حالياً Kashier وFawaterk) ورا API واحد. **هي وحدها اللي بتغيّر حالة الدفع**، وبتبلّغ التطبيق بالـ Events. التطبيق عمره ما يصدّق صفحة الرجوع أو query string. التطبيق بيسمع `PaymentSucceeded` وينفّذ الطلب.

```
التطبيق ── createPayment ──► PaymentService ──► Gateway (Kashier/Fawaterk)
   ▲                              │  ▲
   │ Events (بعد الـ commit)       │  └── webhook / status query / reconcile
   └──────────────────────────────┘
```

## 2. التثبيت
```bash
composer require mo7mud/laravel-payment-gateway
php artisan migrate
# اختياري
php artisan vendor:publish --tag=payment-gateway-config
```
- المتطلبات: PHP ^8.2، Laravel 11/12/13، و`ext-json` و`ext-mbstring`
- الـ Provider بيتسجّل لوحده (auto-discovery)
- **الـ scheduler لازم يكون شغال** (`* * * * * php artisan schedule:run`)، لأن الحزمة بتسجّل `payments:reconcile` و`payments:redispatch-unfulfilled` كل 5 دقايق، و`payments:prune-webhooks` يومياً

### الجداول
| المفتاح | الاسم الافتراضي | المحتوى |
|---|---|---|
| `tables.payments` | `gateway_payments` | دفعة لكل reference |
| `tables.refunds` | `gateway_payment_refunds` | دفتر الـ refunds (ledger) |
| `tables.webhook_events` | `gateway_payment_webhook_events` | كل webhook جه، علشان الـ idempotency والمراجعة |
| — | `payment_integration_packages/gateways` | مفاتيح التشغيل في صفحة الأدمن |

الـ migrations بترفض تلمس جدول بنفس الاسم لو الحزمة مش هي اللي عاملاه.

## 3. الإعدادات (`.env`)
### Kashier
| المتغير | ملاحظة |
|---|---|
| `KASHIER_ENVIRONMENT` | `test` أو `live` (المفاتيح لازم تكون من نفس البيئة) |
| `KASHIER_MERCHANT_ID` | `MID-xxx` |
| `KASHIER_PAYMENT_API_KEY` | بيتحقق بيه من توقيع الـ webhook وصفحة الرجوع |
| `KASHIER_SECRET_KEY` | header اسمه `Authorization` في الـ API calls |
| `KASHIER_RETURN_URL` | الافتراضي لـ `merchantRedirect` (ممكن يتغيّر لكل دفع بـ `returnUrl`) |
| `KASHIER_SERVER_WEBHOOK_URL` | بيتبعت كـ `serverWebhook` مع كل session |
| `KASHIER_ALLOWED_METHODS` | الافتراضي `card,wallet`. التقسيط وBNPL ممنوعين عن قصد |
| `KASHIER_EXPIRE_MINUTES` | صلاحية الـ session (الافتراضي 30) |
| `KASHIER_DISPLAY` | `ar` أو `en` |
| `KASHIER_ENABLE_3DS` / `KASHIER_FAILURE_REDIRECT` / `KASHIER_MAX_FAILURE_ATTEMPTS` | |

### Fawaterk
| المتغير | ملاحظة |
|---|---|
| `FAWATERK_ENVIRONMENT` | `test` (staging.fawaterk.com) أو `live` (app.fawaterk.com) |
| `FAWATERK_CLIENT_ID` / `FAWATERK_CLIENT_SECRET` | OAuth client credentials، والـ token بيتخزن في الـ cache |
| `FAWATERK_VENDOR_API_KEY` | HMAC الـ webhooks |
| `FAWATERK_SUCCESS_URL` / `FAIL_URL` / `PENDING_URL` / `BACK_URL` | الـ success والـ back بيتغيّروا لكل دفع. الـ fail والـ pending من الـ config بس (R4) |
| `FAWATERK_WEBHOOK_URL` | **لازم** يكون فيه `_json`، وإلا بيترفض |

### عامة
| المفتاح | الافتراضي | ملاحظة |
|---|---|---|
| `PAYMENT_GATEWAY_ROUTE_PREFIX` | `payment-gateway` | |
| `routes.middleware` | `['api','throttle:600,1']` | لو الـ `api` group عندك فيه middlewares بتعدّل في الردود، شيله (R5) |
| `PAYMENT_GATEWAY_RETURN_REDIRECT_URL` | فاضي ← JSON | قالب فيه `{reference}` و`{status}` و`{gateway}` و`{error}` |
| `PAYMENT_GATEWAY_RETURN_ALLOWED_HOSTS` | فاضي | الـ hosts المسموحة لـ `return_redirect_url` لكل دفع، ومفصولة بـ `,` |
| `PAYMENT_GATEWAY_RETURN_SYNC_STATUS` | `true` | بيسأل البوابة عن الحالة لما العميل يرجع والدفع لسه مفتوح |
| `PAYMENT_GATEWAY_LOG_CHANNEL` | القناة الافتراضية | الأفضل قناة `payments` لوحدها |
| `PAYMENT_GATEWAY_ADMIN_ENABLED` | `false` | صفحة إعدادات بتكتب في `.env`، ولازمها Gate ability |

### إعدادات من قاعدة البيانات بدل `.env`
لو التطبيق بيخزن المفاتيح في DB، حطها في `config()` من `boot()` في provider بتاعك **قبل** ما أي حاجة من الحزمة تتعملها resolve (الـ gateways بتتبني lazy):
```php
config(['payment-gateway.kashier.secret_key' => decrypt($row->value)]);
```

## 4. إنشاء دفع
```php
use Mo7mud\LaravelPaymentGateway\Services\PaymentService;
use Mo7mud\LaravelPaymentGateway\DTOs\{CreatePaymentRequest, CustomerData};
use Mo7mud\LaravelPaymentGateway\ValueObjects\{Money, Currency};

$result = app(PaymentService::class)->createPayment('kashier', new CreatePaymentRequest(
    paymentReference: 'XP-01JB3...',                  // فريد ومش بيتكرر (ULID)
    amount: Money::fromDecimal('150.00', Currency::egp()),
    customer: new CustomerData(id: '42', name: 'Ahmed Ali', email: 'a@x.com', phone: '010...'),
    returnUrl: route('my.return', ['ref' => 'XP-01JB3...']),
    metadata: ['cart_item_name' => 'Ride #123'],      // Fawaterk بيستخدمها كاسم للمنتج
));

return redirect()->away($result->checkoutUrl);       // ⚠️ ممكن تبقى null (فوري direct)
```
**قواعد:**
- **متناديهاش جوه `DB::transaction`** بتاعك (R8): هيحصل HTTP call والـ locks ماسكة، والـ events هتتأجل
- نفس الـ reference بنفس المبلغ يرجّع نفس النتيجة (idempotent). لو المبلغ أو البوابة اختلفوا ← `PaymentReferenceConflictException`
- لو إنشاء فشل أو اتقطع، بيتعمل retry أوتوماتيك بـ merchant order id جديد (`<ref>-A2-xxxxxx`)
- المبلغ اللي البوابة رجّعته بيتقارن بالمطلوب ← `PaymentAmountMismatchException`

### Metadata خاصة بكل بوابة
| المفتاح | البوابة | التأثير |
|---|---|---|
| `fawaterk_payment_method_id` | Fawaterk | يختار وسيلة (من `getTrPaymentmethods`) |
| `fawaterk_redirect_option` | Fawaterk | `true` = يفضل على الصفحة الـ hosted حتى لو فيه وسيلة متختارة |
| `fawaterk_due_date` | Fawaterk | آخر ميعاد للدفع (الافتراضي عندهم +2 يوم) |
| `fawaterk_mobile_wallet_number` | Fawaterk | Meeza direct |
| `fawaterk_lang` / `fawaterk_list_style` | Fawaterk | لغة وشكل الصفحة |
| `fawaterk_tr_number` | Fawaterk | الرقم اللي بيظهر للتاجر |
| `return_redirect_url` | الكل | redirect خاص بالدفع ده بعد الرجوع (http/https، والـ host لازم يكون في `return.allowed_hosts`، وإلا `createPayment` بترمي `InvalidArgumentException`) |
| `cart_items` | Fawaterk | `[{name, price, quantity}]`، والمجموع **لازم** يساوي المبلغ |
| `forward_metadata_keys` (config) | الاتنين | مفاتيح إضافية بتتبعت للبوابة غير `payment_reference` |

### نتيجة فوري (Fawaterk direct)
`checkoutUrl = null`، و`metadata` جواها `fawaterk_payment_data_type = reference` و`fawaterk_reference_number` و`fawaterk_reference_expire_date`. اعرضهم للعميل، والدفع هيتأكد بعدين من الـ webhook.

## 5. الحالات
```
initiating → pending → processing → succeeded → partially_refunded → refunded
     ↓          ↓          ↓
creation_failed  failed / cancelled / expired ──(دفع اتأكد متأخر)──► succeeded (late_payment=true)
```
- `isPaid()`: succeeded، refunding، partially_refunded، refunded
- أي تحديث جاي متأخر أو بترتيب غلط (STALE) **بيتجاهل** ومبيرميش exception

## 6. الـ Events (بتطلع بعد الـ commit، وفيها scalars بس)
| Event | الحقول | تعمل إيه |
|---|---|---|
| `PaymentSucceeded` | `paymentReference`, `gateway`, `latePayment` | **نفّذ الطلب هنا**. لو `latePayment=true` طبّق سياسة الدفع المتأخر |
| `PaymentClosed` | + `status` (failed/cancelled/expired) | الغي الحجز أو ارجع لاختيار وسيلة الدفع |
| `PaymentAttemptFailed` | + `gatewayStatus`, `reason` | محاولة واحدة فشلت والدفع لسه مفتوح (العميل ممكن يحاول تاني) |
| `PaymentRefunded` | + `refundedMinor`, `currency`, `fullyRefunded`, `source` | حدّث حسابات التطبيق |
| `LatePaymentReceived` | + `previousStatus` | تنبيه للمراجعة |
| `PaymentStatusChanged` | + `from`, `to`, `source` | لوج أو audit |

### ⚠️ عقد الـ listener (Integration contract)
1. **Idempotent**: نفس الـ event ممكن يوصل أكتر من مرة (إعادة الإطلاق تحت، أو queue retry)
2. **أكّد التنفيذ بـ `markFulfilled()`**: بعد ما تتعامل مع `PaymentSucceeded` (نفّذت الطلب، أو قررت ترجّع الفلوس في حالة `latePayment`)، نادي `markFulfilled` **جوه نفس الـ transaction** بتاع التنفيذ:
   ```php
   DB::transaction(function () use ($event, $payments) {
       $order = Order::where('payment_reference', $event->paymentReference)->lockForUpdate()->firstOrFail();
       if (!$order->is_paid) {
           $order->markPaid();
       }
       $payments->markFulfilled($event->paymentReference); // idempotent: بترجّع false لو اتعلّم قبل كده
   });
   ```
   - لو الدفع مش `isPaid()` بترمي `PaymentNotPaidException`
   - أي دفع `succeeded` أو `refunding` أو `partially_refunded` من غير `fulfilled_at`، وعدّى عليه `fulfilment.older_than_minutes` (10)، بيطلع له `PaymentSucceeded` تاني من `payments:redispatch-unfulfilled`. ده بيحصل بـ backoff من 5 دقايق لحد 6 ساعات، ولحد `fulfilment.max_dispatches` (10) مرات، وبعدها log `critical` (`payment.fulfilment_redispatch_exhausted`) والقرار يدوي
   - الدفع اللي اترجّع بالكامل (`refunded`) مش بيتعاد إطلاقه
   - لو التطبيق مش عايز الآلية دي: `PAYMENT_GATEWAY_REDISPATCH_UNFULFILLED=false`
3. **الـ exception في الـ listener مش بتوقّع العملية**: الحالة اتعملها commit خلاص، فالحزمة بتسجّل log `critical` (`payment.listener_failed`) ومعاه الـ exception، وبتكمّل باقي الـ events، والـ webhook بيرجع 200. والتعافي بيحصل من إعادة الإطلاق مش من retry البوابة
4. متنفّذش الطلب من صفحة الرجوع

## 7. الـ Routes
| Route | الاستخدام |
|---|---|
| `POST {prefix}/webhooks/{gateway}/{type?}` | Kashier: `/webhooks/kashier`. Fawaterk: `/webhooks/fawaterk/paid_json` و`/failed` و`/cancel` و`/refund` |
| `GET\|POST {prefix}/return/{gateway}` | رجوع العميل. الـ query مش بيغيّر الحالة، بس ممكن يتسأل الـ API (تحت). وبعدها redirect أو JSON |

أكواد الرد على الـ webhook: `401` توقيع غلط، و`200` اتعالج أو اتجاهل أو فشل بشكل نهائي، و`503` غلطة مؤقتة أو مشكلة في الإعدادات (هيتبعت تاني)، و`500` خطأ مش متوقع، و`409` مكرر (Kashier).

### صفحة الرجوع
1. **التوقيع:** البوابة بتتحقق منه (Kashier). والـ query عمره ما يغيّر الحالة
2. **الـ sync (`return.sync_status`، الافتراضي `true`):** لو الدفع لسه `pending` أو `processing`، الحزمة بتسأل البوابة مرة واحدة بـ `getPaymentStatus()` قبل الـ redirect، فالعميل بيشوف الحالة الحقيقية حتى لو الـ webhook لسه موصلش. ولو حصل نجاح، بيطلع `PaymentSucceeded` عادي
   - **الـ throttle:** مرة واحدة لكل دفع كل `return.sync_lock_seconds` (10) ثواني، عن طريق الـ cache
   - **لو البوابة مردّتش:** بيتسجل log `warning` (`payment.return_sync_failed`)، والـ redirect بيكمّل بالحالة المتخزنة
3. **الـ redirect:**
   - لو الدفع اتعمل بـ `metadata['return_redirect_url']`، والـ host بتاعه موجود في `return.allowed_hosts`، بيتعمل redirect عليه
   - غير كده، بيتعمل redirect على القالب `return.redirect_url`
   - ولو القالب فاضي، الرد بيبقى JSON: `payment_reference` و`status` و`error`
   - **الـ placeholders** في الاتنين: `{reference}` و`{status}` و`{gateway}` و`{error}`

لو عندك route خاص بيك (جسر مثلاً)، `handleReturn()` بيعمل الـ sync نفسه، ونتيجته في `localStatus`:
```php
$ret = $payments->handleReturn(new PaymentReturnRequest($request->query()), 'kashier'); // بيتحقق من التوقيع، وبيعمل sync لو مفعّل
$ret->localStatus; // الحالة المتخزنة بعد الـ sync (null لو الدفع متعرفش)
```

## 8. Status / Reconcile
```php
$payments->getPaymentStatus(new PaymentStatusRequest('XP-...'));  // Kashier: session، وFawaterk: getTransactionData
$payments->reconcilePayment(new PaymentStatusRequest('XP-...'));  // Kashier: بيدوّر في الـ orders (أدق)، وبيحدّث الـ refunds
```
- `payments:reconcile --limit= --minutes=`: المدفوعات المفتوحة اللي بقالها أكتر من `stale_minutes` (15) من غير تحديث، مع exponential backoff من 5 دقايق لحد 6 ساعات، ولمدة `max_age_days` (30). الأمر ده كمان بيعمل expire بعد الصلاحية + 30 دقيقة grace

## 9. Refund
```php
$payments->refund(new RefundPaymentRequest(
    paymentReference: 'XP-...',
    amount: Money::fromDecimal('50.00', Currency::egp()),   // null = الباقي كله
    reason: 'Ride cancelled',
    idempotencyKey: 'refund-booking-123',                   // مهم لو عملت retry
));
```
- بيتحجز صف `pending` في الـ ledger الأول، علشان مفيش refund أكتر من المبلغ حتى لو جم طلبين في نفس الوقت
- Kashier: الرد بيرجع على طول. Fawaterk: **pending** لحد ما webhook الـ refund يوصل (approval flow)
- لو النتيجة مش معروفة (timeout): الصف بيفضل pending و`outcome_unknown=true`، والقرار يتاخد يدوي بـ:
  `php artisan payments:resolve-refund {uuid} succeeded|failed --reference=`

## 10. الإلغاء
```php
$status = $payments->cancelPayment('XP-...'); // PaymentStatus بعد الإلغاء
if ($status->isPaid()) {
    // العميل دفع قبل الإلغاء بلحظة: PaymentSucceeded طلع، وطبّق سياستك (تنفيذ أو refund)
}
```
- **إمتى بيشتغل:** الدفع لازم يكون `pending` أو `processing` أو `creation_failed`، وبيتقفل `cancelled` وبيطلع `PaymentClosed`. ولو الدفع مش مفتوح، بيرجع حالته زي ما هي من غير أي تغيير
- **`initiating`:** بيرمي `PaymentCreationInProgressException`، فحاول تاني بعد شوية
- **Kashier:** بيحاول يترك الـ session (abandon بـ `PATCH`، ومفيش cancel في Kashier)، وبعدين بيقرا حالتها. لو كانت اتدفعت قبلها، الدفع بيفضل مدفوع (مش late). ولو الـ abandon فشل (على test بيفشل دايماً، E-K4)، بيتسجل log `warning` (`payment.kashier_abandon_failed`) والإلغاء بيبقى محلي بس، فالصفحة ممكن تفضل تقبل الدفع لحد ما مدتها تخلص
- **Fawaterk:** مفيش API للإلغاء، فالإلغاء محلي بس، والصفحة أو كود فوري بيفضل ينفع يتدفع لحد ما مدته تخلص
- **الدفع المتأخر:** أي دفع بيتأكد بعد الإلغاء بيتسجل `late_payment` ويطلع `LatePaymentReceived` + `PaymentSucceeded(latePayment: true)`
- **لو البوابة فشلت:** لو قراءة الحالة نفسها فشلت، الـ exception بيترمي ومفيش حاجة بتتغيّر، فحاول تاني

## 11. الاختبار
- `Testing\MockGateway` + `InMemory*Repository` + `InMemoryTransactionRunner` = اختبارات للتطبيق من غير DB ولا HTTP (الأمثلة في `tests/Unit/Webhooks/WebhookIdempotencyTest.php`)
- لو بتستخدم `Illuminate\Events\Dispatcher` من غير container في الاختبار: `$dispatcher->setTransactionManagerResolver(fn () => null)`
- اختبارات التكامل الحقيقية: `tests/Integration/*` + `tests/Fixtures/kashier.env` (ممنوع يترفع على Git)
- كروت وأرقام الاختبار في `docs/gateways/*.md`

## 12. Checklist الإطلاق
- [ ] مفاتيح live، و`*_ENVIRONMENT=live`، و`APP_DEBUG=false`
- [ ] الـ webhook URLs متسجلة في لوحة البوابة (Fawaterk: 4 URLs، والـ paid فيه `_json`)
- [ ] الـ cron بتاع `schedule:run` شغال، ومتأكد إن `payments:reconcile` بيشتغل (اتأكد من اللوج)
- [ ] قناة لوج `payments` + تنبيه على `critical`
- [ ] الـ listener بتاع `PaymentSucceeded` idempotent وبينادي `markFulfilled()`، وتنبيه على `payment.listener_failed` و`payment.fulfilment_redispatch_exhausted`
- [ ] دفعة حقيقية صغيرة + refund ليها على كل بوابة
