<?php

namespace think\model;

use think\Model;

class CasbinModel extends Model
{
    public function __construct($data = [])
    {
        $casbinConfig = C('Casbin');
        if (!empty($casbinConfig)) {
            $this->connection = $casbinConfig['database']['connection'];
            $this->tableName = $casbinConfig['database']['casbin_rules_table'];
            $this->trueTableName = $casbinConfig['database']['casbin_rules_name'];
        }
        parent::__construct($data);
    }
}
