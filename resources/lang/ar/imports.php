<?php

return [
    'import_customers' => 'استيراد العملاء',
    'import_customers_subtitle' => 'أنشئ العملاء بكميات كبيرة من ملف CSV. كل صف يصبح عميلاً جديداً — لا يتم تحديث أي شيء.',
    'import_products' => 'استيراد المنتجات',
    'import_products_subtitle' => 'أنشئ المنتجات والخدمات بكميات كبيرة من ملف CSV. كل صف يصبح صنفاً جديداً — لا يتم تحديث أي شيء.',

    'upload_title' => 'رفع ملف CSV',
    'upload' => 'رفع CSV',
    'csv_file' => 'ملف CSV',
    'csv_helper' => 'ملف CSV بترميز UTF-8 مع صف رأس، مطابق للقالب. الملفات محدودة بـ :max_kb كيلوبايت و :max_rows صفاً.',
    'download_template' => 'تنزيل القالب',
    'instructions_title' => 'قبل الرفع',
    'instruction_template' => 'ابدأ من القالب: فهو يحتوي على الأعمدة المحددة التي يقبلها هذا الاستيراد فقط.',
    'instruction_encoding' => 'احفظ الملف كملف CSV بترميز UTF-8. صف الرأس مطلوب؛ النصوص والاصفار البادئة تُحفظ كما هي.',
    'instruction_preview' => 'سترى دائماً معاينة وتقرير تحقق كاملاً قبل إنشاء أي شيء.',
    'instruction_all_or_nothing' => 'إذا كان أي صف غير صالح، فلن يُنشأ أي صف إطلاقاً — صحّح الأخطاء وأعد الرفع.',

    'preview_title' => 'معاينة الاستيراد',
    'preview_subtitle' => 'راجع الصفوف قبل إنشاء أي شيء.',
    'total_rows' => 'إجمالي الصفوف',
    'valid_rows' => 'الصفوف الصالحة',
    'invalid_rows' => 'الصفوف غير الصالحة',
    'row_label' => 'صف',
    'row_error' => 'الصف :row — :message',

    'header' => [
        'invalid' => 'رأس CSV غير صالح',
        'invalid_description' => 'صحّح الرأس وأعد رفع الملف.',
        'empty' => 'الملف فارغ: صف الرأس مطلوب.',
        'blank' => 'أحد أعمدة الرأس فارغ.',
        'unknown' => 'عمود غير معروف «:column».',
        'duplicate' => 'عمود مكرر «:column».',
        'missing' => 'العمود المطلوب «:column» مفقود.',
    ],
    'header_invalid' => 'رأس CSV غير صالح',
    'header_invalid_description' => 'صحّح الرأس وأعد رفع الملف.',

    'rows_invalid' => ':count من :total صفاً لا يمكن استيرادها.',
    'rows_invalid_description' => 'صحّح الصفوف المحددة في ملف CSV وأعد رفعه. لم يتم إنشاء أي شيء.',
    'errors_title' => 'أخطاء الصفوف',
    'no_errors' => 'لا توجد أخطاء.',
    'more_errors' => 'و :count أخرى…',

    'row' => [
        'required' => 'حقل «:label» مطلوب.',
        'column_count' => 'يحتوي الصف على :actual أعمدة، بينما كان متوقعاً :expected.',
    ],

    'preview_ready' => ':count صفاً جاهزة للاستيراد.',
    'preview_ready_description' => 'الملف صالح بالكامل. أكّد أدناه لإنشاء كل صف في خطوة واحدة.',
    'preview_rows' => 'عرض أول :count من أصل :total صفاً.',
    'confirm' => 'تأكيد الاستيراد',
    'confirm_note' => 'التأكيد ينشئ :count سجلاً جديداً. الاستيراد لا يُحدّث أي شيء أبداً.',
    'cancel' => 'إلغاء',
    'back_to_upload' => 'رفع ملف مختلف',

    'completed' => 'اكتمل الاستيراد. تم إنشاء :count سجلاً.',
    'queued' => 'بدأ الاستيراد في الخلفية. سيتم إنشاء :count سجلاً.',
    'failed' => 'فشل الاستيراد',
    'execution_guard' => 'تم إعادة التحقق من الملف قبل الكتابة مباشرة ولم يعد صالحاً. لم يتم إنشاء أي شيء.',
    'cancelled' => 'أُلغي الاستيراد. تم تجاهل الملف المرفوع.',
    'session_expired' => 'انتهت جلسة الاستيراد هذه أو لم تعد متاحة. يرجى البدء من جديد.',

    'sku_duplicate' => 'رمز SKU مكرر في نفس الملف.',
    'reference_not_found' => '«:label» المرتبطة غير موجودة في هذا النشاط التجاري.',
    'tax_disabled' => 'الضريبة غير مفعّلة لهذا النشاط التجاري.',

    'too_many_rows' => 'الملف يحتوي على أكثر من :limit صفاً. قسّمه وأعد رفعه.',

    'validation' => [
        'file_required' => 'يرجى اختيار ملف CSV للرفع.',
        'file_mimes' => 'يجب أن يكون الملف بامتداد .csv.',
        'file_extensions' => 'يجب أن يكون للملف المرفوع امتداد .csv.',
        'file_max' => 'لا يمكن أن يتجاوز ملف CSV :max كيلوبايت. قسّمه وأعد رفعه.',
    ],
];
