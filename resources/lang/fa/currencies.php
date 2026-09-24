<?php

return [
    'currency' => 'ارز',
    'base_amount' => 'مبلغ (:currency)',
    'rate_applied' => '۱ :currency = :rate :base',

    'status_enabled' => 'ارز :currency فعال شد.',
    'status_disabled' => 'ارز :currency غیرفعال شد.',
    'status_rate_saved' => 'نرخ تبدیل :currency ذخیره شد.',
    'status_rate_deleted' => 'نرخ تبدیل :currency حذف شد.',

    'validation' => [
        'invalid' => 'ارز انتخاب‌شده معتبر نیست.',
        'required' => 'انتخاب ارز الزامی است.',
        'rate_missing' => 'هیچ نرخ تبدیلی برای :currency در تاریخ :date ثبت نشده است. ابتدا آن را در تنظیمات ← ارزها ثبت کنید.',
        'is_base' => 'ارز پایه همیشه فعال است و قابل غیرفعال‌سازی نیست.',
        'already_enabled' => 'این ارز قبلاً فعال شده است.',
        'not_enabled' => 'قبل از ثبت نرخ، این ارز را در تنظیمات فعال کنید.',
        'prohibited' => 'پرداخت‌ها همیشه بر اساس ارز خود فاکتور ثبت می‌شوند.',
        'rate_required' => 'نرخ تبدیل الزامی است.',
        'rate_invalid' => 'نرخ تبدیل باید عدد معتبر با حداکثر ۸ رقم اعشار باشد.',
        'rate_positive' => 'نرخ تبدیل باید بزرگ‌تر از صفر باشد.',
        'effective_date_required' => 'تاریخ اعمال الزامی است.',
        'effective_date_invalid' => 'تاریخ اعمال باید تاریخ معتبری باشد.',
    ],
];
