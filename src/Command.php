<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------
namespace itinysun\model\helper;

use Composer\Autoload\ClassLoader;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
use phpDocumentor\Reflection\DocBlock\StandardTagFactory;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\FqsenResolver;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\This;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use think\App;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\helper\Str;
use think\Model;
use think\model\Relation;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use think\model\relation\MorphMany;
use think\model\relation\MorphOne;
use think\model\relation\MorphTo;

class Command extends \think\console\Command
{

    protected $dirs = [];

    protected $properties = [];

    protected $methods = [];

    protected $overwrite = false;

    protected $clean = false;

    protected $schema=[];
    protected $schema_exsit=false;

    protected $config = [
        /*
         * 开启模型注释的写入，开启后IDE可以获得类型提示。
         * 包括数据库字段、获取器、修改器
         */
        'write_phpdoc'=>true,

        /*
         * 开启模型字段schema的写入，开启后无需更新字段缓存
         * https://www.kancloud.cn/manual/thinkphp6_0/1037581
         */
        'write_schema'=>true,

        /*
         * 开启模型查询方法的辅助注释
         * 包括 find findOrEmpty select
         */
        'write_select_help'=>true,

        /*
         * 是否允许覆盖
         * 注意，这里仅检查原有模型是否含有该字段，并不检测字段是否需要更新
         * 开启后每次都会更新并写入模型，即使没有字段变更，git也可能会刷新版本
         */

        'over_write_phpdoc'=>false,
        'over_write_schema'=>false,

        /*
         * 设置读取模型的目录
         * 例如：
         * ‘model’ 读取的是 base_path('model') 即 app 目录下的 model 目录
         * 或者直接使用绝对路径
         */
        'load_dir'=>[
            'model'
        ],

        /*
         * 忽略更新的模型
         * 例如
         * \think\Model::class
         */
        'ignore_model'=>[

        ],
    ];
    protected $choice ='';

    protected function configure()
    {
        $this
            ->setName('model:help')
            ->addArgument('model', Argument::OPTIONAL | Argument::IS_ARRAY, 'Which models to include', [])
            ->addOption('clean', 'C', Option::VALUE_NONE, 'Remove all of the phpdocs and schema')
            ->addOption('overwrite', 'O', Option::VALUE_NONE, 'Overwrite the phpdocs');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->dirs = $this->config['load_dir'];

        $this->config = array_merge($this->config,Config::get('model_help'));

        $this->cleanMode = $input->getOption('clean');
        if($this->cleanMode){
            $this->choice = $this->output->choice($input,"select clean mode:",['cancle','clean docs and schema','only docs','only schema'],'cancle');
            if('cancle'==$this->choice){
                $this->output->info('task cancle');
                return;
            }
        }else{
            $this->overwrite = $input->getOption('overwrite');
            if($this->overwrite)
                $this->output->info('Force overwrite : enable');
        }
        $model  = $input->getArgument('model');

        $this->generateDocs($model);
    }

    /**
     * 生成注释
     */
    protected function generateDocs($models=[])
    {

        if (empty($models)) {
            $models = $this->loadModels();
        }

        $ignore = $this->config['ignore_model'];

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= Output::VERBOSITY_QUIET) {
                    $this->output->comment("Ignoring model '$name'");
                }
                continue;
            }

            $this->properties = [];
            $this->methods    = [];

            if (class_exists($name)) {
                try {
                    $reflectionClass = new \ReflectionClass($name);
                    if (!$reflectionClass->isSubclassOf('think\Model')) {
                        continue;
                    }
                    if ($this->output->getVerbosity() >= Output::VERBOSITY_VERBOSE) {
                        $this->output->comment("Loading model '$name'");
                    }
                    if (!$reflectionClass->isInstantiable()) {
                        // 忽略接口和抽象类
                        continue;
                    }
                    $model = new $name;

                    if(empty($this->choice)){
                        $this->getPropertiesFromTable($name, $model);

                        $this->getPropertiesFromMethods($name, $model);

                        if($this->config['write_select_help'])
                            $this->getPropertiesFromDefault($name);

                        $this->createPhpDocs($name);
                        $ignore[] = $name;
                    }else{
                        $this->clean($name);
                    }
                } catch (\Exception $e) {
                    $this->output->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }
    }

    /**
     * 从数据库读取字段信息
     * @param string $class
     * @param Model  $model
     */
    protected function getPropertiesFromTable($class, Model $model)
    {
        $this->schema=[];

        $properties = (new ReflectionClass($class))->getDefaultProperties();

        $dateFormat = empty($properties['dateFormat']) ? $this->app->config->get('database.datetime_format') : $properties['dateFormat'];
        try {
            $fields = $model->getFields();
        } catch (\Exception $e) {
            $this->output->warning($e->getMessage());
        }
        if (!empty($fields)) {
            foreach ($fields as $name => $field) {

                if (in_array($name, (array) $properties['disuse'])) {
                    continue;
                }

                if (in_array($name, [$properties['createTime'], $properties['updateTime']])) {
                    if (false !== strpos($dateFormat, '\\')) {
                        $type = "\\" . $dateFormat;
                    } else {
                        $type = 'string';
                    }
                    $this->schema[$name]=$this->app->config->get('database.auto_timestamp');
                } elseif (!empty($properties['type'][$name])) {

                    $type = $properties['type'][$name];

                    if (is_array($type)) {
                        list($type, $param) = $type;
                    } elseif (strpos($type, ':')) {
                        list($type, $param) = explode(':', $type, 2);
                    }

                    switch ($type) {
                        case 'timestamp':
                        case 'datetime':
                            $format = !empty($param) ? $param : $dateFormat;

                            if (false !== strpos($format, '\\')) {
                                $type = "\\" . $format;
                            } else {
                                $type = 'string';
                            }
                            break;
                        case 'json':
                            $type = 'array';
                            break;
                        case 'serialize':
                            $type = 'mixed';
                            break;
                        default:
                            if (false !== strpos($type, '\\')) {
                                $type = "\\" . $type;
                            }
                    }
                } else {
                    if (!preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*(.+)*$/', $field['type'], $matches)) {
                        continue;
                    }
                    $limit     = null;
                    $precision = null;
                    $type      = $matches[1];
                    if (count($matches) > 2) {
                        $limit = $matches[3] ? (int) $matches[3] : null;
                    }

                    $this->schema[$name]=$type;

                    switch ($type) {
                        case 'varchar':
                        case 'char':
                        case 'tinytext':
                        case 'mediumtext':
                        case 'longtext':
                        case 'text':
                        case 'timestamp':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                        case 'set':
                        case 'enum':
                            $type = 'string';
                            break;
                        case 'tinyint':
                        case 'smallint':
                        case 'mediumint':
                        case 'int':
                        case 'bigint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }
                $comment = $field['comment'];
                $this->setProperty($name, $type, true, true, $comment);

            }
        }
    }

    /**
     * 自动生成获取器和修改器以及关联对象的属性信息
     * @param $class
     * @param $model
     */
    protected function getPropertiesFromMethods($class, $model)
    {
        $classRef = new \ReflectionClass($class);
        $methods  = $classRef->getMethods();

        foreach ($methods as $method) {

            if ($method->getDeclaringClass()->getName() == $classRef->getName()) {

                $methodName = $method->getName();
                if (Str::startsWith($methodName, 'get') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && 'getAttr' !== $methodName) {
                    //获取器
                    $name = Str::snake(substr($methodName, 3, -4));

                    if (!empty($name)) {
                        $type = $this->getReturnTypeFromDocBlock($method);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($methodName, 'set') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && 'setAttr' !== $methodName) {
                    //修改器
                    $name = Str::snake(substr($methodName, 3, -4));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($methodName, 'scope')) {
                    //查询范围
                    $name = Str::camel(substr($methodName, 5));

                    if (!empty($name)) {
                        $args = $this->getParameters($method);
                        array_shift($args);
                        $this->setMethod($name, "\\think\\db\\Query", $args);
                    }
                } elseif ($method->isPublic() && $method->getNumberOfRequiredParameters() == 0) {
                    //关联对象
                    try {
                        $return = $method->invoke($model);

                        if ($return instanceof Relation) {

                            $name = Str::snake($methodName);
                            if ($return instanceof HasOne || $return instanceof BelongsTo || $return instanceof MorphOne) {
                                $this->setProperty($name, "\\" . get_class($return->getModel()), true, null);
                            }

                            if ($return instanceof HasMany || $return instanceof HasManyThrough || $return instanceof BelongsToMany) {
                                $this->setProperty($name, "\\" . get_class($return->getModel()) . "[]", true, null);
                            }

                            if ($return instanceof MorphTo || $return instanceof MorphMany) {
                                $this->setProperty($name, "mixed", true, null);
                            }
                        }
                    } catch (\Exception $e) {
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
    }

    protected function getPropertiesFromDefault($class){
        $this->setMethod('find',basename($class).'|null',['mixed $data = null']);
        $this->setMethod('findOrEmpty',basename($class),['mixed data = null']);
        $this->setMethod('select',basename($class).'[]',['mixed data=  null']);
    }

    protected function clean($class){

        $reflection  = new \ReflectionClass($class);

        $filename = $reflection->getFileName();

        $contents = file_get_contents($filename);

        $originalDoc = $reflection->getDocComment();

        if($this->config['over_write_phpdoc']){
            if ($originalDoc) {
                $contents = str_replace(PHP_EOL.$originalDoc, '', $contents);
            }
        }
        if($this->config['write_schema']){
            $this->schema_exsit = strpos($contents,'$schema');
            if($this->schema_exsit ){
                if($this->config['over_write_schema']) $contents = preg_replace('/(\$schema[^;]+;)/i','$schema = [];',$contents,1);
            }
        }

        if (file_put_contents($filename, $contents)) {
            $this->output->info('Clean ' . $filename);
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {
        $reflection  = new \ReflectionClass($class);
        $namespace   = $reflection->getNamespaceName();
        $classname   = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $context     = new Context($namespace);
        $summary     = "Class {$classname}";

        $fqsenResolver      = new FqsenResolver();
        $tagFactory         = new StandardTagFactory($fqsenResolver);
        $descriptionFactory = new DescriptionFactory($tagFactory);
        $typeResolver       = new TypeResolver($fqsenResolver);

        $properties = [];
        $methods    = [];
        $tags       = [];

        try {
            //读取文件注释
            $phpdoc = DocBlockFactory::createInstance()->create($reflection, $context);

            $summary    = $phpdoc->getSummary();
            $properties = [];
            $methods    = [];
            $tags       = $phpdoc->getTags();
            foreach ($tags as $key => $tag) {
                if ($tag instanceof DocBlock\Tags\Property || $tag instanceof DocBlock\Tags\PropertyRead || $tag instanceof DocBlock\Tags\PropertyWrite) {
                    if ($this->overwrite && array_key_exists($tag->getVariableName(), $this->properties)) {
                        //覆盖原来的
                        unset($tags[$key]);
                    } else {
                        $properties[] = $tag->getVariableName();
                    }
                } elseif ($tag instanceof DocBlock\Tags\Method) {
                    if ($this->overwrite && array_key_exists($tag->getMethodName(), $this->methods)) {
                        //覆盖原来的
                        unset($tags[$key]);
                    } else {
                        $methods[] = $tag->getMethodName();
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {

        }

        foreach ($this->properties as $name => $property) {
            if (in_array($name, $properties)) {
                continue;
            }
            $name = "\${$name}";
            $body = trim("{$property['type']} {$name} {$property['comment']}");

            if ($property['read'] && $property['write']) {
                $tag = DocBlock\Tags\Property::create($body, $typeResolver, $descriptionFactory, $context);
            } elseif ($property['write']) {
                $tag = DocBlock\Tags\PropertyWrite::create($body, $typeResolver, $descriptionFactory, $context);
            } else {
                $tag = DocBlock\Tags\PropertyRead::create($body, $typeResolver, $descriptionFactory, $context);
            }

            $tags[] = $tag;
        }

        ksort($this->methods);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }

            $arguments = implode(', ', $method['arguments']);

            $tag    = DocBlock\Tags\Method::create("static {$method['type']} {$name}({$arguments})", $typeResolver, $descriptionFactory, $context);
            $tags[] = $tag;
        }


        $phpdoc = new DocBlock($summary, null, $tags, $context);

        $serializer = new DocBlockSerializer();

        $docComment = $serializer->getDocComment($phpdoc);

        $filename = $reflection->getFileName();

        $contents = file_get_contents($filename);
        if($this->config['over_write_phpdoc']){
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle  = "class {$classname}";
                $replace = "{$docComment}" . PHP_EOL . "class {$classname}";
                $pos     = strpos($contents, $needle);
                if (false !== $pos) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
        }
        if($this->config['write_schema']){
            $schema = $this->buildSchema();
            $this->schema_exsit = strpos($contents,'$schema');
            if($this->schema_exsit ){
                if($this->config['over_write_schema']) $contents = preg_replace('/(\$schema[^;]+;)/i',$schema,$contents,1);
            }else{
                $insert_pos = strpos($contents,'{');
                if(false!==$insert_pos){
                    $contents = substr_replace($contents, PHP_EOL."\t".'protected '.$schema, $insert_pos+1, 0);
                }
            }
        }

        if (file_put_contents($filename, $contents)) {
            $this->output->info('Update ' . $filename);
        }
    }

    protected function buildSchema(){
        $str = '$schema = ['.PHP_EOL;
        foreach ($this->schema as $k => $v){
            $str.="\t\t'$k' => '$v',".PHP_EOL;
        }
        $str.="\t];";
        return $str;
    }

    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name]            = [];
            $this->properties[$name]['type']    = 'mixed';
            $this->properties[$name]['read']    = false;
            $this->properties[$name]['write']   = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if (null !== $type) {
            $this->properties[$name]['type'] = $type;
        }
        if (null !== $read) {
            $this->properties[$name]['read'] = $read;
        }
        if (null !== $write) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = [])
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);
        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name]              = [];
            $this->methods[$name]['type']      = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection)
    {
        $type = null;
        try {
            $phpdoc = DocBlockFactory::createInstance()->create($reflection, new Context($reflection->getDeclaringClass()->getNamespaceName()));
            if ($phpdoc->hasTag('return')) {
                /** @var DocBlock\Tags\Return_ $returnTag */
                $returnTag = $phpdoc->getTagsByName('return')[0];
                $type      = $returnTag->getType();
                if ($type instanceof This || $type instanceof Static_ || $type instanceof Self_) {
                    $type = "\\" . $reflection->getDeclaringClass()->getName();
                }
            }
        } catch (\InvalidArgumentException $e) {

        }
        return is_null($type) ? null : (string) $type;
    }

    /**
     * @param ReflectionMethod $method
     * @return array
     */
    protected function getParameters($method)
    {
        //Loop through the default values for paremeters, and make the correct output string
        $params            = [];
        $paramsWithDefault = [];
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramClass = $param->getClass();
            $paramStr   = (!is_null($paramClass) ? '\\' . $paramClass->getName() . ' ' : '') . '$' . $param->getName();
            $params[]   = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    $default = $default;
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * 自动获取模型
     * @return array
     */
    protected function loadModels()
    {
        $models = [];
        foreach ($this->dirs as $dir) {
            if(!(strpos($dir,'\\') || strpos($dir,'/')))
                $dir = base_path($dir);
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }else{
                $this->output->warning('path '.$dir.' is not found!');
            }
        }
        return $models;
    }
}
