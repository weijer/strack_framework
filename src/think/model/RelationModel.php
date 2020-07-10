<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think\model;

use common\model\FieldModel;
use common\model\ModuleRelationModel;
use think\Model;
use think\Hook;
use think\Request;

/**
 * ThinkPHP关联模型扩展
 */
class RelationModel extends Model
{

    const HAS_ONE = 1;
    const BELONGS_TO = 2;
    const HAS_MANY = 3;
    const MANY_TO_MANY = 4;

    // 关联定义
    protected $_link = array();

    // 定义返回数据
    public $_resData = [];

    // 字段数据源映射源数据字段
    public $_fieldFromDataDict = [];

    // 远端一对多水平关联字段多个查询上一个查询方法
    protected $prevRemoteQueryMethod = '';

    // 远端一对多水平关联字段多个查询当前查询方法
    protected $currentRemoteQueryMethod = '';

    // 字段类型或者格式转换
    protected $type = [];

    // 是否是空值查询
    protected $isNullOrEmptyFilter = false;

    // 当前模块id
    protected $currentModuleId = 0;

    // 自定义字段配置
    protected $customFieldConfig = [];

    // 查询模块模型关联
    protected $queryModuleRelation = [];

    // 查询需要作join查询的模块
    protected $queryModuleLfetJoinRelation = [];

    /**
     * 动态方法实现
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (strtolower(substr($method, 0, 8)) == 'relation') {
            $type = strtoupper(substr($method, 8));
            if (in_array($type, array('ADD', 'SAVE', 'DEL'), true)) {
                array_unshift($args, $type);
                return call_user_func_array(array(&$this, 'opRelation'), $args);
            }
        } else {
            return parent::__call($method, $args);
        }
    }

    /**
     * 数据库Event log Hook
     */
    protected function databaseEventLogHook($param)
    {
        Hook::listen('event_log', $param);
    }

    /**
     * 得到关联的数据表名
     * @param $relation
     * @return string
     */
    public function getRelationTableName($relation)
    {
        $relationTable = !empty($this->tablePrefix) ? $this->tablePrefix : '';
        $relationTable .= $this->tableName ? $this->tableName : $this->name;
        $relationTable .= '_' . $relation->getModelName();
        return strtolower($relationTable);
    }

    /**
     * 查询成功后的回调方法
     * @param $result
     * @param $options
     */
    protected function _after_find(&$result, $options)
    {
        // 获取关联数据 并附加到结果中
        if (!empty($options['link'])) {
            $this->getRelation($result, $options['link']);
        }
    }

    /**
     * 查询数据集成功后的回调方法
     * @param $result
     * @param $options
     */
    protected function _after_select(&$result, $options)
    {
        // 获取关联数据 并附加到结果中
        if (!empty($options['link'])) {
            $this->getRelations($result, $options['link']);
        }

    }

    /**
     * 写入成功后的回调方法
     * @param $pk
     * @param $pkName
     * @param $data
     * @param $options
     */
    protected function _after_insert($pk, $pkName, $data, $options)
    {
        //写入事件日志
        if ($options["model"] != "EventLog") {
            $this->databaseEventLogHook([
                'operate' => 'create',
                'primary_id' => $pk,
                'primary_field' => $pkName,
                'data' => $data,
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联写入
        if (!empty($options['link'])) {
            $this->opRelation('ADD', $data, $options['link']);
        }
    }

    /**
     * 更新成功后的回调方法
     * @param $result
     * @param $pkName
     * @param $data
     * @param $options
     * @param $writeEvent
     */
    protected function _after_update($result, $pkName, $data, $options, $writeEvent)
    {
        //写入事件日志
        if ($result > 0 && $options["model"] != "EventLog" && $writeEvent) {
            $this->databaseEventLogHook([
                'operate' => 'update',
                'primary_id' => $this->oldUpdateKey,
                'primary_field' => $pkName,
                'data' => ["old" => $this->oldUpdateData, "new" => $this->newUpdateData],
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联更新
        if (!empty($options['link'])) {
            $this->opRelation('SAVE', $data, $options['link']);
        }

    }

    /**
     * 删除成功后的回调方法
     * @param $result
     * @param $pkName
     * @param $data
     * @param $options
     */
    protected function _after_delete($result, $pkName, $data, $options)
    {
        //写入事件日志
        if ($result > 0 && $options["model"] != "EventLog") {
            $this->databaseEventLogHook([
                'operate' => 'delete',
                'primary_id' => $this->oldDeleteKey,
                'primary_field' => $pkName,
                'data' => $this->oldDeleteData,
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联删除
        if (!empty($options['link'])) {
            $this->opRelation('DEL', $data, $options['link']);
        }

    }

    /**
     * 对保存到数据库的数据进行处理
     * @access protected
     * @param mixed $data 要操作的数据
     * @return boolean
     */
    protected function _facade($data)
    {
        $this->_before_write($data);
        return $data;
    }

    /**
     * 获取返回数据集的关联记录
     * @access protected
     * @param array $resultSet 返回数据
     * @param string|array $name 关联名称
     * @return array
     */
    protected function getRelations(&$resultSet, $name = '')
    {
        // 获取记录集的主键列表
        foreach ($resultSet as $key => $val) {
            $val = $this->getRelation($val, $name);
            $resultSet[$key] = $val;
        }
        return $resultSet;
    }

    /**
     * 获取返回数据的关联记录
     * @access protected
     * @param mixed $result 返回数据
     * @param string|array $name 关联名称
     * @param boolean $return 是否返回关联数据本身
     * @return array
     */
    protected function getRelation(&$result, $name = '', $return = false)
    {
        if (!empty($this->_link)) {
            foreach ($this->_link as $key => $val) {
                $mappingName = !empty($val['mapping_name']) ? $val['mapping_name'] : $key; // 映射名称
                if (empty($name) || true === $name || $mappingName == $name || (is_array($name) && in_array($mappingName, $name))) {
                    $mappingType = !empty($val['mapping_type']) ? $val['mapping_type'] : $val; //  关联类型
                    $mappingClass = !empty($val['class_name']) ? $val['class_name'] : $key; //  关联类名
                    $mappingFields = !empty($val['mapping_fields']) ? $val['mapping_fields'] : '*'; // 映射字段
                    $mappingCondition = !empty($val['condition']) ? $val['condition'] : '1=1'; // 关联条件
                    $mappingKey = !empty($val['mapping_key']) ? $val['mapping_key'] : $this->getPk(); // 关联键名
                    if (strtoupper($mappingClass) == strtoupper($this->name)) {
                        // 自引用关联 获取父键名
                        $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                    } else {
                        $mappingFk = !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($this->name) . '_id'; //  关联外键
                    }
                    // 获取关联模型对象
                    $model = D($mappingClass);
                    switch ($mappingType) {
                        case self::HAS_ONE:
                            $pk = $result[$mappingKey];
                            $mappingCondition .= " AND {$mappingFk}='{$pk}'";
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->find();
                            if (!empty($val['relation_deep'])) {
                                $model->getRelation($relationData, $val['relation_deep']);
                            }
                            break;
                        case self::BELONGS_TO:
                            if (strtoupper($mappingClass) == strtoupper($this->name)) {
                                // 自引用关联 获取父键名
                                $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                            } else {
                                $mappingFk =
                                    !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($model->getModelName()) . '_id'; //  关联外键
                            }
                            $fk = $result[$mappingFk];
                            $mappingCondition .= " AND {$model->getPk()}='{$fk}'";
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->find();
                            if (!empty($val['relation_deep'])) {
                                $model->getRelation($relationData, $val['relation_deep']);
                            }
                            break;
                        case self::HAS_MANY:
                            $pk = $result[$mappingKey];
                            $mappingCondition .= " AND {$mappingFk}='{$pk}'";
                            $mappingOrder = !empty($val['mapping_order']) ? $val['mapping_order'] : '';
                            $mappingLimit = !empty($val['mapping_limit']) ? $val['mapping_limit'] : '';
                            // 延时获取关联记录
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->order($mappingOrder)->limit($mappingLimit)->select();
                            if (!empty($val['relation_deep'])) {
                                foreach ($relationData as $key => $data) {
                                    $model->getRelation($data, $val['relation_deep']);
                                    $relationData[$key] = $data;
                                }
                            }
                            break;
                        case self::MANY_TO_MANY:
                            $pk = $result[$mappingKey];
                            $prefix = $this->tablePrefix;
                            $mappingCondition = " {$mappingFk}='{$pk}'";
                            $mappingOrder = $val['mapping_order'];
                            $mappingLimit = $val['mapping_limit'];
                            $mappingRelationFk = $val['relation_foreign_key'] ? $val['relation_foreign_key'] : $model->getModelName() . '_id';
                            if (isset($val['relation_table'])) {
                                $mappingRelationTable = preg_replace_callback("/__([A-Z_-]+)__/sU", function ($match) use ($prefix) {
                                    return $prefix . strtolower($match[1]);
                                }, $val['relation_table']);
                            } else {
                                $mappingRelationTable = $this->getRelationTableName($model);
                            }
                            $sql = "SELECT b.{$mappingFields} FROM {$mappingRelationTable} AS a, " . $model->getTableName() . " AS b WHERE a.{$mappingRelationFk} = b.{$model->getPk()} AND a.{$mappingCondition}";
                            if (!empty($val['condition'])) {
                                $sql .= ' AND ' . $val['condition'];
                            }
                            if (!empty($mappingOrder)) {
                                $sql .= ' ORDER BY ' . $mappingOrder;
                            }
                            if (!empty($mappingLimit)) {
                                $sql .= ' LIMIT ' . $mappingLimit;
                            }
                            $relationData = $this->query($sql);
                            if (!empty($val['relation_deep'])) {
                                foreach ($relationData as $key => $data) {
                                    $model->getRelation($data, $val['relation_deep']);
                                    $relationData[$key] = $data;
                                }
                            }
                            break;
                    }
                    if (!$return) {
                        if (isset($val['as_fields']) && in_array($mappingType, array(self::HAS_ONE, self::BELONGS_TO))) {
                            // 支持直接把关联的字段值映射成数据对象中的某个字段
                            // 仅仅支持HAS_ONE BELONGS_TO
                            $fields = explode(',', $val['as_fields']);
                            foreach ($fields as $field) {
                                if (strpos($field, ':')) {
                                    list($relationName, $nick) = explode(':', $field);
                                    $result[$nick] = $relationData[$relationName];
                                } else {
                                    $result[$field] = $relationData[$field];
                                }
                            }
                        } else {
                            $result[$mappingName] = $relationData;
                        }
                        unset($relationData);
                    } else {
                        return $relationData;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 操作关联数据
     * @access protected
     * @param string $opType 操作方式 ADD SAVE DEL
     * @param mixed $data 数据对象
     * @param string $name 关联名称
     * @return mixed
     */
    protected function opRelation($opType, $data = '', $name = '')
    {
        $result = false;
        if (empty($data) && !empty($this->data)) {
            $data = $this->data;
        } elseif (!is_array($data)) {
            // 数据无效返回
            return false;
        }
        if (!empty($this->_link)) {
            // 遍历关联定义
            foreach ($this->_link as $key => $val) {
                // 操作制定关联类型
                $mappingName = $val['mapping_name'] ? $val['mapping_name'] : $key; // 映射名称
                if (empty($name) || true === $name || $mappingName == $name || (is_array($name) && in_array($mappingName, $name))) {
                    // 操作制定的关联
                    $mappingType = !empty($val['mapping_type']) ? $val['mapping_type'] : $val; //  关联类型
                    $mappingClass = !empty($val['class_name']) ? $val['class_name'] : $key; //  关联类名
                    $mappingKey = !empty($val['mapping_key']) ? $val['mapping_key'] : $this->getPk(); // 关联键名
                    // 当前数据对象主键值
                    $pk = $data[$mappingKey];
                    if (strtoupper($mappingClass) == strtoupper($this->name)) {
                        // 自引用关联 获取父键名
                        $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                    } else {
                        $mappingFk = !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($this->name) . '_id'; //  关联外键
                    }
                    if (!empty($val['condition'])) {
                        $mappingCondition = $val['condition'];
                    } else {
                        $mappingCondition = array();
                        $mappingCondition[$mappingFk] = $pk;
                    }
                    // 获取关联model对象
                    $model = D($mappingClass);
                    $mappingData = isset($data[$mappingName]) ? $data[$mappingName] : false;
                    if (!empty($mappingData) || 'DEL' == $opType) {
                        switch ($mappingType) {
                            case self::HAS_ONE:
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        $mappingData[$mappingFk] = $pk;
                                        $result = $model->add($mappingData);
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        $result = $model->where($mappingCondition)->save($mappingData);
                                        break;
                                    case 'DEL': // 根据外键删除关联数据
                                        $result = $model->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                            case self::BELONGS_TO:
                                break;
                            case self::HAS_MANY:
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        $model->startTrans();
                                        foreach ($mappingData as $val) {
                                            $val[$mappingFk] = $pk;
                                            $result = $model->add($val);
                                        }
                                        $model->commit();
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        $model->startTrans();
                                        $pk = $model->getPk();
                                        foreach ($mappingData as $vo) {
                                            if (isset($vo[$pk])) {
                                                // 更新数据
                                                $mappingCondition = "$pk ={$vo[$pk]}";
                                                $result = $model->where($mappingCondition)->save($vo);
                                            } else {
                                                // 新增数据
                                                $vo[$mappingFk] = $data[$mappingKey];
                                                $result = $model->add($vo);
                                            }
                                        }
                                        $model->commit();
                                        break;
                                    case 'DEL': // 删除关联数据
                                        $result = $model->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                            case self::MANY_TO_MANY:
                                $mappingRelationFk = $val['relation_foreign_key'] ? $val['relation_foreign_key'] : $model->getModelName() . '_id'; // 关联
                                $prefix = $this->tablePrefix;
                                if (isset($val['relation_table'])) {
                                    $mappingRelationTable = preg_replace_callback("/__([A-Z_-]+)__/sU", function ($match) use ($prefix) {
                                        return $prefix . strtolower($match[1]);
                                    }, $val['relation_table']);
                                } else {
                                    $mappingRelationTable = $this->getRelationTableName($model);
                                }
                                if (is_array($mappingData)) {
                                    $ids = array();
                                    foreach ($mappingData as $vo) {
                                        $ids[] = $vo[$mappingKey];
                                    }

                                    $relationId = implode(',', $ids);
                                }
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        if (isset($relationId)) {
                                            $this->startTrans();
                                            // 插入关联表数据
                                            $sql = 'INSERT INTO ' . $mappingRelationTable . ' (' . $mappingFk . ',' . $mappingRelationFk . ') SELECT a.' . $this->getPk() . ',b.' . $model->getPk() . ' FROM ' . $this->getTableName() . ' AS a ,' . $model->getTableName() . " AS b where a." . $this->getPk() . ' =' . $pk . ' AND  b.' . $model->getPk() . ' IN (' . $relationId . ") ";
                                            $result = $model->execute($sql);
                                            if (false !== $result) // 提交事务
                                            {
                                                $this->commit();
                                            } else // 事务回滚
                                            {
                                                $this->rollback();
                                            }
                                        }
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        if (isset($relationId)) {
                                            $this->startTrans();
                                            // 删除关联表数据
                                            $this->table($mappingRelationTable)->where($mappingCondition)->delete();
                                            // 插入关联表数据
                                            $sql = 'INSERT INTO ' . $mappingRelationTable . ' (' . $mappingFk . ',' . $mappingRelationFk . ') SELECT a.' . $this->getPk() . ',b.' . $model->getPk() . ' FROM ' . $this->getTableName() . ' AS a ,' . $model->getTableName() . " AS b where a." . $this->getPk() . ' =' . $pk . ' AND  b.' . $model->getPk() . ' IN (' . $relationId . ") ";
                                            $result = $model->execute($sql);
                                            if (false !== $result) // 提交事务
                                            {
                                                $this->commit();
                                            } else // 事务回滚
                                            {
                                                $this->rollback();
                                            }

                                        }
                                        break;
                                    case 'DEL': // 根据外键删除中间表关联数据
                                        $result = $this->table($mappingRelationTable)->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                        }
                        if (!empty($val['relation_deep'])) {
                            $model->opRelation($opType, $mappingData, $val['relation_deep']);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 进行关联查询
     * @access public
     * @param mixed $name 关联名称
     * @return Model
     */
    public function relation($name)
    {
        $this->options['link'] = $name;
        return $this;
    }

    /**
     * 关联数据获取 仅用于查询后
     * @access public
     * @param string $name 关联名称
     * @return array
     */
    public function relationGet($name)
    {
        if (empty($this->data)) {
            return false;
        }

        return $this->getRelation($this->data, $name, true);
    }


    /**
     * 字符串命名风格转换
     * type 0 将Java风格转换为C的风格 1 将C风格转换为Java的风格
     * @param string $name 字符串
     * @param integer $type 转换类型
     * @param bool $ucfirst 首字母是否大写（驼峰规则）
     * @return string
     */
    public function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);
            return $ucfirst ? ucfirst($name) : lcfirst($name);
        } else {
            return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
        }
    }

    /**
     * 数据读取 类型转换
     * @access public
     * @param mixed $value 值
     * @param string|array $type 要转换的类型
     * @return mixed
     */
    protected function readTransform($value, $type)
    {
        if (is_array($type)) {
            list($type, $param) = $type;
        } elseif (strpos($type, ':')) {
            list($type, $param) = explode(':', $type, 2);
        }
        switch ($type) {
            case 'integer':
                $value = (int)$value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float)$value;
                } else {
                    $value = (float)number_format($value, $param);
                }
                break;
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'timestamp':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = date($format, $value);
                }
                break;
            case 'datetime':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = date($format, strtotime($value));
                }
                break;
            case 'json':
                $value = json_decode($value, true);
                break;
            case 'array':
                $value = is_null($value) ? [] : json_decode($value, true);
                break;
            case 'object':
                $value = empty($value) ? new \stdClass() : json_decode($value);
                break;
            case 'serialize':
                $value = unserialize($value);
                break;
        }
        return $value;
    }

    /**
     * 获取对象原始数据 如果不存在指定字段返回false
     * @access public
     * @param string $name 字段名 留空获取全部
     * @return mixed
     * @throws \Exception
     */
    public function getData($name = null)
    {
        if (is_null($name)) {
            return $this->data;
        } elseif (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        } else {
            StrackE('property not exists:' . $name);
        }
    }

    /**
     * 获取器 获取数据对象的值
     * @param $name
     * @return mixed
     */
    public function getAttr($name)
    {
        try {
            $value = $this->getData($name);
        } catch (\Exception $e) {
            $value = null;
        }

        // 检测属性获取器
        $method = 'get' . $this->parseName($name, 1) . 'Attr';
        if (method_exists($this, $method)) {
            $value = $this->$method($value, $this->data);
        } elseif (isset($this->type[$name])) {
            // 类型转换
            $value = $this->readTransform($value, $this->type[$name]);
        }

        return $value;
    }

    /**
     * 处理查询数据
     * @param $data
     * @return array
     */
    protected function handleQueryData($data)
    {
        $item = [];
        $this->data = !empty($data) ? $data : $this->data;
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $this->data = $val;
                $arr = [];
                foreach ($val as $k => $v) {
                    $arr[$k] = $this->getAttr($k);
                }
                $item[$key] = $arr;
            } else {
                $item[$key] = $this->getAttr($key);
            }
        }

        return !empty($item) ? $item : [];
    }

    /**
     * 处理返回数据
     * @param $data
     * @param bool $first
     * @return array
     */
    public function handleReturnData($first = true, $data = [])
    {
        $dealData = !empty($data) ? $data : $this->data;

        $this->data = $this->handleQueryData($dealData);

        if ($first && is_many_dimension_array($this->data)) {
            $item = [];
            foreach ($this->data as $value) {


                $this->data = $value;
                $item[] = $this->handleReturnData(false);
            }
            return $item;
        } else {
            //过滤属性
            if (!empty($this->visible)) {
                $data = array_intersect_key($this->data, array_flip($this->visible));
            } elseif (!empty($this->hidden)) {
                $data = array_diff_key($this->data, array_flip($this->hidden));
            } else {
                $data = $this->data;
            }

            // 追加属性自定义字段
            if (!empty($this->appendCustomField)) {
                foreach ($this->appendCustomField as $field => $value) {
                    $data[$field] = $value;
                }
            }

            // 追加属性（必须定义获取器）
            if (!empty($this->append)) {
                foreach ($this->append as $name) {
                    $data[$name] = $this->getAttr($name);
                }
            }
            return !empty($data) ? $data : [];
        }
    }


    /**
     * 新增数据，成功返回当前添加的一条完整数据
     * @param array $param 新增数据参数
     * @return array|bool|mixed
     */
    public function addItem($param = [])
    {
        $this->resetDefault();
        if ($this->create($param, self::MODEL_INSERT)) {
            $result = $this->add();
            if (!$result) {
                //新增失败
                return false;
            } else {
                //新增成功，返回当前添加的一条完整数据
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $result])->find();
                $this->successMsg = "Add {$this->name} items successfully.";
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            //数据验证失败，返回错误
            return false;
        }
    }

    /**
     * 修改数据，必须包含主键，成功返回当前修改的一条完整数据
     * @param array $param 修改数据参数
     * @return array|bool|mixed
     */
    public function modifyItem($param = [])
    {

        $this->resetDefault();
        if ($this->create($param, self::MODEL_UPDATE)) {
            $result = $this->save();
            if (!$result) {
                // 修改失败
                if ($result === 0) {
                    // 没有数据被更新
                    $this->error = 'No data has been changed.';
                    return false;
                } else {
                    return false;
                }
            } else {
                // 修改成功，返回当前修改的一条完整数据
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $param[$pk]])->find();
                $this->successMsg = "Modify {$this->name} items successfully.";
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            // 数据验证失败，返回错误
            return false;
        }
    }

    /**
     * 更新单个组件基础方法
     * @param $data
     * @return array|bool|mixed
     */
    public function updateWidget($data)
    {
        $this->resetDefault();
        if ($this->create($data, self::MODEL_UPDATE)) {
            $result = $this->save();
            if (!$result) {
                if ($result === 0) {
                    // 没有数据被更新
                    $this->error = 'No data has been changed.';
                    return false;
                } else {
                    return false;
                }
            } else {
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $data[$pk]])->find();
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            // 数据验证失败，返回错误
            return false;
        }
    }


    /**
     * 删除数据
     * @param array $param
     * @return mixed
     */
    public function deleteItem($param = [])
    {
        $this->resetDefault();
        $result = $this->where($param)->delete();
        if (!$result) {
            // 数据删除失败，返回错误
            if ($result == 0) {
                // 没有数据被删除
                $this->error = 'No data has been changed.';
                return false;
            } else {
                return false;
            }
        } else {
            // 删除成功，返回当前添加的一条完整数据
            $this->successMsg = "Delete {$this->name} items successfully.";
            return true;
        }
    }


    /**
     * 处理过滤条件数据结构
     * @param $result
     * @param $filter
     * @param $currentFilter
     * @param int $depth
     * @param int $index
     */
    private function parserFilterParam(&$result, $filter, $currentFilter, $depth = 0, $index = 1)
    {
        if ($depth > 0) {
            $currentDepth = count($currentFilter);
            $currentIndex = 1;

            // 数据索引
            $dictIndex = $depth - 1;

            foreach ($currentFilter as $key => $value) {

                if ($index !== ($depth - 1)) {
                    if (is_array($value) && is_many_dimension_array($value)) {
                        $index++;
                        $this->parserFilterParam($result, $filter, $value, $depth, $index);
                    }
                } else {

                    if ($key !== "_logic") {

                        if (!(is_array($value) && is_many_dimension_array($value))) {
                            // 把所有相关联模块存下来
                            if (strpos($key, '.') === false) {
                                throw_strack_exception('The field format must contain a dot symbol.', -400001);
                            }

                            $fieldsParam = explode('.', $key);
                            if (!in_array($fieldsParam[0], Request::$complexFilterRelatedModule)) {
                                Request::$complexFilterRelatedModule[] = $fieldsParam[0];
                            }

                            // 按模板分组存储字段信息
                            if (array_key_exists($dictIndex, $result)) {
                                if (!array_key_exists($fieldsParam[0], $result[$dictIndex])) {
                                    $result[$dictIndex][$fieldsParam[0]] = [
                                        $fieldsParam[1] => $value
                                    ];
                                } else {
                                    $result[$dictIndex][$fieldsParam[0]][$fieldsParam[1]] = $value;
                                }
                            } else {
                                $result[$dictIndex] = [
                                    $fieldsParam[0] => [$fieldsParam[1] => $value]
                                ];
                            }
                        }

                    } else {
                        // 逻辑关系
                        if (empty($result[$dictIndex]["_logic"])) {
                            if (array_key_exists($dictIndex, $result)) {
                                $result[$dictIndex][$key] = $value;
                            } else {
                                $result[$dictIndex] = [$key => $value];
                            }
                        }
                    }


                    if ($currentDepth === $currentIndex) {

                        // 循环到末尾往上遍历
                        if (!array_key_exists('_logic', $result[$dictIndex])) {
                            $result[$depth - 1]['_logic'] = 'AND';
                        }

                        $this->parserFilterParam($result, $filter, $filter, $depth - 1, 1);
                    }

                    $currentIndex++;
                }
            }
        }
    }

    /**
     * 递归处理实体entity路径
     * @param $result
     * @param $moduleCode
     * @param $moduleDictByDstModuleId
     * @param $moduleDictBySrcModuleId
     */
    private function recurrenceEntityChildHierarchy(&$result, $moduleCode, $moduleDictByDstModuleId, $moduleDictBySrcModuleId, $isChild = false)
    {
        $moduleData = Request::$moduleDictData['module_index_by_code'][$moduleCode];
        $masterModuleData = Request::$moduleDictData['module_index_by_code'][Request::$moduleDictData['current_module_code']];

        if ($moduleData['type'] === 'entity') {

            $result = [
                "belong_module" => $moduleData['code'],
                "relation_type" => "belong_to",
                "src_module_id" => $moduleData['id'],
                "dst_module_id" => $masterModuleData['id'],
                "link_id" => "entity_id,entity_module_id",
                "type" => "fixed",
                "module_code" => $masterModuleData['code'],
                "filter_type" => "entity"
            ];

            foreach ($moduleDictBySrcModuleId[$moduleData['id']] as $moduleDictSrcItem) {
                $dstModuleData = Request::$moduleDictData['module_index_by_id'][$moduleDictSrcItem['dst_module_id']];
                if ($dstModuleData['type'] === 'entity') {
                    $this->recurrenceEntityChildHierarchy($result['child'], $dstModuleData['code'], $moduleDictByDstModuleId, $moduleDictBySrcModuleId, $isChild = true);
                    continue;
                }
            }
        }
    }

    /**
     * 获取entity模块父子结构
     * @param $complexFilterRelatedModule
     * @param $moduleDictBySrcModuleId
     */
    private function getEntityParentChildHierarchy($complexFilterRelatedModule, $moduleDictByDstModuleId, $moduleDictBySrcModuleId)
    {
        $resultDict = [];
        foreach ($complexFilterRelatedModule as $moduleCode) {
            $result = [];
            $this->recurrenceEntityChildHierarchy($result, $moduleCode, $moduleDictByDstModuleId, $moduleDictBySrcModuleId);
            if (!empty($result)) {
                $resultDict[$moduleCode] = $result;
            }
        }
        return $resultDict;
    }

    /**
     * 递归处理过滤条件实体的链路关系
     * @param $data
     * @param $entityParentChildHierarchyData
     */
    private function recurrenceFilterModuleEntityRelation(&$data, $entityParentChildHierarchyData)
    {
        $moduleItem = [];
        foreach ($entityParentChildHierarchyData as $key => $entityParentChildHierarchyItem) {
            if ($key !== 'child') {
                $moduleItem[$key] = $entityParentChildHierarchyItem;
            }
        }

        $data = $moduleItem;
        if (array_key_exists('child', $entityParentChildHierarchyData) && !empty($entityParentChildHierarchyData['child'])) {
            $this->recurrenceFilterModuleEntityRelation($data['child'], $entityParentChildHierarchyData['child']);
        }
    }

    /**
     * 递归处理过滤条件的链路关系
     * @param $filterModuleLinkRelation
     * @param $moduleCode
     * @param $horizontalModuleList
     * @param $moduleDictBySrcModuleId
     * @param $moduleDictByDstModuleId
     * @param $entityParentChildHierarchyData
     */
    private function recurrenceFilterModuleRelation(&$filterModuleLinkRelation, $moduleCode, $horizontalModuleList, $moduleDictBySrcModuleId, $moduleDictByDstModuleId, $entityParentChildHierarchyData)
    {
        // 对于实体和任务特殊关系每层实体下面都可以挂任务
        $moduleData = Request::$moduleDictData['module_index_by_code'][$moduleCode];

        if (in_array($moduleData['code'], $horizontalModuleList)) {
            // 判断是否是水平自定义关联模块
            $moduleDictByDstModuleId[$moduleData['id']][0]['filter_type'] = 'direct';
            $filterModuleLinkRelation[$moduleData['code']] = $moduleDictByDstModuleId[$moduleData['id']][0];
        } else {
            if ($moduleData['type'] === 'entity') {
                // 实体类型 如果 对方是任务模块需要独立处理，因为每个实体下面都有任务
                foreach ($moduleDictBySrcModuleId[$moduleData['id']] as $relationModuleItem) {
                    if (Request::$moduleDictData['module_index_by_code'][Request::$moduleDictData['current_module_code']]['id'] === $relationModuleItem['dst_module_id']) {
                        $filterModuleLinkEmtityTemp = [];
                        $this->recurrenceFilterModuleEntityRelation($filterModuleLinkEmtityTemp, $entityParentChildHierarchyData[$moduleData['code']]);
                        $filterModuleLinkRelation[$moduleData['code']] = $filterModuleLinkEmtityTemp;
                    }
                }
            } else {
                if ($moduleData['code'] !== Request::$moduleDictData['current_module_code']) {

                    // 不是当前自己模块
                    foreach ($moduleDictByDstModuleId[$moduleData['id']] as $relationModuleItem) {
                        if ($relationModuleItem['dst_module_id'] === $moduleData['id']) {
                            $relationModuleItem['filter_type'] = 'direct';
                            $filterModuleLinkRelation[$moduleData['code']] = $relationModuleItem;
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取过滤条件的模块关联关系
     * @return array
     */
    private function parserFilterModuleRelation()
    {
        if (!empty($this->queryModuleRelation)) {
            return $this->queryModuleRelation;
        }

        // 获取所有关联模块
        $moduleRelationModel = new ModuleRelationModel();
        $moduleRelationData = $moduleRelationModel->field('id,type as relation_type,src_module_id,dst_module_id,link_id')->select();

        // 当前模块的水平关联自定义字段
        $fieldModel = new FieldModel();
        $horizontalFieldData = $fieldModel->field('id,table,config')
            ->where([
                'type' => 'custom',
                'is_horizontal' => 1,
                'module_id' => Request::$moduleDictData['module_index_by_code'][Request::$moduleDictData['current_module_code']]['id']
            ])
            ->select();

        // 获取任务与当前模块的关系
        $moduleDictByDstModuleId = [];
        $horizontalModuleList = [];

        if (!empty($horizontalFieldData)) {
            foreach ($horizontalFieldData as $horizontalFieldItem) {

                // 当前水平关联自定义字段配置
                $horizontalFieldItemConfig = json_decode($horizontalFieldItem['config'], true);

                // 判断当前查询关联模块是存在
                $dstModuleData = Request::$moduleDictData['module_index_by_id'][$horizontalFieldItemConfig['data_source']['dst_module_id']];

                $moduleDictByDstModuleId[$horizontalFieldItemConfig['data_source']['dst_module_id']][] = [
                    'type' => 'horizontal', // 自定义关系
                    'module_code' => $dstModuleData['code'],
                    'src_module_id' => $horizontalFieldItemConfig['data_source']['src_module_id'],
                    'dst_module_id' => $horizontalFieldItemConfig['data_source']['dst_module_id'],
                    'relation_type' => $horizontalFieldItemConfig['data_source']['relation_type'],
                    'link_id' => $horizontalFieldItemConfig['field']
                ];

                $horizontalModuleList[] = $dstModuleData['code'];
            }
        }

        // 涉及的固定字段模块关系  按照 src_module_id dst_module_id 索引
        $moduleDictBySrcModuleId = [];

        foreach ($moduleRelationData as $moduleRelationItem) {
            $moduleRelationItem['type'] = 'fixed';
            $moduleRelationData = Request::$moduleDictData['module_index_by_id'][$moduleRelationItem['dst_module_id']];
            $moduleRelationItem['module_code'] = $moduleRelationData['code'];
            $moduleDictByDstModuleId[$moduleRelationItem['dst_module_id']][] = $moduleRelationItem;
            $moduleDictBySrcModuleId[$moduleRelationItem['src_module_id']][] = $moduleRelationItem;
        }


        // 获取entity链路关系
        $entityParentChildHierarchyData = $this->getEntityParentChildHierarchy(Request::$complexFilterRelatedModule, $moduleDictByDstModuleId, $moduleDictBySrcModuleId);

        // 递归处理过滤条件的链路关系
        $filterModuleLinkRelation = [];
        foreach (Request::$complexFilterRelatedModule as $moduleCode) {
            $this->recurrenceFilterModuleRelation($filterModuleLinkRelation, $moduleCode, $horizontalModuleList, $moduleDictBySrcModuleId, $moduleDictByDstModuleId, $entityParentChildHierarchyData);
        }


        $this->queryModuleRelation = $filterModuleLinkRelation;

        return $filterModuleLinkRelation;
    }

    /**
     * 格式化过滤条件
     * @param $filter
     * @return array
     */
    private function formatFilterCondition($filter)
    {
        foreach ($filter as &$condition) {
            switch (strtolower($condition[0])) {
                case 'like':
                    $condition[1] = "%{$condition[1]}%";
                    break;
            }
        }
        return $filter;
    }

    /**
     * 处理过滤条件复杂值
     * @param $masterModuleCode
     * @param $itemModule
     * @param $selectData
     * @param $idsString
     * @return array
     */
    private function parserFilterItemComplexValue($masterModuleCode, $itemModule, $selectData, $idsString, $isComplex = true)
    {
        $filterData = [];
        if (strpos($itemModule['link_id'], ',') !== false) {
            $linkIds = explode(',', $itemModule['link_id']);
            $filterItem = [];
            foreach ($linkIds as $linkIdKey) {
                if (strpos($linkIdKey, '_id')) {
                    if (!empty($selectData)) {
                        $filterItem["{$masterModuleCode}.{$linkIdKey}"] = ["IN", $idsString];
                    } else {
                        $filterItem["{$masterModuleCode}.{$linkIdKey}"] = 0;
                    }
                }

                if (strpos($linkIdKey, '_module_id')) {
                    $filterItem["{$masterModuleCode}.{$linkIdKey}"] = $itemModule['src_module_id'];
                }
            }

            if ($isComplex) {
                $filterData['_complex'] = $filterItem;
            } else {
                $filterData = $filterItem;
            }

        } else {
            if (!empty($selectData)) {
                $filterData["{$masterModuleCode}.{$itemModule['link_id']}"] = ["IN", $idsString];
            } else {
                $filterData["{$masterModuleCode}.{$itemModule['link_id']}"] = 0;
            }
        }

        return $filterData;
    }

    /**
     * 预处理实体任务过滤关联
     * @param $filterData
     * @param $masterModuleCode
     * @param $itemModule
     * @param $filter
     */
    private function parserFilterItemEntityTaskRelated(&$filterData, $masterModuleCode, $itemModule, $filter)
    {
        $class = '\\common\\model\\EntityModel';
        $selectData = (new $class())->where($this->formatFilterCondition($filter))->select();
        if (!empty($selectData)) {
            $ids = array_column($selectData, 'id');
            $idsString = join(',', $ids);
            $filterItemData = $this->parserFilterItemComplexValue($masterModuleCode, $itemModule, $selectData, $idsString, false);
            if (empty($filterData)) {
                $filterData = $filterItemData;
            } else {
                $newfilterData = [];
                $newfilterData[] = $filterData;
                $newfilterData[] = $filterItemData;
                $newfilterData['_logic'] = 'OR';
                $filterData = $newfilterData;
            }

            if (array_key_exists('child', $itemModule)) {
                $entityFilter = [];
                foreach ($filterItemData as $filed => $value) {
                    $filedKey = explode('.', $filed)[1];
                    $entityFilter[$filedKey] = $value;
                }

                $this->parserFilterItemEntityTaskRelated($filterData, $masterModuleCode, $itemModule['child'], $entityFilter);
            }
        } else {
            if (empty($filterData)) {
                $filterData = $this->parserFilterItemComplexValue($masterModuleCode, $itemModule, $selectData, 0, false);
            }
        }

        return $filterData;
    }

    /**
     * 预处理过滤条件项的值
     * @param $masterModuleCode
     * @param $itemModule
     * @param $filter
     * @return array
     */
    private function parserFilterItemValue($masterModuleCode, $itemModule, $filter)
    {
        $filterData = [];
        switch ($itemModule['filter_type']) {
            case 'master':
                // 主键查询只需要加上字段别名
                foreach ($filter as $field => $condition) {
                    $filterData["{$masterModuleCode}.{$field}"] = $condition;
                }
                break;
            case 'direct':
                $class = '\\common\\model\\' . string_initial_letter($itemModule['module_code']) . 'Model';
                $selectData = (new $class())->where($this->formatFilterCondition($filter))->select();
                if (!empty($selectData)) {
                    $ids = array_column($selectData, 'id');
                    $idsString = join(',', $ids);
                } else {
                    $idsString = 'null';
                }

                if ($itemModule['type'] === 'horizontal') {
                    // 水平关联为自定义字段
                    $filterData['_string'] = "JSON_CONTAINS(json_extract({$masterModuleCode}.json, '$.{$itemModule['link_id']}' ), '[{$idsString}]' )";
                } else {
                    // 普通直接查询条件
                    $filterData = $this->parserFilterItemComplexValue($masterModuleCode, $itemModule, $selectData, $idsString);
                }
                break;
            case 'entity':
                // 只有用entity查询task时候需要特殊处理
                if ($masterModuleCode === 'task') {
                    // 得到各个层级的id
                    $filterEntityTaskData = [];
                    $filterData['_complex'] = $this->parserFilterItemEntityTaskRelated($filterEntityTaskData, $masterModuleCode, $itemModule, $filter);
                } else {
                    $class = '\\common\\model\\' . string_initial_letter($itemModule['module_code']) . 'Model';
                    $selectData = (new $class())->where($this->formatFilterCondition($filter))->select();
                    if (!empty($selectData)) {
                        $ids = array_column($selectData, 'id');
                        $idsString = join(',', $ids);
                    } else {
                        $idsString = 'null';
                    }

                    $filterData = $this->parserFilterItemComplexValue($masterModuleCode, $itemModule, $selectData, $idsString);
                }
                break;
        }

        return $filterData;
    }

    /**
     * 预处理过滤条件分组项的值
     * @param $filterGroupItem
     * @param $count
     * @param $filterModuleLinkRelation
     */
    private function parserFilterGroupValue($filterGroupItem, $count, $filterModuleLinkRelation)
    {
        // 一个一个执行
        $filter = [];
        for ($index = $count; $index > 0; $index--) {
            $filterTemp = [];
            foreach ($filterGroupItem[$index] as $key => $filterItem) {
                if ($key !== '_logic') {
                    if ($key === Request::$moduleDictData['current_module_code']) {
                        // 当前模块
                        $filterTempItem = $this->parserFilterItemValue(Request::$moduleDictData['current_module_code'], [
                            "type" => "",
                            "module_code" => Request::$moduleDictData['current_module_code'],
                            "relation_type" => "",
                            "src_module_id" => Request::$moduleDictData['module_index_by_code'][Request::$moduleDictData['current_module_code']]['id'],
                            "dst_module_id" => 0,
                            "link_id" => "id",
                            "filter_type" => "master"
                        ], $filterItem);
                    } else {
                        $filterTempItem = $this->parserFilterItemValue(Request::$moduleDictData['current_module_code'], $filterModuleLinkRelation[$key], $filterItem);
                    }

                    if (array_key_exists('_complex', $filterTempItem)) {
                        $filterTemp['_complex'] = $filterTempItem['_complex'];
                    } else if (array_key_exists('_string', $filterTempItem)) {
                        $filterTemp['_string'] = $filterTempItem['_string'];
                    } else {
                        $filterTemp[] = $filterTempItem;
                    }
                }
            }
            if (array_key_exists('_logic', $filterGroupItem[$index])) {
                $logic = $filterGroupItem[$index]['_logic'];
            } else {
                $logic = 'AND';
            }

            if (empty($filter)) {
                $filterTemp['_logic'] = $logic;
                $filter = $filterTemp;
            } else {
                $filterMid = $filter;
                $filter = [];
                $filter[] = $filterMid;
                $filter[] = $filterTemp;
                $filter['_logic'] = $logic;
            }
        }

        return $filter;
    }

    /**
     * 解析字段模块
     * @param $fields
     */
    private function parserFieldModule($fields)
    {
        $fieldsArr = explode(',', $fields);
        foreach ($fieldsArr as $fieldsItem) {
            if (strpos($fieldsItem, '.')) {
                $fieldsParam = explode('.', $fieldsItem);
                if (!in_array($fieldsParam[0], Request::$complexFilterRelatedModule)) {
                    Request::$complexFilterRelatedModule[] = $fieldsParam[0];
                }
            }
        }
    }

    /**
     * 预处理过滤条件值
     * @param $pretreatmentFilter
     * @param $filterReverse
     * @param $filterModuleLinkRelation
     */
    private function parserFilterValue(&$pretreatmentFilter, $filterReverse, $filterModuleLinkRelation)
    {
        $filter = [];
        foreach ($filterReverse as $key => $filterGroupItem) {
            if ($key !== '_logic') {
                $count = count($filterGroupItem);
                $filter[] = $this->parserFilterGroupValue($filterGroupItem, $count, $filterModuleLinkRelation);
            }
        }

        if (array_key_exists('_logic', $filterReverse)) {
            $filter['_logic'] = $filterReverse['_logic'];
        } else {
            $filter['_logic'] = 'AND';
        }
    }

    /**
     * 处理过滤条件
     * @param $filter
     * @return array
     */
    private function buildFilter($filter, $fields)
    {
        if (Request::$isComplexFilter) {
            // 复杂过滤条件处理
            $filterReverse = [];

            foreach ($filter as $filterKey => $filterVal) {
                if (is_array($filterVal)) {
                    $depth = array_depth($filterVal);
                    $filterReverseItem = [];
                    $this->parserFilterParam($filterReverseItem, $filterVal, $filterVal, $depth);
                    $filterReverse[] = $filterReverseItem;
                }
            }

            // 处理查询字段
            if (!empty($fields)) {
                $this->parserFieldModule($fields);
            }

            if (array_key_exists('_logic', $filter)) {
                $filterReverse['_logic'] = $filter['_logic'];
            } else {
                $filterReverse['_logic'] = 'AND';
            }

            // 处理所有 module relation 链路数据
            $filterModuleLinkRelation = $this->parserFilterModuleRelation();

            // 预处理过滤条件值
            $pretreatmentFilter = [];
            $this->parserFilterValue($pretreatmentFilter, $filterReverse, $filterModuleLinkRelation);

            return $pretreatmentFilter;
        }

        return $filter;
    }

    /**
     * 构建查询字段
     * @param $field
     * @return array
     */
    public function buildFields($field)
    {
        // 处理所有 module relation 链路数据
        $filterModuleLinkRelation = $this->parserFilterModuleRelation();

        $fieldsArr = explode(',', $field);
        $masterModuleCode = Request::$moduleDictData['current_module_code'];

        $newFields = [];
        if (strpos($field, '.') !== false) {
            foreach ($fieldsArr as $fieldItem) {
                // 找的可以belong_to的字段
                $moduleArray = explode('.', $fieldItem);
                if ($masterModuleCode !== $moduleArray[0]) {
                    if ($filterModuleLinkRelation[$moduleArray[0]]['filter_type'] === "direct" &&
                        $filterModuleLinkRelation[$moduleArray[0]]['relation_type'] === "belong_to") {
                        $newFields[] = "{$fieldItem} as {$moduleArray[0]}_{$moduleArray[1]}";
                        $this->queryModuleLfetJoinRelation[$moduleArray[0]] = $filterModuleLinkRelation[$moduleArray[0]];
                    }
                } else {
                    $newFields[] = $fieldItem;
                }
            }
        } else if (Request::$isComplexFilter) {
            // 仅查询主表字段，但需要复杂查询
            foreach ($fieldsArr as $fieldItem) {
                $newFields[] = "{$masterModuleCode}.{$fieldItem}";
            }
        }

        return join(',', $newFields);
    }


    /**
     * 获取一条数据
     * @param array $options
     * @param bool $needFormat
     * @return array|mixed
     */
    public function findData($options = [], $needFormat = true)
    {
        if (array_key_exists("fields", $options)) {
            // 有字段参数
            $this->field($options["fields"]);
        }

        if (array_key_exists("filter", $options)) {
            //有过滤条件
            $this->where($options["filter"]);
        }

        $findData = $this->find();

        if (empty($findData)) {
            $this->error = 'Data does not exist.';
            return [];
        }

        // 数据格式化
        if ($needFormat) {
            return $this->handleReturnData(false, $findData);
        } else {
            return $findData;
        }
    }


    /**
     * 获取多条数据
     * @param array $options
     * @param bool $needFormat
     * @return array
     */
    public function selectData($options = [], $needFormat = true)
    {
        if (Request::$isComplexFilter) {
            $this->alias('task');
            $masterModuleCode = Request::$moduleDictData['current_module_code'];
        }

        $filter = [];
        if (array_key_exists("filter", $options)) {
            // 有过滤条件
            $filter = $this->buildFilter($options["filter"], $options["fields"]);
            $this->where($filter);
        }

        // 统计个数
        $total = $this->count();

        // 获取数据
        if ($total > 0) {

            if (array_key_exists("fields", $options)) {
                // 有字段参数
                $this->field($this->buildFields($options["fields"]));
            }

            if (!empty($this->queryModuleLfetJoinRelation)) {
                // left join
                foreach ($this->queryModuleLfetJoinRelation as $joinMoudleCode => $joinItem) {
                    $linkIds = explode(',', $joinItem['link_id']);

                    $queryJoin = [
                        'type' => 'one',
                        'condition' => []
                    ];

                    foreach ($linkIds as $linkId) {
                        if (strpos($linkId, 'module_id') !== false) {
                            if ($masterModuleCode === 'task') {
                                $queryJoin['condition'][] = "{$joinItem['module_code']}.id = {$masterModuleCode}.entity_module_id";
                            } else {
                                $queryJoin['condition'][] = "{$joinItem['module_code']}.id = {$masterModuleCode}.{$linkId}";
                            }
                        } else {
                            if ($linkId) {
                                if (in_array($linkId, ['assignee', 'executor', 'created_by'])) {
                                    // 需要分为多个join
                                    $queryJoin['type'] = 'multiple';
                                }
                                $queryJoin['condition'][] = "{$joinItem['module_code']}.id = {$masterModuleCode}.{$linkId}";
                            }
                        }
                    }

                    if ($queryJoin['type'] === 'one') {
                        $conditionString = join('AND', $queryJoin['condition']);
                        substr($conditionString, 0, -strlen('AND'));
                        $this->join("LEFT JOIN {$joinItem['module_code']} ON {$conditionString}");
                    } else {
                        foreach ($queryJoin['condition'] as $conditionItem) {
                            $this->join("LEFT JOIN {$joinItem['module_code']} ON {$conditionItem}");
                        }
                    }
                }
            }

            if (array_key_exists("filter", $options)) {
                // 有过滤条件
                $this->where($filter);
            }

            if (array_key_exists("page", $options)) {
                // 有分页参数
                $pageSize = $options["page"][1] > C("DB_MAX_SELECT_ROWS") ? C("DB_MAX_SELECT_ROWS") : $options["page"][1];
                $this->page($options["page"][0], $pageSize);
            } else {
                if (array_key_exists("limit", $options) && $options["limit"] <= C("DB_MAX_SELECT_ROWS")) {
                    // 有limit参数
                    $this->limit($options["limit"]);
                } else {
                    $this->limit(C("DB_MAX_SELECT_ROWS"));
                }
            }

            if (array_key_exists("order", $options)) {
                // 有order参数
                $this->order($options["order"]);
            }

            $selectData = $this->select();

        } else {
            $selectData = [];
        }

        if (empty($selectData)) {
            $this->error = 'Data does not exist.';
            return ["total" => 0, "rows" => []];
        }

        // 数据格式化
        if ($needFormat) {
            foreach ($selectData as &$selectItem) {
                $selectItem = $this->handleReturnData(false, $selectItem);
            }
            return ["total" => $total, "rows" => $selectData];
        } else {
            return ["total" => $total, "rows" => $selectData];
        }
    }


    /**
     * 获取字段数据源映射
     */
    private
    function getFieldFromDataDict()
    {
        // 用户数据映射
        $allUserData = M("User")->field("id,name")->select();
        $allUserDataMap = array_column($allUserData, null, "id");
        $this->_fieldFromDataDict["user"] = $allUserDataMap;

        // 模块数据映射
        $allModuleData = M("Module")->field("id,name,code,type")->select();
        $moduleMapData = [];
        $moduleCodeMapData = [];
        foreach ($allModuleData as $allModuleDataItem) {
            $moduleMapData[$allModuleDataItem["id"]] = $allModuleDataItem;
            $moduleCodeMapData[$allModuleDataItem["code"]] = $allModuleDataItem;
        }

        $this->_fieldFromDataDict["module"] = $moduleMapData;
        $this->_fieldFromDataDict["module_code"] = $moduleCodeMapData;;
    }

    /**
     * 关联模型查询
     * @param array $param
     * @param string $formatMode
     * @return array
     */
    public
    function getRelationData($param = [])
    {

    }

    /**
     * 生成排序规则
     * @param $sortRule
     * @param $groupRule
     * @return string
     */
    private
    function buildSortRule($sortRule, $groupRule = [])
    {

    }

    /**
     * 生成最终过滤条件
     * @param $request
     * @param $other
     * @return array
     */
    private
    function buildFinalFilter($request, $other)
    {

    }

    /**
     * 生成控件过滤条件
     * @param $item
     * @return array
     */
    public
    function buildWidgetFilter($item)
    {
        switch ($item["editor"]) {
            case "text":
            case "textarea":
                switch ($item["condition"]) {
                    case "LIKE":
                    case "NOTLIKE":
                        $value = "%" . $item["value"] . "%";
                        break;
                    default:
                        $value = $item["value"];
                        break;
                }
                $filter = [$item["condition"], $value];
                break;
            case "combobox":
            case "tagbox":
            case "horizontal_relationship":
            case "checkbox":
                //$filter = [$item["condition"], $item["value"]];
                $filter = $this->checkFilterValWeatherNullOrEmpty($item["condition"], $item["value"]);
                break;
            case "datebox":
            case "datetimebox":
                switch ($item["condition"]) {
                    case "BETWEEN":
                        $dateBetween = explode(",", $item["value"]);
                        $filter = [$item["condition"], [strtotime($dateBetween[0]), strtotime($dateBetween[1])]];
                        break;
                    default:
                        $filter = [$item["condition"], strtotime($item["value"])];
                        break;
                }
                break;
            default:
                $filter = [];
                break;
        }
        return $filter;
    }
}