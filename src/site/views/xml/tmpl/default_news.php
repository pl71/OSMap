<?php
/**
 * @package   OSMap
 * @contact   www.joomlashack.com, help@joomlashack.com
 * @copyright 2007-2014 XMap - Joomla! Vargas - Guillermo Vargas. All rights reserved.
 * @copyright 2016-2020 Joomlashack.com. All rights reserved.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 *
 * This file is part of OSMap.
 *
 * OSMap is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * OSMap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OSMap.  If not, see <http://www.gnu.org/licenses/>.
 */

use Alledia\OSMap;

defined('_JEXEC') or die();

$params = $this->params;

$params->set('cutoff_date', new DateTime('-' . $this->sitemap->newsDateLimit . ' days'));

$printNodeCallback = function ($node) use ($params) {
    // Limit to Google requirements
    static $limit = 1000;

    $display = !$node->ignore
        && $node->published
        && (!$node->duplicate || ($node->duplicate && !$this->osmapParams->get('ignore_duplicated_uids', 1)))
        && isset($node->newsItem)
        && !empty($node->newsItem)
        && $node->visibleForRobots
        && $node->parentIsVisibleForRobots
        && $node->visibleForXML
        && $node->isInternal
        && trim($node->fullLink) != '';

    if (!$node->hasCompatibleLanguage()) {
        $display = false;
    }

    if (!$display) {
        return false;
    }

    if (--$limit < 0) {
        return false;
    }

    // Publication date
    $publicationDate = (
        isset($node->publishUp)
        && !empty($node->publishUp)
        && $node->publishUp != OSMap\Factory::getDbo()->getNullDate()
        && $node->publishUp != -1
    ) ? $node->publishUp : null;

    $publicationDate = new JDate($publicationDate);
    if ($params->get('cutoff_date') > $publicationDate) {
        return false;
    }

    // Publication name
    $publicationName = $params->get('news_publication_name', '');

    // Print the item
    echo '<url>';
    echo '<loc>' . htmlspecialchars($node->fullLink) . '</loc>';
    echo "<news:news>";
    echo '<news:publication>';
    echo '<news:name>' . htmlspecialchars($publicationName) . '</news:name>';

    // Language
    if (!isset($node->language) || $node->language == '*') {
        $defaultLanguage = strtolower(JFactory::getLanguage()->getTag());

        // Legacy code. Not sure why the hardcoded zh-cn and zh-tw
        if (preg_match('/^([a-z]+)-.*/', $defaultLanguage, $matches)

            && !in_array($defaultLanguage, array(' zh-cn', ' zh-tw'))
        ) {
            $defaultLanguage = $matches[1];
        }

        $node->language = $defaultLanguage;
    }

    echo '<news:language>' . $node->language . '</news:language>';

    echo '</news:publication>';

    echo '<news:publication_date>' . $publicationDate->format('Y-m-d\TH:i:s\Z') . '</news:publication_date>';

    // Title
    echo '<news:title>' . htmlspecialchars($node->name) . '</news:title>';

    // Keywords
    if (isset($node->keywords)) {
        echo '<news:keywords>' . htmlspecialchars($node->keywords) . '</news:keywords>';
    }

    echo "</news:news>";
    echo '</url>';

    return true;
};

// Do we need to apply XSL?
if ($this->params->get('add_styling', 1)) {
    $title = '';
    if ($this->params->get('show_page_heading', 1)) {
        $title = '&amp;title=' . urlencode($this->pageHeading);
    }

    echo '<?xml-stylesheet type="text/xsl" href="' . JUri::base() . 'index.php?option=com_osmap&amp;view=xsl&amp;format=xsl&amp;tmpl=component&amp;layout=news&amp;id=' . $this->sitemap->id . $title . '"?>';
}

echo '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';

$this->sitemap->traverse($printNodeCallback);

echo '</urlset>';
