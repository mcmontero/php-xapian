<?php

// +------------------------------------------------------------+
// | LICENSE                                                    |
// +------------------------------------------------------------+

/**
 * Copyright 2013 Michael C. Montero
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// +------------------------------------------------------------+
// | PUBLIC CLASSES                                             |
// +------------------------------------------------------------+

//
// +------------------+
// | xapian_Exception |
// +------------------+
//

/**
 * Any errors arising through the use of this library will produce exceptions
 * of this type.
 */
class xapian_Exception
extends Exception
{
    function __construct($message)
    {
        parent::__construct($this->format_message($message));
    }

    // +-----------------+
    // | Private Methods |
    // +-----------------+

    private function format_message($message)
    {
        return "\n====================================================="
               . "=========================\n"
               . $message
               . "\n===================================================="
               . "==========================\n";
    }
}

//
// +-------------+
// | xapian_Base |
// +-------------+
//

/**
 * Provides a low level class for encapsulating often repeated steps for
 * interacting with Xapian.  A minimum requirement for the use of this class
 * is the need to create/open a Xapian database.
 */
class xapian_Base
{
    const DB_ACCESS_READ_ONLY  = 1;
    const DB_ACCESS_READ_WRITE = 2;

    protected $db_path;
    protected $db;
    protected $stem_lang;
    protected $stopper;
    protected $db_access_id;

    function __construct($db_path)
    {
        $this->db_path      = $db_path;
        $this->stem_lang    = 'english';
        $this->stopper      = new XapianSimpleStopper();
        $this->db_access_id = self::DB_ACCESS_READ_ONLY;
    }

    function __destruct()
    {
        if (!is_null($this->db))
        {
            $this->db = null;
        }
    }

    // +----------------+
    // | Public Methods |
    // +----------------+

    final public function set_read_write()
    {
        $this->db_access_id = self::DB_ACCESS_READ_WRITE;
        return $this;
    }

    final public function set_stem_lang($lang)
    {
        $this->stem_lang = $lang;
        return $this;
    }

    final public function set_stopper(XapianStopper $stopper)
    {
        $this->stopper = $stopper;
        return $this;
    }

    // +-------------------+
    // | Protected Methods |
    // +-------------------+

    final protected function connect_db()
    {
        if (is_null($this->db))
        {
            switch ($this->db_access_id)
            {
                case self::DB_ACCESS_READ_ONLY:
                    $this->db =
                        new XapianDatabase(
                                $this->db_path);
                    break;

                case self::DB_ACCESS_READ_WRITE:
                    $this->db =
                        new XapianWritableDatabase(
                                $this->db_path,
                                Xapian::DB_CREATE_OR_OPEN);
                    break;

                default:
                    throw new xapian_Exception(
                                'Unrecognized DB access ID "'
                                . $this->db_access_id
                                . '"');
            }
        }
    }
}

//
// +--------------------------+
// | xapian_Prefix_Dictionary |
// +--------------------------+
//

/**
 * Maps labels used in query text to their respective Xapian prefixes.  This
 * object allows you to define an application wide dictionary and use it
 * throughout your interactions with searches.
 *
 * Usage:
 *
 *  $dictionary =
 *      xapian_Prefix_Dictionary::get_instance()
 *          ->add_boolean_prefix(
 *              'gender', 'XG')
 *          ->add_text_prefix(
 *              'keyword', 'K')
 *          ->add_slot_prefix(
 *              0, 'XY:', xapian_Prefix_Dictionary::SLOT_NUM_VAL_RANGE_PROC);
 */
class xapian_Prefix_Dictionary
{
    const SLOT_NUM_VAL_RANGE_PROC = 1;

    private static $instance;
    private $text_prefixes;
    private $boolean_prefixes;
    private $slot_prefixes;

    static function get_instance()
    {
        if (!isset(self::$instance))
        {
            self::$instance = new xapian_Prefix_Dictionary();
        }

        return self::$instance;
    }

    function __construct()
    {
        $this->text_prefixes    = array();
        $this->boolean_prefixes = array();
        $this->slot_prefixes    = array();
    }

    // +----------------+
    // | Public Methods |
    // +----------------+

    final public function add_boolean_prefix($label, $prefix)
    {
        $this->boolean_prefixes[ $label ] = $prefix;
        return $this;
    }

    final public function add_slot_prefix($slot_num, $prefix, $type)
    {
        $this->validate_range_processor_type($type);

        $this->slot_prefixes[ $slot_num ] = array($prefix, $type);
        return $this;
    }

    final public function add_text_prefix($label, $prefix)
    {
        $this->text_prefixes[ $label ] = $prefix;
        return $this;
    }

    final public function configure_prefixes(XapianQueryParser $query_parser)
    {
        foreach ($this->text_prefixes as $label => $prefix)
        {
            $query_parser->add_prefix($label, $prefix);
        }

        foreach ($this->boolean_prefixes as $label => $prefix)
        {
            $query_parser->add_boolean_prefix($label, $prefix);
        }

        foreach ($this->slot_prefixes as $slot_num => $data)
        {
            list($prefix, $type) = $data;

            switch ($type)
            {
                case self::SLOT_NUM_VAL_RANGE_PROC:
                    $query_parser->add_valuerangeprocessor(
                                    new XapianNumberValueRangeProcessor(
                                            $slot_num, $prefix, true));
                    break;

                default:
                    throw new xapian_Exception('Unrecognized range processor '
                                               . 'type.');
            }
        }

        return $this;
    }

    // +-----------------+
    // | Private Methods |
    // +-----------------+

    private function validate_range_processor_type($type)
    {
        if (!in_array($type, array(self::SLOT_NUM_VAL_RANGE_PROC)))
        {
            throw new xapian_Exception('The range processor type you provided '
                                       . 'is invalid.');
        }
    }
}

//
// +-----------------------+
// | xapian_Record_Indexer |
// +-----------------------+
//

/**
 * Implements a simple record indexer for Xapian that supports both prefixed
 * and non-prefixed text.  To successfully execute an index operation you
 * much provide at least an ID for the record and one piece of text data or
 * boolean term.
 */
class xapian_Record_Indexer
extends xapian_Base
{
    private $id;
    private $text;
    private $boolean_terms;
    private $slots;
    private $data;

    function __construct($db_path)
    {
        parent::__construct($db_path);

        $this->reset();
    }

    static function make($db_path)
    {
        return new self($db_path);
    }

    // +----------------+
    // | Public Methods |
    // +----------------+

    final public function add_boolean_term($term)
    {
        $this->boolean_terms[] = $term;
        return $this;
    }

    final public function add_text($value, $prefix = null)
    {
        $this->text[ $prefix ] = $value;
        return $this;
    }

    final public function add_to_slot($slot_num, $value)
    {
        $this->slots[ $slot_num ] = $value;
        return $this;
    }

    public function execute()
    {
        if (empty($this->id))
        {
            throw new xapian_Exception('A record cannot be indexed without an '
                                       . 'integer ID.');
        }

        if (empty($this->text) &&
            empty($this->boolean_terms) &&
            empty($this->slots))
        {
            throw new xapian_Exception('In order to index a record you must '
                                       . 'provide text, boolean terms, values '
                                       . 'in slots or all of them.');
        }

        $this->connect_db();

        $document = new XapianDocument();
        $indexer  = new XapianTermGenerator();

        $indexer->set_document($document);
        $indexer->set_stemmer(new XapianStem($this->stem_lang));

        foreach ($this->text as $prefix => $value)
        {
            $indexer->index_text($value, 1, $prefix);
        }

        foreach ($this->boolean_terms as $term)
        {
            $document->add_boolean_term($term);
        }

        foreach ($this->slots as $slot_num => $value)
        {
            $document->add_value($slot_num, Xapian::sortable_serialise($value));
        }

        if (!is_null($this->data))
        {
            $document->set_data($this->data);
        }

        $this->db->replace_document($this->id, $document);

        $indexer  = null;
        $document = null;

        $this->reset();

        return $this;
    }

    final public function set_data($data)
    {
        $this->data = $data;
        return $this;
    }

    final public function set_id($id)
    {
        if (!is_int($id))
        {
            throw new xapian_Exception('This library does not support non-'
                                       . 'integer ID values');
        }

        $this->id = intval($id);
        return $this;
    }

    // +-----------------+
    // | Private Methods |
    // +-----------------+

    private function reset()
    {
        $this->id            = null;
        $this->text          = array();
        $this->boolean_terms = array();
        $this->slots         = array();
        $this->data          = null;
    }
}

//
// +--------------+
// | xapian_Query |
// +--------------+
//

/**
 * Executes searches against the Xapian index using a text query string.
 */
class xapian_Query
extends xapian_Base
{
    private $prefix_dict;
    private $query_parser;
    private $query;
    private $enquire;
    private $match_set;
    private $match_index;
    private $num_matches;

    function __construct($db_path, xapian_Prefix_Dictionary $dict = null)
    {
        parent::__construct($db_path);

        $this->prefix_dict = $dict;
        $this->reset();
    }

    function __destruct()
    {
        $this->enquire      = null;
        $this->query        = null;
        $this->query_parser = null;
    }

    static function make($db_path, xapian_Prefix_Dictionary $dict = null)
    {
        return new self($db_path, $dict);
    }

    // +----------------+
    // | Public Methods |
    // +----------------+

    final public function execute($query,
                                  $num_to_fetch = 100,
                                  $offset = 0,
                                  $check_at_least = null)
    {
        if (empty($query))
        {
            throw new xapian_Exception('A search cannot be executed without '
                                       . 'a query.');
        }

        $this->connect_db();

        $this->query_parser = new XapianQueryParser();
        $this->query_parser
            ->set_database($this->db);
        $this->query_parser
            ->set_stemmer(new XapianStem($this->stem_lang));
        $this->query_parser
            ->set_stemming_strategy(XapianQueryParser::STEM_SOME);
        $this->query_parser
            ->set_stopper($this->stopper);
        $this->prefix_dict
            ->configure_prefixes($this->query_parser);

        $this->query = $this->query_parser->parse_query($query);

        $this->enquire = new XapianEnquire($this->db);
        $this->enquire->set_query($this->query);

        $this->match_set =
            $this->enquire->get_mset($offset, $num_to_fetch, $check_at_least);
        $this->num_matches =
            $this->match_set->get_matches_estimated();

        return $this;
    }

    final public function get_match_set()
    {
        return $this->match_set;
    }

    final public function get_next()
    {
        if (empty($this->match_set))
        {
            throw new xapain_Exception('No match set has been generated.');
        }

        if ($this->num_matches == 0)
        {
            return null;
        }

        if (is_null($this->match_index))
        {
            $this->match_index = $this->match_set->begin();
            return $this->match_index;
        }
        else
        {
            $this->match_index->next();

            return $this->match_index->equals($this->match_set->end()) ?
                                null : $this->match_index;
        }
    }

    final public function get_num_matches()
    {
        return $this->num_matches;
    }

    final public function reset()
    {
        $this->enquire        = null;
        $this->query          = null;
        $this->query_parser   = null;
        $this->match_set      = null;
        $this->match_index    = null;
    }
}
?>
