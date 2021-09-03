## ThinkPHP6 自动生成模型的注释


## 功能
- 根据数据库的字段，以及模型的获取器和修改器等方法，自动生成模型的注释。以便于IDE可以获得类型提示。
- 开启模型字段schema的写入，开启后无需更新字段缓存，查看https://www.kancloud.cn/manual/thinkphp6_0/1037581了解schema



### 安装

~~~

composer require itinysun/think-model-helper

修改 配置文件
config/console/model_help

确保已经配置了数据库连接，并且可以连接到数据库
~~~

### 使用方法

~~~
//更新所有模型
php think model:help

//更新指定模型
php think model:help app\model\User app\model\Post

//清理所有模型
php think model:help -C

//清理指定模型
php think model:help app\model\User  app\model\Post -C

~~~

#### 可选参数
~~~

--clean [-C] 清理模式，根据提示清理生成的注释和字段

--overwrite [-O] 强制覆盖已有的属性注释
~~~

#### 感谢
本项目修改自 yunwuxin/think-model-helper
