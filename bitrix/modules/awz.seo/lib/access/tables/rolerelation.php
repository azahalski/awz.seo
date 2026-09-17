<?php
namespace Awz\Seo\Access\Tables;

use Bitrix\Main\Access\Role\AccessRoleRelationTable;

class RoleRelationTable extends AccessRoleRelationTable
{
    public static function getTableName()
    {
        return 'awz_seo_role_relation';
    }

}