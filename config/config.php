<?php

declare(strict_types=1);
return [
    /*
     * 开启模型注释的写入，开启后IDE可以获得类型提示。
     * 包括数据库字段、获取器、修改器
     */
    'write_phpdoc' => true,

    /*
     * 开启模型字段schema的写入，开启后无需更新字段缓存
     * https://www.kancloud.cn/manual/thinkphp6_0/1037581
     */
    'write_schema' => true,

    /*
     * 开启模型字段type的写入，开启后自动转换的类型
     * https://www.kancloud.cn/manual/thinkphp6_0/1037581
     */
    'write_type' => true,

    /*
     * 开启模型查询方法的辅助注释
     * 包括 find findOrEmpty select
     */
    'write_select_help' => true,

    /*
     * 开启关联模型查询方法的辅助注释
     */
    'write_relation_model' => true,

    /*
     * 是否允许覆盖
     * 注意，这里仅检查原有模型是否含有该字段，并不检测字段是否需要更新
     * 开启后每次都会更新并写入模型，即使没有字段变更，git也可能会刷新版本
     */

    'over_write_phpdoc' => false,
    'over_write_schema' => false,
    'over_write_type' => false,

    /*
     * 设置读取模型的目录
     * 例如：
     * ‘model’ 读取的是 base_path('model') 即 app 目录下的 model 目录
     * 或者直接使用绝对路径
     */
    'load_dir' => [
        'model',
    ],

    /*
     * 忽略更新的模型
     * 例如
     * \think\Model::class
     */
    'ignore_model' => [
    ],
];
