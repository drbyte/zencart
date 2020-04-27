<?php
/**
 * language Class.
 *
 * @package classes
 * @copyright Copyright 2003-2020 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id:  Modified in v1.5.7 $
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

/**
 * language Class.
 * Class to handle language settings for customer viewing
 *
 * @package classes
 */
class language extends base
{
    public $catalog_languages = array();
    public $browser_languages = array();
    public $language = '';
    protected $languages = array();

    function __construct($lng = '')
    {
        global $db;

        $sql = "SELECT languages_id, name, code, image, directory
                FROM " . TABLE_LANGUAGES . "
                ORDER BY sort_order";
        $results = $db->Execute($sql);

        foreach ($results as $result) {
            $this->languages[$result['code']] = array(
                'id' => $result['languages_id'],
                'name' => $result['name'],
                'image' => $result['image'],
                'icon' => isset($result['icon']) ? $result['icon'] : '',
                'code' => $result['code'],
                'directory' => $result['directory'],
            );
            $this->languages[$result['code']]['image_html'] = $this->set_language_image_html($result);

            // fallback to using value of 'image' as the CSS icon class if the image didn't match a filename
            $this->languages[$result['code']]['icon'] = $this->set_language_icon($result);

        }
        $this->catalog_languages = $this->languages;

        $this->set_language($lng);
    }

    function get_languages()
    {
        return $this->languages;
    }

    function get_language($lookup)
    {
        return $this->languages;
    }

    protected function set_language_image_html($langRecord = array())
    {
        if (empty($langRecord)) return '';

        $file = $langRecord['directory'] . '/images/' . $langRecord['image'];
        $filedirRegex = '~[^0-9a-z' . preg_quote('.!@#$%&()_-~/`+^ ' . '\\', '~') . ']~i';
        $cleanedFilename = preg_replace($filedirRegex, '', $file);
        if (file_exists(DIR_WS_CATALOG_LANGUAGES . $cleanedFilename)) {
            $image_path = DIR_WS_CATALOG_LANGUAGES . $cleanedFilename;
        }
        return zen_image($image_path, $langRecord['directory']);
    }

    protected function set_language_icon($langRecord = array())
    {
        if (empty($langRecord)) return '';
        if (!isset($langRecord['code]'])) return '';
        if (!isset($this->languages[$langRecord['code]']])) return '';

        // fallback to using 'image' as the CSS icon class if the image didn't match a filename
        if (empty($this->languages[$langRecord['code]']]['icon'])) {
            if (empty($this->languages[$langRecord['code']]['image_html'])) {
                return $this->languages[$langRecord['code']]['image'];
            }
        }

        return $this->languages[$langRecord['code']]['icon'];
    }

    function set_language($language)
    {
        if (!empty($language) && isset($this->languages[$language])) {
            $this->language = $this->languages[$language];
            return;
        }

        $this->language = $this->languages[DEFAULT_LANGUAGE];
    }

    function get_browser_language()
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $this->browser_languages = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            for ($i = 0, $n = count($this->browser_languages); $i < $n; $i++) {
                $lang = explode(';', $this->browser_languages[$i]);
                if (strlen($lang[0]) == 2) {
                    $code = $lang[0];
                } elseif (strpos($lang[0], '-') == 2 || strpos($lang[0], '_') == 2) {
                    $code = substr($lang[0], 0, 2);
                } else {
                    continue;
                }
                if (isset($this->languages[$code])) {
                    $this->language = $this->languages[$code];
                    break;
                }
            }
        }
    }
}
