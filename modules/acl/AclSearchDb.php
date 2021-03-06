<?php
namespace Jan_Acl;

use Jan_Category\CategorySearchDb
;

class AclSearchDb
{
    protected $db;

    public function __construct(\Db $db)
    {
        $this->db = $db;
    }

    /**
     * Get Categories's ACL
     * @param $ids
     * @param bool $combined
     * @return array
     */
    public function getCategories($ids, $combined = true)
    {
        $result = [];

        if (is_string($ids)) {
            $ids = explode(',', str_replace(' ', '', $ids));
        }

        // Select ACL + inherited cat + Cat fields
        $stmt = $this->db->prepare('
            SELECT c_acl.*,
                IF (c_acl.category_id = ?, NULL, inherited.id) AS inherited_id,
                IF (c_acl.category_id = ?, NULL, inherited.name) AS inherited_name,
                IF (c_acl.action_filter_criteria = "category_id", cat.name, NULL) AS action_filter_value_label
            FROM category_acl AS c_acl
            LEFT JOIN category AS inherited ON inherited.id = c_acl.category_id
            LEFT JOIN category AS cat ON cat.id = c_acl.action_filter_value
            WHERE
                c_acl.category_id = ?
            ORDER BY module, action, action_filter_criteria, action_filter_value_label
        ');

        // foreach is simpler to bind $id to inherited
        foreach ($ids as $id) {
            $stmt->execute([$id, $id, $id]);
            $result = array_merge($result, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        return $result;

    }

    public function getCategory($id) {
        // Prepend parents
        $categorySearch = new CategorySearchDb($this->db);
        $category_ids = array_merge($categorySearch->getParentsIds($id), [$id]);

        return $this->getCategories($category_ids);
    }

    public function saveCategory($category_id, array $acl)
    {
        $ret = true;
        foreach ($acl as $permission) {
            if ($permission['inherited_id']) continue;
            unset($permission['inherited_id'], $permission['inherited_name'], $permission['action_filter_value_label']);
            if (empty($permission['id'])) unset($permission['id']);

            $permission['category_id'] = $category_id;

            // Set empty values to NULL
            foreach ($permission as $key => $value) if (($key != 'allow') && !$value) $permission[$key] = null;

            if (!$this->db->add('category_acl', $permission)) $ret = false; // INSERT
        }
        return $ret;
    }

    public function delCategory($id)
    {
        $this->db->deleteFrom('category_acl')->where('category_id', $id)->prepareExec();
    }


    /**
     * Get User Categories's ACL
     * A user may belong to multiple categories
     * @param $user_id
     * @param bool $combined
     * @return array
     */
    public function getUserCategories($user_id, $combined = true)
    {
        $categorySearch = new CategorySearchDb($this->db);

        $categories = $this->db->select('category_id')
            ->from('user_category AS u_c')
            ->leftJoin('category AS cat', 'user_category', false)
            ->where('u_c.user_id', $user_id)
            ->prepareFetchAll()
        ;

        $category_ids = [];
        foreach ($categories as $row) $category_ids[] = $row['category_id'];

        // Prepend parents from all categories
        $category_ids = array_merge($categorySearch->getParentsIds($category_ids), $category_ids);

        return $this->getCategories($category_ids);
    }


    public function getUser($id)
    {
        return $this->db->select('
                u_a.*,
                NULL AS inherited_id,
                NULL AS inherited_name
            ')
            ->from('user_acl AS u_a')
            ->where('u_a.user_id', $id)
            ->orderBy('module, action, allow, action_filter_criteria')
            ->prepareFetchAll();
    }

    /**
     * Get User's & Category ACL
     * @param $user_id
     * @return array
     */
    public function getMerged($user_id)
    {
        return array_merge($this->getUserCategories($user_id), $this->getUser($user_id));
    }

}