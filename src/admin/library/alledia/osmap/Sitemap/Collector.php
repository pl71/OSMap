<?php
/**
 * @package   OSMap
 * @copyright 2007-2014 XMap - Joomla! Vargas - Guillermo Vargas. All rights reserved.
 * @copyright 2016 Open Source Training, LLC. All rights reserved.
 * @contact   www.alledia.com, support@alledia.com
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace Alledia\OSMap\Sitemap;

use Alledia\OSMap;
use Alledia\Framework;

defined('_JEXEC') or die();

/**
 * Sitemap items collector
 */
class Collector
{
    /**
     * @var SitemapInterface
     */
    protected $sitemap;

    /**
     * @var array
     */
    protected $uidList = array();

    /**
     * Callback used to trigger the desired action while fetching items.
     * This is only used in the legacy method printNode, which is called by
     * the osmap plugins to process the additional items.
     *
     * @var callable
     */
    protected $printNodeCallback;

    /**
     * The current view: xml or html. Kept for backward compatibility with
     * the legacy plugins. It is always HTML since the collector is generic now
     * and needs to have the information about the item's level even for the
     * XML view in the Pro version, to store that info in the cache.
     *
     * @var string
     */
    public $view = 'html';

    /**
     * Legacy property used by some plugins. True if we are collecting news.
     *
     * @var string
     *
     * @deprecated
     */
    public $isNews = false;

    /**
     * The items counter.
     *
     * @var int
     */
    protected $counter = 0;

    /**
     * The items with custom settings
     *
     * @var array
     */
    protected $itemsSettings;

    /**
     *
     */
    protected $tmpItemDefaultSettings = array(
        'changefreq' => 'weekly',
        'priority'   => '0.5'
    );

    /**
     * The current items level
     *
     * @var int
     */
    protected $currentLevel = 0;

    /**
     * The reference for the instance of the current menu for item and its
     * subitems.
     *
     * @var object
     */
    protected $currentMenu;

    /**
     * The component's params
     *
     * @var \JRegistry
     */
    protected $params;

    /**
     * The constructor
     */
    public function __construct($sitemap)
    {
        $this->sitemap = $sitemap;
        $this->params = \JComponentHelper::getParams('com_osmap');
    }

    /**
     * Collects sitemap items based on the selected menus. This is the main
     * method of this class. For each found item, it will call the given
     * callback, so it can manipulate the data in many ways. It returns the
     * total of found items.
     *
     * @param callable $callback
     *
     * @return int
     */
    public function fetch($callback)
    {
        // $baseMemory = memory_get_usage();
        // $baseTime   = microtime();

        $menus = $this->getSitemapMenus();
        $this->counter = 0;

        if (!empty($menus)) {

            // Get the custom settings from db for the items
            $this->getItemsSettings();

            foreach ($menus as $menu) {
                // Store a reference for the current menu
                $this->currentMenu = &$menu;

                // Get the menu items
                $items = $this->getMenuItems($menu);

                foreach ($items as $item) {
                    if ($this->itemIsBlackListed($item)) {
                        continue;
                    }

                    // Set the legacy UID
                    $item['uid'] = 'menuitem.' . $item['id'];

                    // Store the menu settings to use in the submitItemToCallback called
                    // by callbacks
                    $this->tmpItemDefaultSettings['changefreq'] = $menu->changefreq;
                    $this->tmpItemDefaultSettings['priority']   = $menu->priority;

                    // Submit the item and prepare it calling the plugins
                    $this->submitItemToCallback($item, $callback, true);

                    // Internal items can trigger plugins to grab more items
                    // The child items are not displayed if the parent item is ignored
                    if ($item->isInternal && !$item->ignore) {
                        // Call the plugin to get additional items related to
                        $this->callPluginsGetItemTree($item, $callback);
                    }

                    // Make sure the memory is cleaned
                    $item = null;
                }
            }
        }

        // echo sprintf('<m>%s</m>', memory_get_usage() - $baseMemory);
        // echo sprintf('<t>%s</t>', microtime() - $baseTime);
        return $this->counter;
    }

    /**
     * Submit the item to the callback, checking duplicity and incrementing
     * the counter. It can receive an array or object and returns true or false
     * according to the result of the callback.
     *
     * @param mixed    $item
     * @param callable $callback
     * @param bool     $prepareItem
     *
     * @return bool
     */
    public function submitItemToCallback(&$item, $callback, $prepareItem = false)
    {
        // Converts to an Item instance, setting internal attributes
        $item = new Item($item, $this->sitemap, $this->currentMenu);

        if ($prepareItem) {
            // Call the plugins to prepare the item
            $this->callPluginsPreparingTheItem($item);
        }

        $this->setItemCustomSettings($item);

        // Check if is external URL and if should be ignored
        if (!$item->isInternal) {
            if (!(bool)$this->params->get('show_external_links', 0)) {
                $item->ignore = true;
            }
        }

        // Verify if the item's link was already listed, if not ignored
        if ($this->checkItemWillBeDisplayed($item)) {
            $this->checkDuplicatedUIDToIgnore($item);

            // Check again, after verify the duplicity
            if ($this->checkItemWillBeDisplayed($item)) {
                if (!$item->duplicate) {
                    ++$this->counter;
                }
            }
        }

        // Set the current level to the item
        $item->level = $this->currentLevel;

        // Call the given callback function
        return (bool)call_user_func($callback, $item);
    }

    /**
     * Gets the list of selected menus for the sitemap.
     * It returns a list of objects with the attributes:
     *  - name
     *  - menutype
     *  - priority
     *  - changefrq
     *  - ordering
     *
     * @return array;
     */
    protected function getSitemapMenus()
    {
        $db = OSMap\Factory::getContainer()->db;

        $query = $db->getQuery(true)
            ->select(
                array(
                    'mt.id',
                    'mt.title AS ' . $db->quoteName('name'),
                    'mt.menutype',
                    'osm.changefreq',
                    'osm.priority',
                    'osm.ordering'
                )
            )
            ->from('#__osmap_sitemap_menus AS osm')
            ->join('LEFT', '#__menu_types AS mt ON (osm.menutype_id = mt.id)')
            ->where('osm.sitemap_id = ' . $db->quote($this->sitemap->id))
            ->order('osm.ordering');

        $list = $db->setQuery($query)->loadObjectList('menutype');

        // Check for a database error
        if ($db->getErrorNum()) {
            throw new \Exception($db->getErrorMsg(), 021);
        }

        return $list;
    }

    /**
     * Get the menu items as a tree
     *
     * @param object $menu
     *
     * @return array
     */
    protected function getMenuItems($menu)
    {
        $container = OSMap\Factory::getContainer();
        $db        = $container->db;
        $app       = $container->app;
        $lang      = $container->language;

        $query = $db->getQuery(true)
            ->select(
                array(
                    'm.id',
                    'm.title AS ' . $db->quoteName('name'),
                    'm.alias',
                    'm.path',
                    'm.level',
                    'm.type',
                    'm.home',
                    'm.params',
                    'm.parent_id',
                    'm.browserNav',
                    'm.link',
                    // Say that the menu came from a menu
                    '1 AS ' . $db->quoteName('isMenuItem'),
                    // Flag that allows to children classes choose to ignore items
                    '0 AS ' . $db->quoteName('ignore')
                )
            )
            ->from('#__menu AS m')
            ->join('INNER', '#__menu AS p ON (p.lft = 0)')
            ->where('m.menutype = ' . $db->quote($menu->menutype))
            // Only published menu items
            ->where('m.published = 1')
            // Only public/guest menu items
            ->where('m.access IN (' . OSMap\Helper::getAuthorisedViewLevels() . ')')
            ->where('m.lft > p.lft')
            ->where('m.lft < p.rgt')
            ->order('m.lft');

        // Filter by language
        if ($app->isSite() && $app->getLanguageFilter()) {
            $query->where('m.language IN (' . $db->quote($lang->getTag()) . ',' . $db->quote('*') . ')');
        }

        $items = $db->setQuery($query)->loadAssocList();

        // Check for a database error
        if ($db->getErrorNum()) {
            throw new Exception($db->getErrorMsg(), 021);
        }

        return $items;
    }

    /**
     * Checks if the item's uid was already registered. If positive, set the
     * item to be ignored and return true. If negative, register the item and
     * return false.
     *
     * @param object $item
     *
     * @return bool
     */
    protected function checkDuplicatedUIDToIgnore($item)
    {
        // If is already set, interrupt the flux and ignore the item
        if (isset($this->uidList[$item->uid])) {
            $item->set('duplicate', true);

            return true;
        }

        // Not set and published, so let's register
        if ($item->published) {
            $this->uidList[$item->uid] = 1;
        }

        return false;
    }

    /**
     * Calls the respective OSMap and XMap plugin, according to the item's
     * component/option. If the plugin's method returns false, it will set
     * the item's ignore attribute to true.
     *
     * @param Item $item
     *
     * @return void
     */
    protected function callPluginsPreparingTheItem($item)
    {
        // Call the OSMap and XMap legacy plugins, if exists
        $plugins = OSMap\Helper::getPluginsForComponent($item->option);

        if (!empty($plugins)) {
            foreach ($plugins as $plugin) {
                $className = '\\' . $plugin->className;

                $result = true;

                if (method_exists($className, 'prepareMenuItem')) {
                    // If a legacy plugin doesn't specify this method as static, fix the plugin to avoid warnings
                    $result = $className::prepareMenuItem($item, $plugin->params);

                    // If a plugin doesn't return true we ignore the item and break
                    if ($result === false) {
                        $item->set('ignore', true);

                        break;
                    }
                }
            }
        }
    }

    /**
     * Calls the respective OSMap and XMap plugin, according to the item's
     * component/option. Get additional items and send to the callback.
     *
     * @param Item     $item
     * @param Callable $callback
     *
     * @return void
     */
    protected function callPluginsGetItemTree($item, $callback)
    {
        // Register the current callback
        $this->printNodeCallback = $callback;

        // Call the OSMap and XMap legacy plugins, if exists
        $plugins = OSMap\Helper::getPluginsForComponent($item->option);

        if (!empty($plugins)) {
            foreach ($plugins as $plugin) {
                $className = '\\' . $plugin->className;

                if (method_exists($className, 'getTree')) {
                    $className::getTree($this, $item, $plugin->params);
                }
            }
        }
    }

    /**
     * Returns true if the link of the item is in the blacklist array.
     *
     * @param array $item
     *
     * @return bool
     */
    protected function itemIsBlackListed($item)
    {
        $blackList = array(
            'administrator' => 1
        );

        $link = $item['link'];

        return isset($blackList[$link]);
    }

    /**
     * This method is used for backward compatibility. The plugins will call
     * it. In the legacy XMap its behavior depends on the sitemap view type,
     * only changing the level in the HTML view. Now, it always consider the
     * level of the item, even for XML view. That allows to store that info
     * in a cache for both view types. XML will just ignore that.
     *
     * @param int $step
     *
     * @return void
     */
    public function changeLevel($step)
    {
        if (is_numeric($step)) {
            $this->currentLevel += $step;
        }

        return true;
    }

    /**
     * Method called by legacy plugins, which will pass the new item to the
     * callback. Returns the result of the callback converted to boolean.
     *
     * @param object $node
     *
     * @return bool
     */
    public function printNode($node)
    {
        return $this->submitItemToCallback($node, $this->printNodeCallback);
    }

    /**
     * This method gets the settings for all items which have custom settings.
     *
     * @return array;
     */
    protected function getItemsSettings()
    {
        if (empty($this->itemsSettings)) {
            $db = OSMap\Factory::getContainer()->db;

            $query = $db->getQuery(true)
                ->select(
                    array(
                        '*',
                        'IF (url_hash IS NULL OR url_hash = "", uid, CONCAT(uid, ":", url_hash)) AS ' . $db->quoteName('key')
                    )
                )
                ->from('#__osmap_items_settings')
                ->where('sitemap_id = ' . $db->quote($this->sitemap->id));

            $this->itemsSettings = $db->setQuery($query)->loadAssocList('key');
        }

        return $this->itemsSettings;
    }

    /**
     * Gets the item custom settings if set. If not set, returns false.
     *
     * @param string $key
     *
     * @return mix
     */
    public function getItemCustomSettings($key)
    {
        if (isset($this->itemsSettings[$key])) {
            return $this->itemsSettings[$key];
        }

        return false;
    }

    /**
     * Sets the item's custom settings if exists. If no custom settings are
     * found and is a menu item, use the menu's settings. If is s subitem
     * (from plugins), we consider it already set the respective settings. But
     * if there is a custom setting for the item, we use that overriding what
     * was set in the plugin.
     */
    public function setItemCustomSettings($item)
    {
        // Check if the menu item has custom settings. If not, use the values from the menu
        // Check if there is a custom settings specific for this URL. Sometimes the same page has different URLs.
        // We can have different settings for items with the same UID, but different URLs
        $key = $item->uid . ':' . $item->fullLinkHash;
        $settings = $this->getItemCustomSettings($key);

        // Check if there is a custom settings for all links with that UID (happens right after a migration from
        // versions before 4.0.0)
        if ($settings === false) {
            $settings = $this->getItemCustomSettings($item->uid);
        }

        if ($settings === false) {
            // No custom settings, so let's use the menu's settings
            if ($item->isMenuItem) {
                $item->changefreq = $this->tmpItemDefaultSettings['changefreq'];
                $item->priority   = $this->tmpItemDefaultSettings['priority'];
            }
        } else {
            // Apply the custom settings
            $item->changefreq = $settings['changefreq'];
            $item->priority   = is_float($settings['priority']) ? $settings['priority'] : $settings['priority'];
            $item->published  = (bool)$settings['published'];
        }
    }

    /**
     * Checks the item's ignore and published states to say if it will be
     * displayed or not in the sitemap.
     *
     * @param \Item $item
     *
     * @return bool
     */
    protected function checkItemWillBeDisplayed($item)
    {
        return !$item->ignore && $item->published;
    }
}
