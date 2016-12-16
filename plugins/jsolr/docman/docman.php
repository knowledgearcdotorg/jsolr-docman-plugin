<?php
/**
 * @package     JSolr.Plugin
 * @subpackage  Index
 * @copyright   Copyright (C) 2012-2016 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die();

\JLoader::registerNamespace('JSolr', JPATH_PLATFORM);

use \JSolr\Helper;

class PlgJSolrDocman extends \JSolr\Plugin
{
    protected $context = 'com_docman.document';

    public function __construct(&$subject, $config)
    {
        $this->bootFramework();

        parent::__construct($subject, $config);
    }

    protected function bootFramework()
    {
        // This is useful in CLI mode
        if (!class_exists('Koowa')) {
            if (!defined('JDEBUG')) {
                define('JDEBUG', 0);
            }

            JPluginHelper::importPlugin('system');

            JDispatcher::getInstance()->trigger('onAfterInitialise');
        }

        return class_exists('Koowa');
    }

    /**
     * Get a list of items.
     *
     * Items are paged depending on the Joomla! pagination settings.
     *
     * @param   int         $start  The position of the first item in the
     * recordset.
     * @param   int         $limit  The page size of the recordset.
     *
     * @return  StdClass[]  A list of items.
     */
    protected function getItems($start = 0, $limit = 10)
    {
        $items = KObjectManager::getInstance()
            ->getObject('com://admin/docman.controller.document')
                ->enabled(1)
                ->status('published')
                ->limit($limit)
                ->offset($start)
                ->sort('id')
                ->direction('asc')
                ->browse();

        return $items;
    }

    /**
     * Get the total number of documents.
     *
     * @return  int  The total number of documents.
     */
    protected function getTotal()
    {
        $count = KObjectManager::getInstance()
            ->getObject('com://admin/docman.model.documents')
                ->enabled(1)
                ->status('published')
                ->count();

        return $count;
    }

    /**
     * Prepare the item for indexing.
     *
     * @param   StdClass  $source
     * @return  array
     */
    protected function prepare($source)
    {
        $author = JFactory::getUser($source->created_by);

        $i18n = array();

        $array = array();

        $array['id'] = $this->buildId($source->id);
        $array['id_i'] = $source->id;
        $array['name'] = $source->title;
        $array["author"] = array($author->name);
        $array["author_ss"] = array($this->getFacet($author->name));
        $array["author_i"] = $author->id;
        $array['alias_s'] = $source->slug;
        $array['context_s'] = $this->get('context');
        $array['lang_s'] = '*';

        $array['access_i'] = $source->access;
        $array['access_is'] = array_keys($source->getGroups());

        $array["category_s"] = $this->getFacet($source->category->title); // for faceting
        $array["category_i"] = $source->category->id;

        $created = JFactory::getDate($source->created);
        $modified = JFactory::getDate($source->modified);

        if ($created > $modified) {
            $modified = $created;
        }

        $array['created_tdt'] = $created->format('Y-m-d\TH:i:s\Z', false);
        $array['modified_tdt'] = $modified->format('Y-m-d\TH:i:s\Z', false);

        if ($source->publish_up) {
            $published = JFactory::getDate($source->publish_up);

            $array['date_tdt'] = $published->format('Y-m-d\TH:i:s\Z', false);
        } else {
            $array['date_tdt'] = $array['created_tdt'];
        }

        $array["parent_id_i"] = $array["category_i"];

        $i18n["category"] = $source->category->title;

        $i18n["title"] = $array['name'];

        $descriptionParts = explode("<hr id=\"system-readmore\" />", $source->description);
        if (count($descriptionParts) > 0) {
            $description = strip_tags(array_shift($descriptionParts));
        } else {
            $description = strip_tags($source->description);
        }

        $i18n['description'] = $description;

        $i18n["content"] = strip_tags($source->description);

        if (is_array($source->tags)) {
            foreach ($source->tags as $key=>$value) {
                $array["tag_ss"][] = $value;
            }
        }

        // docman does not support multilingual. Index against called configured langs.
        foreach ($i18n as $key=>$value) {
            foreach (JLanguageHelper::getLanguages() as $language) {
                $lang = $this->getLanguage($language->lang_code, false);
                $array[$key."_txt_".$lang] = $value;
            }
        }

        return $array;
    }

    public function onJSolrSearchPrepareData($document)
    {
        if ($this->get('context') == $document->context_s) {
            if (class_exists('KObjectManager')) {
                $template = 'index.php?option=com_docman&view=document&alias=%s&category_slug=%s&Itemid=%d';

                $model = KObjectManager::getInstance()
                    ->getObject('com://admin/docman.model.documents')
                    ->page('all');

                $item = $model->setState(array('id'=>$document->id_i))->fetch();

                $model->setPage($item);

                return sprintf($template, $item->alias, $item->category_slug, $item->itemid);
            } else {
                throw new Exception("JoomlaTools DOCman not installed.");
            }
        }
    }

    /**
     * Adds group checks if applicable.
     *
     * @params  \Solarium\QueryType\Select\Query\Query  $query
     * @params  mixed                                   $state
     */
    public function onJSolrSearchBeforeQuery($query, $state)
    {
        $user = JFactory::getUser();

        $groups = implode(" OR ", $user->getAuthorisedGroups());

        if ($groups) {
            $query->getFilterQuery('access')->setQuery(
                "(".$query->getFilterQuery('access')->getQuery().") OR (access_is:".$groups.")");
        }
    }
}
