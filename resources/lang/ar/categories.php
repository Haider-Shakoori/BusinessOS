<?php

return [
    'title' => 'التصنيفات',
    'subtitle' => 'نظّم المنتجات في مجموعات لتسهيل إدارتها.',
    'view_only' => 'يمكنك عرض التصنيفات. إدارتها تتطلب إذن «إدارة التصنيفات».',

    'add' => 'إضافة تصنيف',
    'create_title' => 'تصنيف جديد',
    'edit_title' => 'تعديل التصنيف',

    'search' => 'بحث عن التصنيفات',
    'search_placeholder' => 'بحث عن التصنيفات…',

    'no_categories' => 'لا توجد تصنيفات',
    'no_categories_description' => 'لا توجد تصنيفات في هذا العمل بعد. أضف أول تصنيف للبدء.',
    'no_results' => 'لا توجد تصنيفات مطابقة',
    'no_results_description' => 'لا يوجد تصنيف يطابق بحثك. جرّب مصطلحًا آخر.',

    'name' => 'الاسم',
    'description' => 'الوصف',
    'description_helper' => 'وصف مختصر لما ينتمي إلى هذا التصنيف.',
    'created' => 'أُضيف',
    'updated' => 'مُحدَّث',
    'deleted' => 'تم حذف التصنيف.',

    'information' => 'المعلومات',

    'table_caption' => 'قائمة التصنيفات',
    'columns' => [
        'name' => 'التصنيف',
        'description' => 'الوصف',
        'created' => 'أُضيف',
        'actions' => 'إجراءات',
    ],

    'delete_confirm_title' => 'حذف :name؟',
    'delete_confirm' => 'سيؤدي هذا إلى إزالة التصنيف من قائمة التصنيفات. لا يمكن التراجع عن هذا الإجراء.',
    'delete_cancel' => 'إلغاء',
    'delete_submit' => 'حذف التصنيف',

    'validation' => [
        'name_required' => 'اسم التصنيف مطلوب.',
        'name_max' => 'اسم التصنيف لا يمكن أن يزيد عن 100 حرف.',
        'name_unique' => 'يوجد تصنيف بهذا الاسم بالفعل في هذا العمل.',
        'description_max' => 'الوصف لا يمكن أن يزيد عن 500 حرف.',
    ],
];
