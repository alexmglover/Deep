<?php

/**
 * Deep
 *
 * @package      rsanchez\Deep
 * @author       Rob Sanchez <info@robsanchez.com>
 */

namespace rsanchez\Deep\Plugin;

use rsanchez\Deep\Deep;
use rsanchez\Deep\App\Entries;
use rsanchez\Deep\Model\Entry;
use rsanchez\Deep\Collection\AbstractTitleCollection;
use rsanchez\Deep\Collection\EntryCollection;
use DateTime;
use Closure;

/**
 * Base class for EE modules/plugins
 */
abstract class BasePlugin
{
    /**
     * Hold an instance of the Deep IoC
     * @var \rsanchez\Deep\Deep
     */
    protected static $app;

    /**
     * Constructor
     * @return void
     */
    public function __construct()
    {
        if (is_null(self::$app)) {
            self::$app = new Deep(ee()->config->config);

            self::$app->bootEloquent(ee());
        }
    }

    /**
     * Parse a plugin tag pair equivalent to channel:entries
     *
     * @param  Closure|null $callback receieves a query builder object as the first parameter
     * @return string
     */
    protected function parse(Closure $callback = null)
    {
        $disabled = array();

        if ($disable = ee()->TMPL->fetch_param('disable')) {
            $disabled = explode('|', $disable);
        }

        ee()->load->library('pagination');

        $pagination = ee()->pagination->create();

        $limit = ee()->TMPL->fetch_param('limit');

        if ($limit && ! in_array('pagination', $disabled)) {
            ee()->TMPL->tagdata = $pagination->prepare(ee()->TMPL->tagdata);

            unset(ee()->TMPL->tagparams['offset']);
        }

        $query = self::$app->make('Entry')->tagparams(ee()->TMPL->tagparams);

        if ($pagination->paginate) {
            $paginationCount = $query->getPaginationCount();

            if (preg_match('#P(\d+)/?$#', ee()->uri->uri_string(), $match)) {
                $query->skip($match[1]);
            }

            $pagination->build($paginationCount, $limit);
        }

        if (is_callable($callback)) {
            $callback($query);
        }

        $entries = $query->get();

        $output = $this->parseEntryCollection($entries);

        if ($pagination->paginate) {
            $output = $pagination->render($output);
        }

        if ($backspace = ee()->TMPL->fetch_param('backspace')) {
            $output = substr($output, 0, -$backspace);
        }

        return $output;
    }

    /**
     * Parse TMPL->tagdata with the specified collection of entries
     *
     * @param  \rsanchez\Deep\Collection\AbstractTitleCollection $entries
     * @return string
     */
    protected function parseEntryCollection(AbstractTitleCollection $entries)
    {
        $tagdata = ee()->TMPL->tagdata;

        $customFieldsEnabled = $entries instanceof EntryCollection;

        preg_match_all('#{((url_title_path|title_permalink)=([\042\047])(.*?)\\3)}#', $tagdata, $urlTitlePathMatches);
        preg_match_all('#{(entry_id_path=([\042\047])(.*?)\\2)}#', $tagdata, $entryIdPathMatches);
        preg_match_all('#{((.*?) format=([\042\047])(.*?)\\3)}#', $tagdata, $dateMatches);

        $singleTags = array();
        $pairTags = array();

        if ($customFieldsEnabled) {
            foreach (array_keys(ee()->TMPL->var_single) as $tag) {
                $spacePosition = strpos($tag, ' ');

                if ($spacePosition === false) {
                    $name = $tag;
                    $params = array();
                } else {
                    $name = substr($tag, 0, $spacePosition);
                    $params = ee()->functions->assign_parameters(substr($tag, $spacePosition));
                }

                $singleTags[] = (object) array(
                    'name' => $name,
                    'key' => $tag,
                    'params' => $params,
                    'tagdata' => '',
                );
            }

            foreach (ee()->TMPL->var_pair as $tag => $params) {
                $spacePosition = strpos($tag, ' ');

                $name = $spacePosition === false ? $tag : substr($tag, 0, $spacePosition);

                preg_match_all('#{('.preg_quote($tag).'}(.*?){/'.preg_quote($name).')}#s', $tagdata, $matches);

                foreach ($matches[1] as $i => $key) {
                    $pairTags[] = (object) array(
                        'name' => $name,
                        'key' => $key,
                        'params' => $params,
                        'tagdata' => $matches[2][$i],
                    );
                }
            }
        }

        $variables = array();

        foreach ($entries as $entry) {
            $row = array();

            if ($customFieldsEnabled) {
                foreach ($pairTags as $tag) {
                    if ($entry->channel->fields->hasField($tag->name)) {
                        $row[$tag->key] = $entry->{$tag->name}->isEmpty() ? '' : ee()->TMPL->parse_variables($tag->tagdata, $entry->{$tag->name}->toArray());
                    }
                }

                foreach ($singleTags as $tag) {
                    if ($entry->channel->fields->hasField($tag->name)) {
                        $row[$tag->key] = (string) $entry->{$tag->name};
                    }
                }
            }

            foreach ($urlTitlePathMatches[1] as $i => $key) {
                $row[$key] = ee()->functions->create_url($urlTitlePathMatches[4][$i]);
            }

            foreach ($entryIdPathMatches[1] as $i => $key) {
                $row[$key] = ee()->functions->create_url($entryIdPathMatches[3][$i]);
            }

            foreach ($dateMatches[1] as $i => $key) {
                $name = $dateMatches[2][$i];
                $format = preg_replace('#%([a-zA-Z])#', '\\1', $dateMatches[4][$i]);

                $row[$key] = ($entry->$name instanceof DateTime) ? $entry->$name->format($format) : '';
            }

            $row = array_merge($row, $entry->getOriginal(), $entry->channel->toArray());

            if (isset($entry->member)) {
                foreach ($entry->member->toArray() as $key => $value) {
                    $row[$key] = $value;
                }
            }

            $variables[] = $row;
        }

        if (! $variables) {
            return ee()->TMPL->no_results();
        }

        return ee()->TMPL->parse_variables($tagdata, $variables);
    }
}
