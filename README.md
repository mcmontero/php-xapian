php-xapian
Copyright 2013 Michael C. Montero (mcmontero@gmail.com)

XAPIAN
======

    - Xapian is a highly adaptable toolkit which allows developers to easily add
      advanced indexing and search facilities to their own applications. It
      supports the Probabilistic Information Retrieval model and also supports a
      rich set of boolean query operators.

        http://xapian.org/

GOALS
=====

    - To provide a simple, standardized PHP interface for indexing data into
      Xapian and searching against it.

USAGE
=====

    xapian_Exception
    ----------------
      * Any errors arising through the use of the library will produce
        exceptions of this type.

            throw new xapian_Exception('The type you provided is invalid.');

    xapian_Base
    -----------
      * Provides a low level class for encapsulating often repeated steps for
        interacting with Xapian.  A minimum requirement for the use of this
        class is the need to create/open a Xapian database.

            class my_Class
            extends xapian_Base
            {
                function __construct($db_path)
                {
                    parent::__construct($db_path);
                    $this->connect_db();
                }

                ...
            }

    xapian_Prefix_Dictionary
    ------------------------
      * Maps labels used in query text to their respective Xapian prefixes.
        This object allows you to define an application wide dictionary and
        use it throughout your interactions with searches.

            $dictionary =
                xapian_Prefix_Dictionary::get_instance()
                    ->add_boolean_prefix(
                        'color', 'XCOLOR')
                    ->add_boolean_prefix(
                        'shape', 'XSHAPE')
                    ->add_text_prefix(
                        'words', 'W')
                    ->add_slot_prefix(
                        0, 'num:',
                        xapian_Prefix_Dictionary::SLOT_NUM_VAL_RANGE_PROC);

    xapian_Record_Indexer
    ---------------------
      * Implements a simple record indexer for Xapian that supports both
        prefixed and non-prefixed text.  To successfully execute an index
        operation you much provide at least an ID for the record and one
        piece of text data or boolean term.

            $indexer = xapian_Record_Indexer::make('/opt/xapian/db');

            $indexer->set_id(1)
                    ->add_boolean_term('XCOLORblue')
                    ->add_boolean_term('XSHAPEsquare')
                    ->add_text('The quick brown fox jumped over the lazy dog.')
                    ->add_to_slot(0, 1234)
                    ->set_data(array('name' => 'Michael Montero',
                                     'dob'  => 'January 15, 1974'))
                    ->execute();

    xapian_Query
    ------------
      * Executes searches against the Xapian index using a text query string.

            $query   = xapian_Query('/opt/xapian/db', $dictionary)
            $matches = $query->execute('color:blue '
                                       . 'AND shape:square '
                                       . 'AND num:1..1235');

            while (!is_null(($match = $matches->get_next())))
            {
                $rank = $match->get_rank() + 1;
                $data = $match->get_document()->get_data();
                print "$rank: " . $match->get_percent() . "% $data\n";
            }

            $query->reset();

DEPENDENCIES
============

    - You must obtain and install Xapian and the PHP bindings from this
      location:

        http://xapian.org/download
