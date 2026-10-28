<?php

declare(strict_types=1);

return [
    'title' => 'إعدادات حزمة بوابات الدفع',
    'package_hint' => 'تُدار من حزمة الدفع المشتركة',
    'enable_package' => 'تفعيل الحزمة',
    'enable_gateway' => 'تفعيل',
    'enabled' => 'مفعّل',
    'credentials' => [
        'test' => 'بيانات الوضع التجريبي',
        'live' => 'بيانات الوضع الحقيقي',
    ],
    'settings' => 'الإعدادات',
    'webhook_url' => 'رابط الـ Webhook',
    'return_url' => 'رابط الرجوع',
    'copy' => 'نسخ',
    'save' => 'حفظ وتطبيق',
    'secret_placeholder' => 'اتركه فارغًا للإبقاء على القيمة الحالية',
    'secret_configured' => 'توجد قيمة محفوظة بالفعل. اترك الحقل فارغًا للإبقاء عليها.',
    'attribute_with_mode' => ':label (:mode)',

    'packages' => [
        'core' => 'بوابات الدفع',
    ],

    'gateways' => [
        'kashier' => 'كاشير',
        'fawaterk' => 'فواتيرك',
    ],

    'modes' => [
        'test' => 'تجريبي',
        'live' => 'حقيقي',
    ],

    'fields' => [
        'environment' => 'البيئة',
        'client_id' => 'معرّف العميل (OAuth Client ID)',
        'client_secret' => 'سر العميل (OAuth Client Secret)',
        'vendor_api_key' => 'مفتاح Vendor API (توقيع الـ Webhook)',
        'success_url' => 'رابط النجاح',
        'fail_url' => 'رابط الفشل',
        'pending_url' => 'رابط الانتظار',
        'back_url' => 'رابط العودة',
        'http_timeout' => 'مهلة الاتصال (بالثواني)',
        'merchant_id' => 'معرّف التاجر (Merchant ID)',
        'payment_api_key' => 'مفتاح Payment API',
        'secret_key' => 'المفتاح السري (Secret Key)',
        'allowed_methods' => 'طرق الدفع المسموحة',
        'payment_type' => 'نوع الدفع',
        'default_method' => 'طريقة الدفع الافتراضية',
        'redirect_method' => 'طريقة التحويل',
        'display_language' => 'لغة صفحة الدفع',
        'enable_3ds' => 'تفعيل 3DS',
        'failure_redirect' => 'التحويل عند الفشل',
        'max_failure_attempts' => 'أقصى عدد لمحاولات الدفع الفاشلة',
        'expire_minutes' => 'مدة صلاحية صفحة الدفع (بالدقائق)',
    ],

    'options' => [
        'credit' => 'ائتمان',
        'get' => 'GET',
        'post' => 'POST',
        'arabic' => 'العربية',
        'english' => 'الإنجليزية',
    ],

    'messages' => [
        'saved' => 'تم حفظ إعدادات بوابات الدفع.',
        'invalid' => 'لم يتم حفظ الإعدادات. صحّح ما يلي ثم حاول مرة أخرى:',
        'locked' => 'إعدادات بوابات الدفع مقفلة. اضبط PAYMENT_GATEWAY_ADMIN_PERMISSION على صلاحية Gate.',
    ],

    'warnings' => [
        'config_cache_cleared' => 'تم مسح كاش الإعدادات. شغّل "php artisan config:cache" لإعادة بنائه.',
        'config_cache_failed' => 'تم حفظ الإعدادات، لكن تعذّر مسح كاش الإعدادات. شغّل "php artisan config:clear".',
        'queue_restart' => 'تم حفظ الإعدادات. أعد تشغيل الـ queue workers لتستخدم القيم الجديدة.',
    ],
];
