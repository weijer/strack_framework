<?php

namespace think;

use think\model\RelationModel;
use Casbin\Persist\Adapter as AdapterContract;
use Casbin\Persist\AdapterHelper;


class CasbinAdapter implements AdapterContract
{
    use AdapterHelper;

    // rule model 对象
    protected $casbinRule;

    // 过滤条件
    protected $filter = [];

    //
    protected $effectField = 'v3';

    // 权限黑名单
    protected $ruleBlackList = [];

    /**
     * CasbinAdapter constructor.
     * @param RelationModel $casbinRule
     */
    public function __construct(RelationModel $casbinRule)
    {
        $this->casbinRule = $casbinRule;
    }

    /**
     * 加载当前用户相关的所有策略规则
     * @param \Casbin\Model\Model $model
     * @return mixed|void
     */
    public function loadPolicy($model)
    {
        $rows = $this->casbinRule
            ->field('id,ptype,v0,v1,v2,v3,v4,v5')
            ->where($this->filter)
            ->select();

        foreach ($rows as $row) {
            if (in_array($row['id'], $this->ruleBlackList)) {
                // 判断是否在黑名单里面
                $row[$this->effectField] = 'deny';
            }
            $line = implode(', ', array_slice(array_values($row), 1));
            $this->loadPolicyLine(trim($line), $model);
        }
    }

    /**
     * 添加一行策略规则
     * @param $ptype
     * @param array $rule
     * @return array
     */
    public function savePolicyLine($ptype, array $rule)
    {
        $col['ptype'] = $ptype;
        foreach ($rule as $key => $value) {
            $col['v' . strval($key) . ''] = $value;
        }
        $resData = $this->casbinRule->addItem($col);

        if (!$resData) {
            // 添加失败错误码
            throw_strack_exception($this->casbinRule->getError(), -404012);
        } else {
            // 返回成功数据
            return success_response($this->casbinRule->getSuccessMassege(), $resData);
        }
    }

    /**
     * 将所有策略规则保存到存储中
     * @param \Casbin\Model\Model $model
     * @return bool
     */
    public function savePolicy($model)
    {
        foreach ($model->model['p'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        foreach ($model->model['g'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        return true;
    }

    /**
     * 向存储中添加策略规则
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     * @return array|mixed
     */
    public function addPolicy($sec, $ptype, $rule)
    {
        return $this->savePolicyLine($ptype, $rule);
    }

    /**
     * 从存储中删除策略规则
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     * @return mixed
     */
    public function removePolicy($sec, $ptype, $rule)
    {
        $result = $this->casbinRule->where(['ptype' => $ptype]);

        foreach ($rule as $key => $value) {
            $result->where(['v' . strval($key) => $value]);
        }

        return $result->delete();
    }

    /**
     * 从存储中删除匹配筛选器的策略规则
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param mixed ...$fieldValues
     * @return int|mixed
     */
    public function removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues)
    {
        $count = 0;

        $instance = $this->casbinRule->where(['ptype' => $ptype]);
        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ('' != $fieldValues[$value - $fieldIndex]) {
                    $instance->where(['v' . strval($value) => $fieldValues[$value - $fieldIndex]]);
                }
            }
        }

        foreach ($instance->select() as $model) {
            if ($model->delete()) {
                ++$count;
            }
        }

        return $count;
    }
}
