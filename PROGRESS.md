# التقدّم

المرجع: `04-package-review.md` (ملفات المشروع). النطاق: R1 ← R3 ← R16 ← `v0.2.0`، والباقي مؤجّل.

## الحالة
| البند | الحالة | ملاحظة |
|---|---|---|
| R1 تنفيذ الطلب لو الـ listener فشل | ✅ | `markFulfilled()` + `payments:redispatch-unfulfilled`، والـ listener exception بقت log `critical` |
| R2 ربط webhook فواتيرك | ✅ من التوثيق | فاضل اختبار `findByGatewayReference` + log `warning` لـ `PaymentNotFound` |
| R3 الرجوع: status sync + redirect لكل دفع | ✅ | `return.sync_status` و`return.allowed_hosts` و`{status}`/`{gateway}` |
| R16 `cancelPayment()` | ✅ | فاضل تجربة E-K4، وبعدها `v0.2.0` |
| الباقي (R2 الاختبار، R4–R15، R17–R19) | ⏸️ مؤجّل | بيتعمل بس لو مشروع طلبه |

## قرارات
- **R1:** الآلية مفعّلة افتراضياً (مفيش تطبيقات شغالة بالحزمة لسه). والدفع `refunded` بالكامل مش بيتعاد إطلاقه.
- **R16:** `cancelPayment()` بيرجّع `PaymentStatus`، ومبيرميش exception لو الدفع مش مفتوح. وفي Kashier بنقرا الـ session بعد الـ abandon علشان الدفع اللي اتعمل قبلها بلحظة يفضل مدفوع. والـ client capability منفصلة (`KashierSessionCancellationInterface`) علشان منكسرش أي implementation موجودة.
- **R3:** الـ sync عن طريق `getPaymentStatus()` بتاع الـ service (نفس قواعد الثقة)، والـ throttle بـ `cache->add` من غير ما نعمل release. والـ redirect لكل دفع بيتأكد منه مرتين: وقت الإنشاء (exception)، ووقت الرجوع (fallback للقالب).
- **R1:** أي exception في الـ listener بعد الـ commit بتتسجل ومبتترميش، لأن الـ retry من البوابة بيرجع duplicate ومبيفيدش.

## آخر إيداع
- R3 (بعد R1، بعد `762f3f3`)
