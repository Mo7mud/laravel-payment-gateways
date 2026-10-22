# CLAUDE.md

حزمة Laravel مستقلة (`mo7mud/laravel-payment-gateway`) بتربط بوابات الدفع المصرية (Kashier وFawaterk) ورا API واحد.

## الأوامر
```bash
composer install
vendor/bin/phpunit --exclude-group integration
vendor/bin/phpstan analyse
vendor/bin/pint
```
الاختبارات بتشتغل على SQLite in-memory افتراضياً. ولو عايز MySQL أو PostgreSQL: `DB_TEST_DRIVER` (التفاصيل في `tests/bootstrap.php`).

## الخريطة
- `src/Services/PaymentService.php`: المكان **الوحيد** اللي بيغيّر الحالة
- `src/Gateways/<Name>/`: الـ gateway والـ client والـ mapper والتوقيع
- `src/Repositories/`: نسخة Eloquent ونسخة InMemory (للاختبارات). أي method جديدة لازم تتعمل في الاتنين
- `database/migrations/`: بنضيف بس، وممنوع نعدّل migration نزلت
- `docs/usage.md`: العقد مع التطبيقات. و`docs/gateways/<name>.md`: المصدر الوحيد لمعلومات البوابة (اللي عليه ❓ ممنوع نبني عليه)

## القواعد
- **الحالة:** بتتغيّر جوه `atomic()` (transaction + `findByReferenceForUpdate`)، والـ events بتطلع بعد الـ commit. والـ exception في الـ listener بتتسجل ومبتترميش
- **الثقة:** أي webhook أو return ميتصدقش غير بعد التوقيع، ولو التوقيع مش بيغطي الحالة والمبلغ يبقى لازم تأكيد من الـ API. والـ return عمره ما يغيّر الحالة
- **الفلوس:** `Money` بـ minor units بس، ومفيش float
- **عامة:** مفيش أي حاجة خاصة بتطبيق معين. اللي التطبيق محتاجه بيتعمل بـ events أو contracts أو config
- **التوافق:** النسخ PHP ^8.2، وLaravel 11–13، وPHPUnit 11–12. وأي breaking change بيتكتب في `CHANGELOG.md` تحت "Breaking"، ومعاه migration path
- **سلوك البوابة اللي مش موثّق:** بيتجرب ويتسجل في `docs/experiments.md` الأول، وبعدين يتحدّث ملف البوابة
- **التقدّم:** بنحدّث `PROGRESS.md` و`CHANGELOG.md` مع كل تغيير
