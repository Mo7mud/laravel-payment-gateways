# التقدّم

المرجع: `04-package-review.md` (ملفات المشروع)، والترتيب: R1 ← R3 ← R16 ← R4 ← R5 ← R17 ← الباقي.

## الحالة
| البند | الحالة | ملاحظة |
|---|---|---|
| R1 تنفيذ الطلب لو الـ listener فشل | ✅ | `markFulfilled()` + `payments:redispatch-unfulfilled`، والـ listener exception بقت log `critical` |
| R2 ربط webhook فواتيرك | ✅ من التوثيق | فاضل اختبار `findByGatewayReference` + log `warning` لـ `PaymentNotFound` |
| R3 الرجوع: status sync + redirect لكل دفع | ⏳ التالي | |
| R16 `cancelPayment()` | ⏳ | |
| R4 Fawaterk `failUrl`/`pendingUrl` لكل دفع | ⏳ | |
| R5 middleware الـ webhooks من غير `api` | ⏳ | |
| R17 Fawaterk `expires_at` مع `due_date` | ⏳ | محتاج تجربة S2 |
| R6–R15، R18، R19 | ⏳ | |

## قرارات
- **R1:** الآلية مفعّلة افتراضياً (مفيش تطبيقات شغالة بالحزمة لسه). والدفع `refunded` بالكامل مش بيتعاد إطلاقه.
- **R1:** أي exception في الـ listener بعد الـ commit بتتسجل ومبتترميش، لأن الـ retry من البوابة بيرجع duplicate ومبيفيدش.

## آخر إيداع
- `762f3f3` (قبل R1)
