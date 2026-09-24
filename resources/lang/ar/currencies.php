<?php

return [
    'currency' => 'العملة',
    'base_amount' => 'المبلغ (:currency)',
    'rate_applied' => '1 :currency = :rate :base',

    'status_enabled' => 'تم تفعيل العملة :currency.',
    'status_disabled' => 'تم تعطيل العملة :currency.',
    'status_rate_saved' => 'تم حفظ سعر الصرف للعملة :currency.',
    'status_rate_deleted' => 'تم حذف سعر الصرف للعملة :currency.',

    'validation' => [
        'invalid' => 'العملة المحددة غير صالحة.',
        'required' => 'العملة مطلوبة.',
        'rate_missing' => 'لا يوجد سعر صرف مسجل للعملة :currency في تاريخ :date. سجّله أولاً من الإعدادات ← العملات.',
        'is_base' => 'العملة الأساسية مفعلة دائماً ولا يمكن تعطيلها.',
        'already_enabled' => 'هذه العملة مفعلة بالفعل.',
        'not_enabled' => 'فعّل هذه العملة من الإعدادات قبل تسجيل أسعار لها.',
        'prohibited' => 'تُسجَّل المدفوعات دائماً بعملة الفاتورة نفسها.',
        'rate_required' => 'سعر الصرف مطلوب.',
        'rate_invalid' => 'يجب أن يكون سعر الصرف رقماً صالحاً بحد أقصى 8 خانات عشرية.',
        'rate_positive' => 'يجب أن يكون سعر الصرف أكبر من صفر.',
        'effective_date_required' => 'تاريخ السريان مطلوب.',
        'effective_date_invalid' => 'يجب أن يكون تاريخ السريان تاريخاً صالحاً.',
    ],
];
