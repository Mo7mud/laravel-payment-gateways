# mo7mud/laravel-payment-gateway

حزمة Laravel بتربط بوابات الدفع المصرية (**Kashier** و**Fawaterk**) ورا API واحد. فيها:
- التحقق من الـ webhooks، وأي دفع بيتأكد من API البوابة قبل ما يتطبق
- قفل على مستوى الصف (row locks)، وidempotency للـ webhooks والـ refunds
- دفتر refunds، وفلوس محسوبة بـ minor units
- reconciliation أوتوماتيك، و**events بعد الـ commit** للتطبيق، وإعادة إطلاق `PaymentSucceeded` لحد ما التطبيق يأكّد التنفيذ بـ `markFulfilled()`

```bash
composer require mo7mud/laravel-payment-gateway && php artisan migrate
```

```php
$result = app(PaymentService::class)->createPayment('kashier', new CreatePaymentRequest(
    paymentReference: 'XP-01JB3...',
    amount: Money::fromDecimal('150.00', Currency::egp()),
    returnUrl: route('checkout.return'),
));
// redirect to $result->checkoutUrl — then fulfil the order from the PaymentSucceeded event
// and confirm it with $payments->markFulfilled($event->paymentReference)
```

## التوثيق
| الملف | المحتوى |
|---|---|
| [`docs/usage.md`](docs/usage.md) | دليل الاستخدام: الإعدادات، والإنشاء، والحالات، والـ events، وعقد الـ listener، والـ refund، والـ reconcile، والاختبار، وchecklist الإطلاق |
| [`docs/gateways/kashier.md`](docs/gateways/kashier.md) | مرجع Kashier: المفاتيح، والتوقيعات، والـ webhooks، والـ retries، والـ refund، وكروت الاختبار، وإعداد اللوحة |
| [`docs/gateways/fawaterk.md`](docs/gateways/fawaterk.md) | مرجع Fawaterk: OAuth، وطرق الدفع (hosted/direct)، وفوري، والـ webhooks الأربعة، والـ refund، وكروت الاختبار |
| [`docs/experiments.md`](docs/experiments.md) | سجل التجارب: أي سلوك اتأكد بالتجربة، أو لسه محتاج يتجرب |
| `docs/gateways/vendor/` | نسخ من التوثيق الرسمي (OpenAPI بتاع Fawaterk) |

**قاعدة صيانة:** لو أي معلومة عن بوابة اتأكدت بالتجربة أو اتغيّرت، بنحدّث `experiments.md` الأول، وبعدها ملف البوابة.

## الحالة
التقدّم في [`PROGRESS.md`](PROGRESS.md)، والتغييرات في [`CHANGELOG.md`](CHANGELOG.md).
