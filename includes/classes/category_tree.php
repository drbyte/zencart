<?php
/**
 * This class is used to generate the category tree used for the categories sidebox
 *
 * @package classes
 * @copyright Copyright 2003-2016 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: category_tree.php  Modified in v1.6.0 $
 */

/**
 * category_tree class.
 * This class is used to generate the category tree used for the categories sidebox
 *
 * @package classes
 */
class category_tree extends base
{
    public $has_any_categories = false;
    protected $box_categories_array = [];
    protected $tree = [];
    protected $first_id, $last_id;
    protected $first_element;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->has_any_categories = $this->get_whether_store_has_any_categories();
    }
    /**
     * Does the store have any categories at all?
     *
     * @return bool
     */
    public function get_whether_store_has_any_categories()
    {
        global $db;
        $result = $db->Execute("select categories_id from " . TABLE_CATEGORIES . " where categories_status=1 limit 1");

        return ($result->RecordCount() > 0);

    }

    /**
     * Build and return a nested category tree array for the sidebox template to build its links and HTML/CSS from
     * @param array $cPath_array
     * @param int|string $product_type
     * @return array
     */
    public function get_nested_category_tree_for_sidebox($cPath_array = [], $product_type = 'all')
    {
        $this->build_nested_category_tree($cPath_array, $product_type);

        return $this->build_categories_array_for_sidebox();
    }

    /**
     * Query the DB for the category list, and optionally also cycle thru current cPath array
     * @param array $cPath_array
     * @param int|string $product_type
     * @return mixed
     */
    protected function build_nested_category_tree($cPath_array = [], $product_type = "all")
    {
        global $db;
        $this->tree = [];

        $this->build_tree_from_query(TOPMOST_CATEGORY_PARENT_ID, $product_type, 0, '');

        // first_element is used to denote which category starts the actual sidebox output when generating
        $this->first_element = $this->first_id;

//die('<pre>'.print_r($this->tree, true));
        // if we've been given a cPath, then we expand that category to show its subcats as well
        if (sizeof($cPath_array)) {
            $new_path = '';
            reset($cPath_array);
            while (list($key, $value) = each($cPath_array)) {
                unset($this->first_id);

                $this->build_tree_from_query($value, $product_type, $key + 1, $new_path);

                // reset the next_id value pointers so we can traverse back out to the parents again when building the sidebox
                $this->tree[$this->last_id]['next_id'] = $this->tree[$value]['next_id'];
                $this->tree[$value]['next_id'] = $this->first_id;
                $new_path .= $value . '_';
            }
        }
//        die('<pre>'.print_r($this->tree, true));
    }
    /**
     * @param int $starting_category
     * @param int|string $product_type
     * @param int $nest_level
     * @param string $new_path
     * @return int
     */
    protected function build_tree_from_query($starting_category = TOPMOST_CATEGORY_PARENT_ID, $product_type = 'all', $nest_level = 0, $new_path = '')
    {
        global $db;
        $sql = $this->get_categories_query($starting_category, $product_type);
        $categories = $db->Execute($sql, '', false, 150); // TRUE
        if ($categories->RecordCount() == 0) {
            return false;
        }

        if ($nest_level) $new_path .= $starting_category;

        foreach ($categories as $cat) {

            // first_id is used to identify the first cat inside any parent
            if (empty($this->first_id)) {
                $this->first_id = $cat['categories_id'];
            }

            $this->tree[$cat['categories_id']] =
                [
                    'name'        => $cat['categories_name'],
                    'parent'      => $cat['parent_id'],
                    'level'       => $nest_level,
                    'path'        => (!empty($new_path) ? $new_path . '_' : '') . $cat['categories_id'],
                    'image'       => $cat['categories_image'],
                    'next_id'     => false,
                    'has_sub_cat' => zen_has_category_subcategories($cat['categories_id']),
                    'products'    => 0,
                ];

            if (SHOW_COUNTS == 'true') {
                $this->tree[$cat['categories_id']]['products'] = zen_count_products_in_category($cat['categories_id']);
            }

            // update previous item's 'next_id' since we couldn't look ahead to get it
            if (isset($previous_id)) {
                $this->tree[$previous_id]['next_id'] = $cat['categories_id'];
            }
// echo '<pre>'.print_r($this->tree[$cat['categories_id']], true) . $previous_id . '</pre><br>';
            // set value so we can look back next time thru
            $previous_id = $cat['categories_id'];

            // always contains the last id processed
            $this->last_id = $cat['categories_id'];

        }
    }

    /**
     * Build the SQL query for extracting categories
     *
     * @param int $value
     * @param int|string $product_type
     * @return string
     */
    protected function get_categories_query($value = TOPMOST_CATEGORY_PARENT_ID, $product_type = 'all')
    {
        if ($product_type == 'all') {
            $query = "select c.categories_id, cd.categories_name, c.parent_id, c.categories_image
                      from " . TABLE_CATEGORIES . " c, " . TABLE_CATEGORIES_DESCRIPTION . " cd
                      where c.parent_id = " . (int)$value . "
                      and c.categories_id = cd.categories_id
                      and cd.language_id=" . (int)$_SESSION['languages_id'] . "
                      and c.categories_status= 1
                      order by sort_order, cd.categories_name";
        } else {
            $query = "select ptc.category_id as categories_id, cd.categories_name, c.parent_id, c.categories_image
                      from " . TABLE_CATEGORIES . " c, " . TABLE_CATEGORIES_DESCRIPTION . " cd, " . TABLE_PRODUCT_TYPES_TO_CATEGORY . " ptc
                      where c.parent_id = " . (int)$value . "
                      and ptc.category_id = cd.categories_id
                      and ptc.product_type_id = " . (int)$product_type . "
                      and c.categories_id = ptc.category_id
                      and cd.language_id=" . (int)$_SESSION['languages_id'] . "
                      and c.categories_status= 1
                      order by sort_order, cd.categories_name";
        }

        return $query;
    }


    /**
     * @param int $starting_category
     * @return array
     */
    protected function build_categories_array_for_sidebox()
    {
//        die('<pre>'.print_r($this->tree, true));
        global $cPath_array;
        $catID = (int)$this->first_element;
        $row   = 0;
        while (!empty($this->tree[$catID])) {
            $is_top_level_category = ((int)$this->tree[$catID]['parent'] == (int)TOPMOST_CATEGORY_PARENT_ID);
            $this->box_categories_array[$row]['top'] = $is_top_level_category;

            // set category name
            if (!$is_top_level_category) {
                $this->box_categories_array[$row]['name'] = str_repeat(CATEGORIES_SUBCATEGORIES_INDENT, (int)$this->tree[$catID]['level']) . CATEGORIES_SEPARATOR_SUBS;
            }
            $this->box_categories_array[$row]['name'] .= $this->tree[$catID]['name'];

            // set other params for this category
            $this->box_categories_array[$row]['path']        = $this->tree[$catID]['path'];
            $this->box_categories_array[$row]['current']     = (isset($cPath_array) && in_array($catID, $cPath_array));
            $this->box_categories_array[$row]['image']       = $this->tree[$catID]['image'];
            $this->box_categories_array[$row]['has_sub_cat'] = $this->tree[$catID]['has_sub_cat'];
            $this->box_categories_array[$row]['count']       = $this->tree[$catID]['products'];

            # break loop if there's no next_id
            if (empty($this->tree[$catID]['next_id'])) {
                break;
            }

            # get next category ID
            $catID = (int)$this->tree[$catID]['next_id'];
            $row++;
        }

        return $this->box_categories_array;
    }


    /**
     * // @TODO - for future use
     *
     * @param string $product_type
     */
    public function get_nested_category_tree_unordered_list($product_type = 'all')
    {
        $first_category = $this->build_nested_category_tree([], $product_type);

        return $this->build_categories_ul_string($first_category);
    }

    /**
     * @TODO - for future use
     * @param int $starting_category
     */
    protected function build_categories_ul_string($starting_category = TOPMOST_CATEGORY_PARENT_ID)
    {
        //@TODO
    }

}
