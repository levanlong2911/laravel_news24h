<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => '承認される必要です。',
    'active_url' => 'URLではありません。',
    'after' => '過去日付は入力不可です。',
    'after_or_equal' => 'このフィールドは:dateイコール又は大きく日付です。',
    'alpha' => '文字しか入力出来ません。',
    'alpha_dash' => '文字、数字、ハイフン、及び下線しか入力出来ません。',
    'alpha_num' => '文字及び数字しか入力出来ません。',
    'array' => 'このフィールドはアレイです。',
    'before' => ':dateより以前の日付です。',
    'before_or_equal' => ':dateイコール又は以前の日付です。',
    'between' => [
        'numeric' => ':min と :maxの間にあるデータです。',
        'file' => ':min と :max kilobytesmaxの間にあるデータです。',
        'string' => '文字は :min と :maxの間にあるデータです。',
        'array' => '項目は:min と :maxの間にあるデータです。 ',
    ],
    'boolean' => 'true または false を選択してください。',
    'confirmed' => 'このフィールドは間違いです。',
    'date' => '入力した日付が不正です。',
    'date_equals' => 'このフィールドは:dateで入力必須です。',
    'date_format' => '入力した形式が不正です。',
    'different' => 'このフィールドと:otherフィールドは同じデータを入力が不可です。',
    'digits' => 'このフィールドは:digitsが超えられません。',
    'digits_between' => ':min と :maxの間にあるデータです。',
    'dimensions' => '写真のサイズが不正です。',
    'distinct' => 'データは重複しています。',
    'email' => 'メールアドレスが不正です。',
    'ends_with' => '最後のデータは:values必須です。',
    'exists' => 'この値が不正です。',
    'file' => 'このフィールドはファイルです。',
    'filled' => 'このフィールドはデータを入力必須です。',
    'gt' => [
        'numeric' => 'このフィールドは:valueより大きくデータです。',
        'file' => ':value kilobytesより大きくデータです。',
        'string' => ':value文字より大きくデータです。',
        'array' => ':value項目より大きくデータです。',
    ],
    'gte' => [
        'numeric' => 'このフィールドは:valueイコール又は大きくデータです。',
        'file' => 'このフィールドは:value kilobytesイコール又は大きくデータです。',
        'string' => 'このフィールドは:value 文字イコール又は大きくデータです。',
        'array' => 'このフィールドは:value 項目イコール又は大きくデータです。',
    ],
    'image' => '写真が必須です。',
    'in' => '選択した項目が不正です。',
    'in_array' => ':otherに存在しません。',
    'integer' => '整数を入力必須です。',
    'ip' => 'IPアドレスが不正です。',
    'ipv4' => 'IPv4アドレスが不正です。',
    'ipv6' => 'IPv6アドレスが不正です。',
    'json' => 'JSONストリングが不正です。',
    'lt' => [
        'numeric' => ':valueより以前値です。',
        'file' => 'このフィールドは:value kilobytes少なくです。',
        'string' => 'このフィールドは:value少なく文字です。',
        'array' => 'このフィールドは:value少なく項目です。',
    ],
    'lte' => [
        'numeric' => 'このフィールドは:valueイコール又は少なくです。',
        'file' => 'このフィールドは:value kilobytesイコール又は少なくです。',
        'string' => 'このフィールドは:valueイコール又は少なく文字です。',
        'array' => ':value 項目を超えられません。',
    ],
    'max' => [
        'numeric' => 'このフィールドは:maxより大きくすることは出来ません。',
        'file' => 'このフィールドは:max kilobytesより大きくすることは出来ません。',
        'string' => 'この項目は:max文字を超えられません。',
        'array' => 'このフィールドは:max項目より大きくすることは出来ません。',
    ],
    'mimes' => ':valuesのファイルが必須です。',
    'mimetypes' => ':valuesのファイルが必須です。',
    'min' => [
        'numeric' => ':min以上の値を入力してください。',
        'file' => '最低:min kilobytesが必須です',
        'string' => '最低:min文字が必須です',
        'array' => '最低:min項目が必須です。',
    ],
    'not_in' => '選択したデータが不正です。',
    'not_regex' => 'フォマットが不正です。',
    'numeric' => '数字しか入力出来ません。',
    'password' => 'パスワードが不正です。',
    'present' => 'エリアが存在必須です。',
    'regex' => 'フォマットが不正です。',
    'required' => '必須の項目です。',
    'required_if' => ':otherがある場合、このフィールドが必須です。',
    'required_unless' => 'このフィールドが必須です。',
    'required_with' => ':valuesがある場合、このフィールドが必須です。',
    'required_with_all' => ':valuesがある場合、このフィールドが必須です。',
    'required_without' => ':valuesがない場合、このフィールドが必須です。',
    'required_without_all' => ':valuesがない場合、このフィールドが必須です。',
    'same' => 'このフィールドと:other合わせる必要です。 ',
    'size' => [
        'numeric' => ':sizeが必須です。',
        'file' => 'このフィールドは:size kilobytesが必須です。',
        'string' => 'このフィールドは:size文字が必須です。',
        'array' => 'このフィールドは:size 項目が必須です。',
    ],
    'starts_with' => ':valuesで始まる必須です。',
    'string' => 'このフィールドはストリングで入力必須です。',
    'timezone' => 'タイムゾーンが不正です。',
    'unique' => '既に存在されました。',
    'uploaded' => 'アップロードが失敗でした。',
    'url' => 'フォマットが不正です。',
    'uuid' => 'UUIDが不正です。',
    "check_number_int" => "TELには整数のみが含まれます。",
    "check_number_double" => "には小数のみが含まれます",
    'mimetypes_csv' => ':attributeはcsvタイプのファイルでなければなりません。',
    'mimetypes_csv_xlsx' => ':attributeはcsv, xlsxタイプのファイルでなければなりません。',
    'katakana' => 'カタカナで入力してください。',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
        'email' => [
            'unique' => 'このメールアドレスは既に登録済です。',
            'exists' => '正しくメールアドレスを入力してください。'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => '名',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'type' => '区分',
        'last_name' => '名',
        'first_name' => '姓',
        'last_name_kana' => 'メイ',
        'first_name_kana' => 'セイ',
        'gender' => '性別',
        'country_ids' => '国',
        'postal_code' => '郵便番号',
        'prefecture' => '都道府県',
        'address' => '住所',
        'phone' => '電話番号',
        'company_name' => '会社名',
        'company_name_kana' => '会社名カナ',
        'capital' => '資本金',
        'number_employees' => '従業員数',
        'department_name' => '部署名',
        'keyword' => 'キーワード',
        'from_name' => 'From タイトル',
        'from_name_other' => 'From サブタイトル',
        'from_address' => 'From 住所',
        'to_name' => 'To タイトル',
        'to_name_other' => 'To サブタイトル',
        'to_address' => 'To 住所',
        'category_id' => 'カテゴリ',
        'area_id' => 'エリア',
        'description' => '説明文',
        'tag_ids' => 'タグ',
        'tag_id' => 'タグ',
        'route_ids' => '経路一覧',
        'expiration_date' => '有効期限',
        'input_file' => 'アップロード',
    ],

];
