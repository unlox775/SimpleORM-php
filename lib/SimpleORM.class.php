<?php

// Debugging
if ( ! function_exists('bug')
     && file_exists(dirname('__FILE__') .'/debug.inc.php')
     ) require_once(dirname('__FILE__') .'/debug.inc.php');
define('ORM_SQL_PROFILE', true);
define('ORM_SQL_DEBUG', false);
define('ORM_SQL_WRITE_DEBUG', false);

$GLOBALS['SimpleORM_OBJECT_CACHE'] = array();

/**
 * SimpleORM Class, for simple-as-possible Object-to-Relational Mapping (for PostgreSQL, MySQL and SQLite)
 *
 * Example usage:
 *
 * <code>
 * class SimpleORM__DBHProvider extends SimpleORM {
 *     protected function provide_dbh()      { return get_global_dbh_from_wherever(); }
 *     protected function provide_dbh_type() { return 'pg'; } // or 'mysql' or 'sqlite'
 *     protected function include_prefix()   { return ''; } // or '/path/to/lib';
 * }
 * class MyStudent extends SimpleORM__DBHProvider {
 *     protected $table       = 'student';
 *     protected $primary_key = array( 'student_id' );
 *     protected $column_sequences = array( 'student_id' => 'student_student_id_seq' );
 *     protected $schema = array( 'student_id'  => array(),
 *                                'name'           => array( 'maxlength' => 25, 'required' => true ),
 *                                'email'          => array( 'format' => 'email', 'maxlength' => 100, 'required' => true ),
 *                                'homepage_url'   => array( 'maxlength' => 100, 'regex' => '/^http:\/\//' ),
 *                                'mentor_id'      => array(),
 *                                'status'         => array(),
 *                                'creation_date'  => array(), # a database-side default will auto fill this in...
 *         );
 *     protected $relations = array(
 *         'mentor' => array( 'relationship' => 'has_one',                 
 *                            'include'      => 'model/Teacher.class.php', # A file to require_once(), (should be in include_path)
 *                            'class'        => 'Teacher',                 # The class name
 *                            'columns'      => 'mentor_id',               # local cols to get the PKey for the new object (can be array if >1 col..)
 *             ),
 *         'classes' => array( 'relationship'      => 'has_many',
 *                           'include'             => 'model/Class/Student.class.php', # A file to require_once(), (should be in include_path)
 *                           'class'               => 'Class__Student',                # The class name
 *                           'foreign_table'       => 'class_student',                 # The table to SELECT FROM
 *                           'foreign_key_columns' => 'student_id',                    # The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
 *                           'foreign_table_pkey'  => array('class_id','student_id'),  # The primary key of that table                              (can be array if >1 col..)
 *                           'custom_where_clause' => "cancelled IS NOT NULL",         # special condition (other than the FKey column join)
 *                           'order_by_clause'     => 'course_name',                   # custom sorting (saves local sorting cost)
 *             ),
 *         'friends'=> array( 'relationship'                    => 'many_to_many',
 *                            'include'                         => 'model/MyStudent.class.php',    # A file to require_once(), (should be in include_path)
 *                            'class'                           => 'MyStudent',                    # The class name (NOTE: can be THIS class)
 *                            'foreign_table'                   => 'student',                      # The final table of the object we will be getting
 *                            'join_table'                      => 'student_peer',                 # The in-between table that has both pKeys
 *                            'foreign_table_pkey'              => 'student_id',                   # The pKey of the final table (NOTE: can be THIS table's pKey)
 *                            'change_status_instead_of_delete' => false,                          # OPTIONAL: Instead of delete, set "status" and "inactive_date" columns (requires you add these cols)
 *                            'join_table_fixed_values'         => array('peer_type' => 'friend'), # OPTIONAL: Alwyas set (and assume to be set) these cols.  Allows for multi-use of the same table    
 *                            'order_by_clause'                 => 'name',                         # custom sorting (fields of both the join (jt.) and foreign table (ft.) are valid)
 *                            ),
 *         'enemies'=> array( 'relationship'                    => 'many_to_many',
 *                            'include'                         => 'model/MyStudent.class.php',    # A file to require_once(), (should be in include_path)
 *                            'class'                           => 'MyStudent',                    # The class name (NOTE: can be THIS class)
 *                            'foreign_table'                   => 'student',                      # The final table of the object we will be getting
 *                            'join_table'                      => 'student_peer',                 # The in-between table that has both pKeys
 *                            'foreign_table_pkey'              => 'sst_fr_id',                    # The pKey of the final table (note: can be THIS table's pKey)
 *                            'change_status_instead_of_delete' => false,                          # OPTIONAL: Instead of delete, set "status" and "inactive_date" columns (requires you add these cols)
 *                            'join_table_fixed_values'         => array('peer_type' => 'enemy'),  # OPTIONAL: Alwyas set (and assume to be set) these cols.  Allows for multi-use of the same table
 *                            'order_by_clause'                 => 'name',                         # custom sorting (fields of both the join (jt.) and foreign table (ft.) are valid)
 *                            ),
 *     );
 *
 *     public function expell($reason) {
 *         foreach ($this->classes as $class_enrollment) {
 *             $class_enrollment->cancelled = true;
 *             $class_enrollment->save(); # also could have used set_and_save() to do in one step...
 *         }
 *         $this->set_and_save(array('status' => 'expelled'));
 *     }
 * }
 *
 * ###  New student
 * $newstud = MyStudent();                                                                        # object status = 'not_created'
 * $newstud->create(array('name' => 'Bob Dillan', 'email' => 'bob@dillan.com', mentor_id => 11)); # object status = 'active'
 * echo $newstud->creation_date;  # this value should have been filled by the database
 * $newstud->set_complete_relation('friends',array(18,79,20,728)); # add 4 friends
 * $newstud->add_relation('enemies', 666); # add an enemy
 * echo $newstud->friends[0]->name;  # Show my first friend's name (sorted by name)
 *
 * ###  Make SURE that student ID=18 is NOT an enemy!
 * if ( $newstud->has_relation('enemies', 18) ) {
 *     $newstud->remove_relation('enemies', 18);
 * }
 *
 * ###  Expell and Delete
 * $newstud->expell('Was lazy.');
 * $newstud->delete();                                                                            # object status = 'deleted'
 * </code>
 *
 * An instance of this object represents a row in a database table.
 * Basically you create your own class extending this class and
 * give it some inforation about the table, it's columnes and
 * relations to other tables (and SimpleORM objects that represent
 * those tables).  Simple ORM then provides standard get, set,
 * save, create, and delete methods to operate on that row.
 *
 * Object Caching
 * 
 * All objects are cached in a global object cache, so that
 * multiple instances of the same object row never simultaneously
 * exist in memory.  If it were allowed, then changes to one
 * object would not be reflected in the other object.  Object
 * caching is done by simple "object forwarding": the first
 * object created for a given row in the database (e.g. user Fred
 * Jones) is created and is a fully qualified object with that
 * object's data and relation cache, the second time a new object
 * is requested for the same object, the cache is consulted and
 * instead of a fully qualified object, a "forwarded" object is
 * returned that has no local data cache (e.g. the second object
 * is a "symlink to the first Fred Jones object").  When any
 * method is called on the "forwarded" object, that method call
 * is just routed and re-called on the actual fully qualified
 * object.
 * 
 * Object Forwarding and Custom Methods
 * 
 * Because of this, any methods in your extended class that want to
 * access object variables, include this line as the first line of
 * the method code:
 *
 * <code>if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();</code>
 *
 * This just quickly checks if the object is a forwarded object
 * and the {@link do_object_forward_method()} method takes care of the
 * rest.
 * 
 * Database Compatibility
 * 
 * Most major functions have been tested and just worked on
 * PostgreSQL, MySQL and SQLite with little or no modification to
 * the original code, due to the fact that the databse operations
 * are based on PDO, which does most of the database abstraction.
 * Most of the rest of the actual SQL is just generally
 * cross-compatible with probably 90% of SQL parsers, so the list
 * of supported engines may be higher than just these three.
 *
 * The primary differences so far in databases are in how you use
 * specific features.  For example, in PostgreSQL,
 * auto-incrementing values are done with named sequences, so the
 * '$column_sequences' definition is required (assoc array, key =
 * column name, value = sequence name), but you may omit that for
 * engines without named sequences.  This feature essentially
 * shadows the PDO behavior.
 *
 * @see $schema
 * @see $relations
 * @author Dave Buchanan <dave@joesvolcano.net>
 * @package SimpleORM
 * @version $Id: SimpleORM.class.php,v 1.3 2009/10/30 18:55:35 dave Exp $
 */
class SimpleORM {
    /**
     * $table - the table who's rows this object represents
     *
     * Overridden by child-classes.
     */
    protected $table = null;
    /**
     * $primary_key - the primary key for this table
     *
     * Overridden by child-classes.  A flat array of column names.
     */
    protected $primary_key = array();
    /**
     * $column_sequences - what columns have sequences by name
     *
     * Overridden by child-classes.  An assoc where the keys are
     * column names and the values are sequence names.
     */
    protected $column_sequences = array();
    /**
     * $schema - Define the columns and simple validation criteria
     *
     * Overridden by child-classes.  This is an assoc array of
     * column names where every value is an assoc array of
     * parameters to define column names and validation settings.
     * The validation criteria are defined by the {@link
     * do_validation()} function in the {@link
     * validation.inc.php} file.
     *
     * These validation checks can be run using the validation
     * functions {@link validate()}, {@link
     * validate_column_value()} or {@link extract_and_validate()}
     */
    protected $schema = array();
    /**
     * $relations - Overridden by child classes to allow quick related object access
     * 
     * Relations are simple ways to link to one or a list of objects
     * that directly relate with foreign keys in the database.  The
     * relations are accessible as object variables just like columns
     * are accesses, but they contain actual SimpleORM object(s).
     * This makes it trivial to do drill down to values like this:
     *
     * <code>echo $student->classes[0]->class->instructor->name;</code>
     *
     * The foreign objects accessed through relations need to be set
     * up with their own SimpleORM-derived class in some file.  All
     * relation definitions include the filename to require_once(),
     * and the name of the class that should be used to instantiate
     * the object.
     *
     * There are currently 2 kinds of relations an object can have:
     * 'has_one' and 'has_many'.
     *
     * The 'has_one' type is for when your table row has an ID or
     * other Primary key to another entity in the database.  The
     * definition for a 'has_one' relationship simply names the
     * column(s) that have the PKey of the foreign table.  When
     * getting the related object, if it is not found the
     * relation accessor returns NULL.
     *
     * The 'has_many' type is for when there is another table in
     * the database that has your object's primary key.  This
     * usually implies that your object "owns" that other object,
     * but it always means that for every one of your object
     * there are 0 or more objects of the other type.  The
     * definition for this type includes the table name and
     * primary key for the foreign object which must be selected
     * in order to instantiate the new object.  It also includes
     * the names of the columns in the foreign table that include
     * your table's primary key.  The accessor for this relation
     * will return an array of all objects found.
     *
     * Relation data is cached the first time it is called, and
     * you must manually call {@link reset_state()} or {@link
     * clear_relation_cache()} to get fresh data.
     *
     * 'has_many' relation definitions can have some custom
     * parameters to tune the SQL that is run.  The
     * 'custom_where_clause' can add special filters to the list
     * of objects returned, e.g. only getting 'active' items.
     * The 'order_by_clause' can provide quick database-side
     * sorting.  This is put directly into the ORDER BY clause,
     * so you only have access to sort by columns in the foreign
     * table.  This server-side sorting is MUCH faster than
     * manually sorting objects once they are in PHP.
     *
     */
    protected $relations = array();
    protected $dbh = null;
    protected $dbh_type = null;
    protected $pk_values = array();
    protected $data = array();
    protected $relation_data = array();
    protected $columns_to_save = array();
    protected $object_forward = null;
    protected $cache_key = null;
    protected $obj_state = null;

    /** 
     * __construct
     *
     * This is also where object caching and retrieval is
     * performed.  The cache key is made by concatenating the
     * object 's class, table and primary key values into a
     * string.  If the object has been previously cached, it will
     * erase as much local object data as possible and set {@link
     * $object_forward}.  This will cause all the methods after
     * this to just "redirect" the call to the original cached
     * object.
     *
     * Object caching is not done if any primary key values
     * passed were null as this often means that the object row
     * has not been created yet.  This will also mean that the
     * object's state will be 'not_created'.  Once a successful
     * {@link create()} call has completed, then the state will
     * become 'active' and the objcect will be added to the
     * cache.
     *
     * @param mixed $pk_value            Either an array of values representing the primary key for this object, or a single non-array value if the PKey is only 1 column (or an empty array for a pseudo object)
     * @param mixed $select_star_data    If you got the PKey from a SQL query you just ran and you also got the full "select * from ..." for that table row, just pass that (assoc) array here and it'll save SimplORM from having to re-query the data
     */
    public function __construct($pk_value = array(), $select_star_data = null) {
        global $SimpleORM_OBJECT_CACHE;

        ###  Handle one or an array or PK values
        if ( is_array( $pk_value ) ) {
            foreach ($this->primary_key as $col) { $this->pk_values[$col] = array_shift($pk_value); }
        }
        else { $this->pk_values[$this->primary_key[0]] = $pk_value; }

        ###  Allow them to supply a "SELECT * " output for this row if they already had it...
        if ( is_array( $select_star_data ) ) {
            ###  For now, just trust it...  Maybe later we'll make it check that all the cols are present...
            $this->data = $select_star_data;
        }

        ###  Reference a cached object if available (skip caching if any of the PK values are NULL)
        $has_null_pk_values = false;
        $pk_string = array();  foreach ($this->primary_key as $col) { if ( ! isset($this->pk_values[$col]) || is_null($this->pk_values[$col]) ) $has_null_pk_values = true;  $pk_string[] = $this->pk_values[$col]; }
        if ( ! $has_null_pk_values ) {
            $this->cache_key = get_class($this). '||--||'. $this->table .'||--||'. join('||--||', $pk_string); 
            if ( array_key_exists($this->cache_key, $SimpleORM_OBJECT_CACHE ) ) {
                $this->object_forward = $SimpleORM_OBJECT_CACHE[$this->cache_key];
#            bug("USING CACHE: ".$this->cache_key);
            
                ###  Blank out some $this data to save memory
                unset($this->data, $this->table, $this->schema, $this->relations, $this->primary_key, $this->pk_values, $this->dbh, $this->columns_to_save, $this->obj_state, $this->cache_key );
            }
            else { $SimpleORM_OBJECT_CACHE[$this->cache_key] = $this; }
        }
    }
    ///  Wait as late as possible to get the DBH...
    protected function load_dbh() {
        ###  Get the database handle
        if ( is_null( $this->dbh ) ) {
            $this->dbh      =& $this->provide_dbh();
            $this->dbh_type =& $this->provide_dbh_type();
        }
        $GLOBALS['orm_dbh'] = $this->dbh;
    }

    protected function provide_dbh() {}
    protected function provide_dbh_type() {}
    protected function include_prefix() { return ''; }
 
    /** 
     * exists() - does this row exist and have normal state?
     *
     * Returns false if the object state is either 'not_created' or 'deleted', otherwise the state must be 'active' and it returns true
     */
    public function exists() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        if ( $this->obj_state == 'not_created') return false;
        return ( $this->obj_state == 'active' || ! is_null($this->get($this->primary_key[0])) );
    }
    /**
     * reset_state() - Quick reset all caches and state
     *
     * Call this if you suspect that anything Non-SimpleORM
     * related has modified the row behind your object, or if any
     * of your relations may have been added or removed.  This
     * will reset all cached data and the object's state.
     *
     * @see clear_relation_cache()
     */
    public function reset_state() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $this->data = array();
        $this->obj_state = null;
        $this->clear_relation_cache();
        $this->post_reset_state_handler();
    }
    /** 
     * post_reset_state_handler() - To be overridden by child-classes that have their own local caching
     */
    protected function post_reset_state_handler() { return true; }
    /** 
     * dbh() - Quick access to the actual PDO database handle
     */
    public function dbh() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        return $this->dbh;
    }
        
    
    #########################
    ###  The actual get(), and set(), and save() for working with multiple columns
    
    /** 
     * get() - Get column values and/or relations
     *
     * @param mixed $arg1    Either an array of column or relation names, a single name, or multiple names passed as arguments (not as an array) e.g. $my_user->get('name','email','birthday')
     */
    public function get($arg1) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('get','__get','get_relation','__isset','set','__set','__unset','exists','call_user_func_array','do_object_forward_method');
        START_TIMER('SimpleORM get', ORM_SQL_PROFILE);

        ###  Quick return for vast common case
        if ( $this->obj_state != 'deleted' && count( func_get_args() ) == 1 && isset($arg1) && (! is_array($arg1)) && isset($this->data[$arg1]) ) { END_TIMER('SimpleORM get', ORM_SQL_PROFILE);  return $this->data[$arg1]; }

        $cols_from_array = false;
        $columns = func_get_args();

        ###  Allow params passed in an array or as args
        if ( count( $columns ) == 1 && is_array(array_shift(array_values($columns))) ) { $cols_from_array = true;  $columns = array_shift(array_values($columns)); };
        
        ###  Check out the column names (AND relations)
        foreach ($columns as $col) {
            if ( !    array_key_exists($col, $this->schema)
                 && ! array_key_exists($col, $this->relations)
                ) {
                trigger_error('Call to get() invalid column '. get_class($this) .'::'. $col .' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
                return false;
            }
        }

        ###  Must be installed and active
        if ( ! is_null($this->obj_state) && $this->obj_state != 'active' ) {
            trigger_error( 'Call to get() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        ###  Get all the data if it's not already cached...
        if ( $this->table && empty( $this->data ) && ( is_null($this->obj_state) || $this->obj_state == 'active' ) ) {
            if ( is_null( $this->dbh ) ) $this->load_dbh();
            START_TIMER('SimpleORM get query', ORM_SQL_PROFILE);
            $values = array();
            $pk_where = array();  foreach ($this->primary_key as $col) { $pk_where[] = "$col = ?";  $values[] = $this->pk_values[$col]; }
            $sql = "SELECT *
                      FROM ". $this->table ."
                     WHERE ". join(' AND ', $pk_where) ."
                      "; # " DUMB PHP emacs syntax hiliting
            $sth = dbh_query_bind($sql, $values);
            ###  If the user has an active account then...
            $this->data = $sth->fetch(PDO::FETCH_ASSOC);

            $this->obj_state = empty($this->data) ? 'not_created' : 'active';
            END_TIMER('SimpleORM get query', ORM_SQL_PROFILE);
        }

        ###  Return an array of the requested columns
        $return_ary = array();
        foreach ($columns as $col) {
            ###  Prefer data columns first (in case people define a relation the same name as a column --> POSSIBLE INFINITE LOOP)
            if ( array_key_exists($col, $this->schema) ) { $return_ary[] = $this->data[$col]; }
            ###  Otherwise handle relation access
            else                                         { $return_ary[] = $this->get_relation( $col ); }
        }

        END_TIMER('SimpleORM get', ORM_SQL_PROFILE);
        return ( ! $cols_from_array && count($return_ary) == 1 ) ? $return_ary[0] : $return_ary;
    }

    /** 
     * set() - Get column values and/or relations
     *
     * This sets the local value of the object columns.  To update the row in the database you must later call {@link save()}.
     *
     * @param array $to_set    An assoc array of columns to set
     * @see save()
     * @see set_and_save()
     */
    public function set($to_set) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('set','set_and_save','__set','__unset','exists','call_user_func_array','do_object_forward_method');
        START_TIMER('SimpleORM set', ORM_SQL_PROFILE);

        if ( ! is_array($to_set) ) die("You must pass set() an array!");
        
        ###  Check out the column names
        foreach (array_keys($to_set) as $col) {
            if ( !    array_key_exists($col, $this->schema) ) {
                trigger_error( 'Call to set() invalid column '. get_class($this) .'::'. $col . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
                return false;
            }
        }

        ###  Get all the data if it's not already cached...
        if ( empty( $this->data ) ) $this->get($this->primary_key[0]);

        ###  Must be installed and active
        if ( ! $this->exists() && $this->obj_state != 'active' ) {
            trigger_error( 'Call to set() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }
        
        ###  Set the new values in $this->data
        foreach (array_keys($to_set) as $col) {
            ###   If on the first edit they want to remember the OLD values, then DO...
            if ( $this->remember_old_values() && ! $this->columns_to_save[ $col ] ) {
                $this->data['__old__'][ $col ] = $this->data[ $col ];
            }
            $this->columns_to_save[ $col ] = true;
            $this->data[ $col ] = $to_set[ $col ];
        }
 
        END_TIMER('SimpleORM set', ORM_SQL_PROFILE);
        return true;
    }
    public function remember_old_values() { return false; }
    /** 
     * unsaved_columns() - Get a quick list of the cols that have been locally {@link set()}, but not yet saved using {@link save()}
     */
    public function unsaved_columns() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        return $this->columns_to_save;
    }

    public function get_table() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        return $this->table;
    }
    public function get_primary_key() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        return $this->primary_key;
    }

    /** 
     * set_and_save() - Convenience, do a {@link set()} then a {@link save()}
     */
    public function set_and_save($to_set) { $this->set($to_set);  $this->save(); }
    /** 
     * save() - Take all the columns set locally, and send an UPDATE to the database
     */
    public function save() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('save','set_and_save','exists','call_user_func_array','do_object_forward_method');

        ###  Must be installed and active
        if ( ! $this->exists() && $this->obj_state != 'active' ) {
            trigger_error( 'Call to save() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }
        if ( empty( $this->columns_to_save ) ) return true;
        
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        if ( ! $this->pre_save_handler($this->columns_to_save) ) return false;
        
        ###  Update the values
        $values = array();
        ksort($this->columns_to_save); # help in query caching
        $set_clause = array();  foreach (array_keys($this->columns_to_save) as $col) { $set_clause[] = "$col = ?";  $values[] = $this->data[$col]; }
        $pk_where = array();    foreach ($this->primary_key                 as $col) { $pk_where[]   = "$col = ?";  $values[] = $this->pk_values[$col]; }
        $sql = "UPDATE ". $this->table ."
                   SET ". join(',', $set_clause) ."
                 WHERE ". join(' AND ', $pk_where) ."
                  "; #"
        $sth = dbh_do_bind($sql, $values);

        if ( ! $this->post_save_handler($this->columns_to_save) ) return false;
        
        ###  Reset the to-be-saved queue
        $this->columns_to_save = array();
        if ( isset( $this->data['__old__'] ) ) unset( $this->data['__old__'] );
        
        return true;
    }
    /** 
     * pre_save_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function pre_save_handler($columns_to_save) { return true; }
    /** 
     * post_save_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function post_save_handler($columns_to_save) { return true; }

    
    #########################
    ###  Relations access

    /**
     * get_relation() - Directly get a relation, can also be done with get()
     */
    public function get_relation($name, $force_ordered_list = true) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('get_relation','get','__get','__isset','set','__set','__unset','exists','call_user_func_array','do_object_forward_method');

        ###  Must be installed and active
        if ( ! is_null($this->obj_state) && $this->obj_state != 'active' ) {
            trigger_error( 'Call to get_relation() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }
        ###  Return the cached answer if it's there
        if ( isset( $this->relation_data[$name] ) ) return( $force_ordered_list && is_array( $this->relation_data[$name] ) ? array_values( $this->relation_data[$name] ) : $this->relation_data[$name] );

        ###  Must have a definition
        if ( ! array_key_exists($name, $this->relations) ) {
            trigger_error( 'Use of invalid relation '. get_class($this) .'::'. $name . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        $rel = &$this->relations[$name];

        ###  Relationship types
        if ( $rel['relationship'] == 'has_one' ) {
            START_TIMER('SimpleORM get_relationship has_one', ORM_SQL_PROFILE);
            ###  Read the 'columns' definition
            $rel_pk_values = array();
            $are_null_values = false;
            if ( is_array($rel['columns']) ) { foreach ( $rel['columns'] as $col ) { if (( $rel_pk_values[] = $this->get($col)            ) === null) $are_null_values = true; } }
            else                                                                   { if (( $rel_pk_values[] = $this->get($rel['columns']) ) === null) $are_null_values = true; }
        
            ###  Can't do this type with NULL values
            if ( $are_null_values ) { END_TIMER('SimpleORM get_relationship has_one', ORM_SQL_PROFILE);  return null; }

            $class = $rel['class'];
            if ( ! class_exists($class) ) require_once( $this->include_prefix() . $rel['include']);

            $this->relation_data[$name] = new $class ($rel_pk_values);
            END_TIMER('SimpleORM get_relationship has_one', ORM_SQL_PROFILE);
        }
        else if ( $rel['relationship'] == 'has_many' ) {
            START_TIMER('SimpleORM get_relationship has_many', ORM_SQL_PROFILE);
            
            ###  Read the 'foreign_key_columns' definition
            $rel_pk_values = array();
            $are_null_values = false;
            if ( is_array($rel['foreign_key_columns']) ) { $fk_columns =        $rel['foreign_key_columns']; }
            else                                         { $fk_columns = array( $rel['foreign_key_columns'] ); }

            if ( is_array($rel['foreign_table_pkey']) ) { $foreign_pkey =        $rel['foreign_table_pkey']; }
            else                                        { $foreign_pkey = array( $rel['foreign_table_pkey'] ); }
            
            if ( is_null( $this->dbh ) ) $this->load_dbh();
            $values = array();
            $where = array();  foreach ($fk_columns as $i => $col) { $where[] = "$col = ?";  $values[] = $this->pk_values[$this->primary_key[$i]]; }
            if ( ! empty($rel['custom_where_clause']) ) $where[] = $rel['custom_where_clause'];
            $sql = "SELECT *
                      FROM ". $rel['foreign_table'] ."
                     ". ((! empty($where)                  ) ? "WHERE ". join(' AND ', $where)      : "") ."
                     ". ((! empty($rel['order_by_clause']) ) ? "ORDER BY ". $rel['order_by_clause'] : "") ."
                      "; # " DUMB emacs PHP syntax hiliting
            $sth = dbh_query_bind($sql, $values);

            $class = $rel['class'];
            if ( ! class_exists($class) ) require_once($this->include_prefix() . $rel['include']);

            ###  Get the data and convert it into an array of objects...
            $data = $sth->fetchAll(PDO::FETCH_ASSOC);
            $obj_list = array();
            foreach ( $data as $row ) {
                $pkey_vals = array();  foreach ($foreign_pkey as $col) { $pkey_vals[] = $row[$col]; }
                $obj_list[] = new $class ($pkey_vals,$row);
            }

            $this->relation_data[$name] = $obj_list;

            END_TIMER('SimpleORM get_relationship has_many', ORM_SQL_PROFILE);
        }
        else if ( $rel['relationship'] == 'many_to_many' ) {
            START_TIMER('SimpleORM get_relationship many_to_many', ORM_SQL_PROFILE);
            
            ###  Read the 'foreign_key_columns' definition
            $rel_pk_values = array();
            $are_null_values = false;

            if ( is_array($rel['foreign_table_pkey']) ) { $foreign_pkey =        $rel['foreign_table_pkey']; }
            else                                        { $foreign_pkey = array( $rel['foreign_table_pkey'] ); }

            ###  If there are any 'join_table_fixed_values'
            $join_table_fixed_values = array();
            if ( isset($rel['join_table_fixed_values']) && is_array($rel['join_table_fixed_values']) ) {
                $join_table_fixed_values = $rel['join_table_fixed_values'];
            }
            
            if ( is_null( $this->dbh ) ) $this->load_dbh();
            $values = array();
            $where = array();  foreach ($this->pk_values         as $col => $val) { $where[] = "jt.$col = ?";  $values[] = $val; }
            foreach                    ($join_table_fixed_values as $col => $val) { $where[] = "jt.$col = ?";  $values[] = $val; }
            if ( ! empty($rel['custom_where_clause']) )        $where[] = $rel['custom_where_clause'];
            if ( isset($rel['change_status_instead_of_delete'])
                 && $rel['change_status_instead_of_delete']  ) $where[] = "jt.status = 'active'";
            $sql = "SELECT ft.*
                      FROM ". $rel['join_table'] ." jt 
                      JOIN ". $rel['foreign_table'] ." ft USING(". join(', ', $foreign_pkey) .") 
                      ". ((! empty($where)                  ) ? "WHERE ". join(' AND ', $where)      : "") ."
                      ". ((! empty($rel['order_by_clause']) ) ? "ORDER BY ". $rel['order_by_clause'] : "") ."
                      "; # " DUMB emacs PHP syntax hiliting
            $sth = dbh_query_bind($sql, $values);

            $class = $rel['class'];
            if ( ! class_exists($class) ) require_once($this->include_prefix() . $rel['include']);

            ###  Get the data and convert it into an array of objects...
            $data = $sth->fetchAll(PDO::FETCH_ASSOC);
            $obj_list = array();
            foreach ( $data as $row ) {
                $pkey_vals = array();  foreach ($foreign_pkey as $col) { $pkey_vals[] = $row[$col]; }
                $obj_list[join('||--||',$pkey_vals)] = new $class ($pkey_vals,$row);
            }

            $this->relation_data[$name] = $obj_list;

            END_TIMER('SimpleORM get_relationship many_to_many', ORM_SQL_PROFILE);
        }
        else {
            ###  Error if they use an invalid relationship type
            trigger_error( 'Invalid relationship type in '. get_class($this) .' Class definition '. get_class($this) .'::'. $name . ' referenced at '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return null;
        }
        
        ///  Force Ordered array, to hide the 'many_to_many' keyed indexes except when (we) or others want them
        return( $force_ordered_list && is_array( $this->relation_data[$name] ) ? array_values( $this->relation_data[$name] ) : $this->relation_data[$name] );
    }
    /**
     * has_relation() - for many_to_many relationships only, see if two objects are related
     *
     * $relation - the name of the relation
     * $pkey - an array of the primary key values of the other object
     */
    public function has_relation($relation, $pkey) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('set_complete_relation', 'get_relation','get','__get','__isset','set','__set','__unset','exists','call_user_func_array','do_object_forward_method');
        ###  Must be many to many
        if ( $this->relations[$relation]['relationship'] != 'many_to_many' )  {
            trigger_error( 'Call to has_relation() when not a many_to_many '. get_class($this) .'::'. $name . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        $relation_key = is_array($pkey)?join('||--||',$pkey):$pkey;

        return array_key_exists($relation_key,$this->get_relation($relation, false));
    }
    /**
     * add_relation() - 
     * 
     * for many_to_many relationships only
     *
     * $relation - the name of the relation
     * $pkey - an array of the primary key values of the other object
     */
    public function add_relation($relation, $pkey) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        if($this->has_relation($relation, $pkey)) return true;
        
        if(!is_array($pkey)) $pkey = array($pkey);
        $relation_key = join('||--||',$pkey);
        $rel = &$this->relations[$relation];
        $class = $rel['class'];
        $this->relation_data[$relation][$relation_key] = new $class ($pkey);
        if ( is_array($rel['foreign_table_pkey']) ) { $foreign_pkey =        $rel['foreign_table_pkey']; }
        else                                        { $foreign_pkey = array( $rel['foreign_table_pkey'] ); }
        
        ###  If there are any 'join_table_fixed_values'
        $join_table_fixed_values = array();
        if ( isset($rel['join_table_fixed_values']) && is_array($rel['join_table_fixed_values']) ) {
            $join_table_fixed_values = $rel['join_table_fixed_values'];
        }
            
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        $fields = array(); $q_marks = array(); $values = array();
        foreach( $this->primary_key       as         $pk ) { $fields[] = $pk;  $q_marks[] = "?"; $values[] = $this->pk_values[$pk];}
        foreach( $foreign_pkey            as $i   => $pk ) { $fields[] = $pk;  $q_marks[] = "?"; $values[] = array_key_exists($pk,$pkey)?$pkey[$pk]:$pkey[$i];}
        foreach( $join_table_fixed_values as $col => $val) { $fields[] = $col; $q_marks[] = "?"; $values[] = $val;}
        $sql = "INSERT INTO ". $rel['join_table'] ." (". join(',',$fields) .") 
                VALUES (". join(',', $q_marks).") ";
        $sth = dbh_do_bind($sql, $values);
        
        return true;
    }
    /**
     * remove_relation() - 
     * 
     * for many_to_many relationships only
     *
     * $relation - the name of the relation
     * $pkey - an array of the primary key values of the other object
     */
    public function remove_relation($relation, $pkey) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        if(!$this->has_relation($relation, $pkey)) return true;
        
        if(!is_array($pkey)) $pkey = array($pkey);
        $relation_key = join('||--||',$pkey);
        $rel = &$this->relations[$relation];
        unset($this->relation_data[$relation][$relation_key]);
        if ( is_array($rel['foreign_table_pkey']) ) { $foreign_pkey =        $rel['foreign_table_pkey']; }
        else                                        { $foreign_pkey = array( $rel['foreign_table_pkey'] ); }
        
        ###  If there are any 'join_table_fixed_values'
        $join_table_fixed_values = array();
        if ( isset($rel['join_table_fixed_values']) && is_array($rel['join_table_fixed_values']) ) {
            $join_table_fixed_values = $rel['join_table_fixed_values'];
        }
            
        ###  Assemble the WHERE clause
        $values = array();
        $where = array();  foreach ($this->pk_values         as $col => $val) { $where[] = "$col = ?";               $values[] = $val; }
        foreach                    ($pkey                    as $i   => $val) { $where[] = $foreign_pkey[$i]." = ?"; $values[] = $val; }
        foreach                    ($join_table_fixed_values as $col => $val) { $where[] = "$col = ?";               $values[] = $val; }
        if ( ! empty($rel['custom_where_clause']) ) $where[] = $rel['custom_where_clause'];

        ###  Assemble the SQL, (either UDPATE or DELETE)
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        if ( isset($rel['change_status_instead_of_delete']) && $rel['change_status_instead_of_delete'] ) {
            $sql = "UPDATE ". $rel['join_table'] ."
                       SET status = '". ( ( is_string($rel['change_status_instead_of_delete']) && $rel['change_status_instead_of_delete'] != '1' )
				                          ? $rel['change_status_instead_of_delete']
				                          : 'inactive'
				                          ) ."'
                       ". ( isset($rel['update_inactive_date'] )
							? ', '. ( ( is_string($rel['update_inactive_date']) && $rel['update_inactive_date'] != '1' ) ? $rel['update_inactive_date'] : 'inactive_date' ) .' = now()'
							: ''
							) ."
                      ";
            $where[] = "status = 'active'";
        } else {
            $sql = "DELETE FROM ". $rel['join_table'];
        }
        $sql .= " WHERE ". join(' AND ', $where);
        $sth = dbh_do_bind($sql, $values);
        
        return true;
    }
    /**
     * set_complete_relation() - 
     * 
     * for many_to_many relationships only
     *
     * $relation - the name of the relation
     * $pkeys - an array of the primary key values of the other objects
     */
    public function set_complete_relation($relation, $pkeys) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();

        ###  This call is expensive enough, clear relation cache before to assure reliability...
        unset($this->relation_data[$relation]);
        
        $old_relations = $this->get_relation($relation, false);
        foreach($pkeys as $pkey) {
            $relation_key = is_array($pkey)?join('||--||',$pkey):$pkey;
            ##  Skip ones already set and in to_set
            if(array_key_exists($relation_key,$old_relations)) {
                unset($old_relations[$relation_key]);
            } 
            ## Set ones not already set but need to be
            else {
                $this->add_relation($relation,$pkey);
            }
        }
        foreach($old_relations as $key => $rel) {
            ## Remove ones not in the to_set
            $pkey = explode('||--||',$key);
            $this->remove_relation($relation,$pkey);
        }
        
        return true;
    }
    /**
     * clear_relation_cache() - clear just relation cache, but not column data
     */
    public function clear_relation_cache() {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $this->post_clear_relation_cache_handler();
        return $this->relation_data = array();
    }
    /** 
     * post_clear_relation_cache_handler() - To be overridden by child-classes that have their own local caching
     */
    protected function post_clear_relation_cache_handler() { return true; }
    


    #########################
    ###  Create
    
    /** 
     * create() - Do a databse INSERT
     *
     * This formulates an INSERT query using JUST the columns you
     * pass it in the assoc array.  Any other values are assumed
     * to be NULL-able, or have default values in the DB.
     *
     * Because of default values and database-side triggers, it
     * doesn't keep the data you just passed it in local data
     * cache.  The next time you call a {@link get()} call it
     * will do a new SELECT query.
     *
     * After insert in order to get the new primary key, it may
     * use the {@link $column_sequences} to get
     * seqeunce-generated values.
     *
     * Also adds the object to the cache after a successful insert.
     *
     */
    public function create($to_set) {
        global $SimpleORM_OBJECT_CACHE;
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('create','exists','call_user_func_array','do_object_forward_method');
    
        ###  Must be not installed
        if ( $this->exists() || $this->obj_state != 'not_created' ) {
            trigger_error( 'Call to create() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        ###  Check in advance that we will be able to get the new Primary Key values...
        if ( $this->dbh_type == 'pg' ) {
            foreach ($this->primary_key as $col) {
                if ( ! isset($to_set[$col]) && ! isset($this->column_sequences[$col]) ) {
                    trigger_error( 'In create, could not proceed because I would not be able to get complete primary key after insert.  You probably need to define a "column_sequences" definition in your SimpleORM object'. get_class($this) .'::'. $col . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
                }
            }
        }
        
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        if ( ! $this->pre_create_handler($to_set) ) return false;
        
        ###  Insert the new row
        ksort($to_set); # help in query caching
        $fields = array();  $q_marks = array();  $values = array();
        foreach ($to_set as $col => $v) { $fields[] = $col;  $q_marks[] = "?";  $values[] = $v; }
        $sql = "INSERT INTO ". $this->table ." (". join(',', $fields) .")
                VALUES (". join(',', $q_marks) .")
                  "; #"
        $sth = dbh_do_bind($sql, $values);

        ###  Populate $this->pk_values
        foreach ($this->primary_key as $col) {
            if ( isset($to_set[$col]) ) {
                $this->pk_values[$col] = $to_set[$col];
            }
            else if ( $this->dbh_type == 'pg' && isset($this->column_sequences[$col]) ) {
                $this->pk_values[$col] = $this->dbh->lastInsertId($this->column_sequences[$col]);
            }
            else if ( $this->dbh_type != 'pg' ) {
                $this->pk_values[$col] = $this->dbh->lastInsertId();
            }
            ###  (PostgreSQL only) Already checking for the 'else' above BEFORE the insert is done...
            else {
                trigger_error( 'What the?'. get_class($this) .'::'. $col . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            }
        }
        $this->obj_state = null;
        
        ###  Add to cache
        $pk_string = array();  foreach ($this->primary_key as $col) { $pk_string[] = $this->pk_values[$col]; }
        $this->cache_key = get_class($this). '||--||'. $this->table .'||--||'. join('||--||', $pk_string);
        $SimpleORM_OBJECT_CACHE[$this->cache_key] = $this;
            
        if ( ! $this->post_create_handler($to_set) ) return false;
        
        return true;
    }
    /** 
     * pre_create_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function pre_create_handler($to_set) { return true; }
    /** 
     * post_create_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function post_create_handler($to_set) { return true; }


    #########################
    ###  Delete
    
    /** 
     * delete() - Do a databse DELETE
     *
     * Also removes the object from the cache, and sets the state to 'deleted'.  You can't do anything with the object after this point.
     */
    public function delete() {
        global $SimpleORM_OBJECT_CACHE;
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('delete','exists','call_user_func_array','do_object_forward_method');

        ###  Must be installed and active
        if ( ! is_null($this->obj_state) && $this->obj_state != 'active' ) {
            trigger_error( 'Call to delete() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }
        
        if ( is_null( $this->dbh ) ) $this->load_dbh();
        if ( ! $this->pre_delete_handler() ) return false;
        
        ###  Update the values
        $values = array();
        $pk_where = array(); foreach ($this->primary_key as $col) { $pk_where[]   = "$col = ?";  $values[] = $this->pk_values[$col]; }
        $sql = "DELETE FROM ". $this->table ."
                 WHERE ". join(' AND ', $pk_where) ."
                  "; #"
        $sth = dbh_do_bind($sql, $values);

        ###  Reset any data beside the PK values
        unset($this->data, $this->dbh, $this->columns_to_save );
        $this->obj_state = 'deleted';

        if ( ! $this->post_delete_handler() ) return false;
        
        ###  Free up the cache item, Any other objects that are still object_forwarded
        ###    to this will also now appear "deleted".  If you're messy, this can bite you!
        if ( array_key_exists($this->cache_key, $SimpleORM_OBJECT_CACHE ) ) {
            unset( $SimpleORM_OBJECT_CACHE[$this->cache_key] );
        }
            
        return true;
    }
    /** 
     * pre_delete_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function pre_delete_handler() { return true; }
    /** 
     * post_delete_handler() - To be overridden by child-classes, like a trigger, if it returns false the primary operation exits (but no rollback!)
     */
    protected function post_delete_handler() { return true; }



    ########################
    ###  Pre-Cache Sub-Relations
    
    ###  Need to expose some internals for this..
    protected function __relations() { if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();  return $this->relations; }
    protected function __schema()    { if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();  return $this->schema; }

    /**
     * pre_load_sub_relations() - Acts like get_relation(), but pre-load sub-object's in one query (for "has_many" relations only)
     *
     * This will return just like get_relation() except that first it will create a Monster query
     * to join in a bunch of other tables so it can pre-load them all at once.  NOTE: this will
     * spin many nested levels deep looking for "has_one" relationships.  But it will only pre-load
     * "has_one" relationships (using an OUTER JOIN).
     *
     * NOTE: The "where_clause" in your "has_many" relationship will be used in this new Monster query
     * which also will have lots of other tables joined in.  If some of these joined in tables have the
     * same column names as your primary table, then it's possible that some of the coluns you reference
     * in your "where_clause" may become ambiguous references.  To avoid this, it's best to just prefix
     * all column names in your "has_many" relation's "where_clause" with the full table name as the alias.
     *
     */
    public function pre_load_sub_relations( $name, $sub_relations_to_load, $force_ordered_list = true ) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $caller_funcs_to_ignore = array('pre_load_sub_relations','get','__get','__isset','set','__set','__unset','exists','call_user_func_array','do_object_forward_method');

        ###  Must be installed and active
        if ( ! is_null($this->obj_state) && $this->obj_state != 'active' ) {
            trigger_error( 'Call to pre_load_sub_relations() on a "'. get_class($this) .'" object that is "'. $this->obj_state .'" in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }
        ###  DON'T RETURN a CACHED ANSWER, Re-Run every time...
#        if ( isset( $this->relation_data[$name] ) ) return( $force_ordered_list && is_array( $this->relation_data[$name] ) ? array_values( $this->relation_data[$name] ) : $this->relation_data[$name] );

        ###  Must have a definition
        if ( ! array_key_exists($name, $this->relations) ) {
            trigger_error( 'Use of invalid relation '. get_class($this) .'::'. $name . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        $rel = &$this->relations[$name];

        ###  Must have a definition
        if ( $rel['relationship'] != 'has_many' ) {
            trigger_error( 'Call to pre_load_sub_relations() on a relation that is not "has_many": '. get_class($this) .'::'. $name . ' in '. trace_blame_line($caller_funcs_to_ignore), E_USER_ERROR);
            return false;
        }

        START_TIMER('SimpleORM get_relationship has_many (pre_load_sub)', ORM_SQL_PROFILE);
            
        ###  Read the 'foreign_key_columns' definition
        $rel_pk_values = array();
        $are_null_values = false;
        if ( is_array($rel['foreign_key_columns']) ) { $fk_columns =        $rel['foreign_key_columns']; }
        else                                         { $fk_columns = array( $rel['foreign_key_columns'] ); }

        if ( is_array($rel['foreign_table_pkey']) ) { $foreign_pkey =        $rel['foreign_table_pkey']; }
        else                                        { $foreign_pkey = array( $rel['foreign_table_pkey'] ); }
            
        if ( is_null( $this->dbh ) ) $this->load_dbh();

        $class = $rel['class'];
        if ( ! class_exists($class) ) require_once($this->include_prefix() . $rel['include']);

        $alias = $rel['foreign_table'];
        $seen = array(get_class($this)); // Don't crawl back to THIS object...
        $sub_tables = array( 'alias_letters' => range('a','z'),
                             'aliases' => array( 'primary' => $class ),
                             'pkeys'   => array( 'primary' => ( is_array( $this->primary_key ) ? $this->primary_key : array( $this->primary_key ) ) ),
                             'joins'   => array( "FROM ". $rel['foreign_table'] ),
                             'selects' => array( $alias .'.*' ),
                             );
        $this->get_pre_load_sub_relations($class, $alias, $sub_relations_to_load, $seen, $sub_tables);

        $values = array();
        $where = array();  foreach ($fk_columns as $i => $col) { $where[] = "$alias.$col = ?";  $values[] = $this->pk_values[$this->primary_key[$i]]; }
        if ( ! empty($rel['custom_where_clause']) ) $where[] = $rel['custom_where_clause'];

        $sql = "SELECT ". join(',',$sub_tables['selects']) ."
                  ". join("\n         ",$sub_tables['joins']) ."
                 ". ((! empty($where)                  ) ? "WHERE ". join(' AND ', $where)      : "") ."
                 ". ((! empty($rel['order_by_clause']) ) ? "ORDER BY ". $rel['order_by_clause'] : "") ."
                  "; # " DUMB emacs PHP syntax hiliting
        if (ORM_SQL_DEBUG) trace_dump();
        if (ORM_SQL_DEBUG) bug($sql, $values);
        $sth = dbh_query_bind($sql, $values);


        ###  Get the data and convert it into an array of objects...
        $data = $sth->fetchAll(PDO::FETCH_ASSOC);


        $obj_list = array();
        foreach ( $data as $row ) {

            ###  Split data into groups
            $groups = array();
            foreach ( array_keys($row) as $col ) {
                if ( strpos($col, '___') === false ) $groups['primary'][$col] = $row[$col];
                else {
                    list($alias,$acol) = explode('___', $col);
                    $groups[ $alias ][$acol] = $row[$col];
                }
            }
            
            foreach ( $groups as $alias => $grow ) {
                /// Skip if the PKey is null
                $any_pkeys_were_null = false;
                $pkey_vals = array();
                foreach ($sub_tables['pkeys'][$alias] as $col) {
                    if ( is_null($grow[$col]) ) { $any_pkeys_were_null = true; }
                    else { $pkey_vals[] = $grow[$col]; } 
                }
                if ( $any_pkeys_were_null ) continue; 

                $g_class = $sub_tables['aliases'][$alias];
                $obj = new $g_class ($pkey_vals,$grow);

                ###  If this is the Primary table, then add it to the object list
                ###    ( otherwise, we have pre-loaded the object, and we're done)
                if ( $alias == 'primary' ) $obj_list[] = $obj;
            }
        }

        $this->relation_data[$name] = $obj_list;

        END_TIMER('SimpleORM get_relationship has_many (pre_load_sub)', ORM_SQL_PROFILE);
        
        ###  Force Ordered array, to hide the 'many_to_many' keyed indexes except when (we) or others want them
        return( $force_ordered_list && is_array( $this->relation_data[$name] ) ? array_values( $this->relation_data[$name] ) : $this->relation_data[$name] );
    }

    ###  Nested loop to find all has_one relations
    protected function get_pre_load_sub_relations($class, $alias, $sub_relations_to_load, &$seen, &$sub_tables, $obj = null) {
        $seen[] = $class;

        ###  Get a blank object
        if ( is_null( $obj ) ) $obj = new $class ();
        foreach ( $obj->__relations() as $name => $rel ) {
            if ( $rel['relationship'] != 'has_one'
                 || ! in_array( $name, $sub_relations_to_load )
                 ) continue;

            $sub_class = $rel['class'];
            if ( ! class_exists($sub_class) ) require_once($this->include_prefix() . $rel['include']);
            $sub_obj = new $sub_class ();

            ###  Record what we need to Join this table and get it's columns
            $sub_alias = str_repeat( array_shift( $sub_tables['alias_letters'] ), 4 );
            $sub_tables['aliases'][ $sub_alias ] = $sub_class;


            $local_pkey   = $rel['columns'];              if ( ! is_array($local_pkey) )   $local_pkey   = array( $local_pkey );
            $foreign_pkey = $sub_obj->get_primary_key();  if ( ! is_array($foreign_pkey) ) $foreign_pkey = array( $foreign_pkey );
            $on_clause = array();  foreach ( $local_pkey as $i => $col ) $on_clause[] = ( $alias .'.'. $local_pkey[$i] .' = '. $sub_alias .'.'. $foreign_pkey[$i] );
            $sub_tables['joins'][] = 'LEFT OUTER JOIN '. $sub_obj->get_table() .' '. $sub_alias .' ON ('. $alias .'.'. $rel['columns'] .' = '. $sub_alias .'.'. $foreign_pkey[0] .')';

            $select_cols = array(); foreach ( array_keys( $sub_obj->__schema() ) as $col ) $select_cols[] = ( $sub_alias .'.'. $col .' as '. $sub_alias .'___'. $col );
            $sub_tables['selects'][] = join(',',$select_cols);

            $sub_tables['pkeys'][ $sub_alias ] = $foreign_pkey;

            ###  Nest Deeper
            if ( ! in_array( $sub_class, $seen ) ) { ###  Stop infinite loops...
                $this->get_pre_load_sub_relations($sub_class, $sub_alias,  $sub_relations_to_load, $seen, $sub_tables, $sub_obj);
            }
        }
    }

    
    #########################
    ###  Virtual members for getting / setting each column name
    
    ###  These just use get() and set(), so they are "object_forward" safe
    public function __get($name)         { return $this->get($name); }
    public function __set($name, $value) { return $this->set(array($name => $value)); }
    public function __isset($name)       { return ! is_null($this->get($name)); }
    public function __unset($name)       { $this->set(array($name => null)); }


    #########################
    ###  Object Forwarding

    public function __call($name, $args){
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method($name);
        
        trigger_error( 'Call to undefined method '. get_class($this) .'::'. $name . ' in '. trace_blame_line(array('__call','do_object_forward_method')), E_USER_ERROR);
        return false;
    }
    protected function do_object_forward_method($method_name = null) {
        $trace = debug_backtrace();
        if ( ! isset( $method_name ) ) $method_name = $trace[1]['function'];
        $obj = $this->object_forward;
        return call_user_func_array(array(&$this->object_forward, $method_name), $trace[1]['args']);
    }


    #########################
    ###  Validation

    /**
     * extract() - Just extract the given cols from the form
     *
     * Returns 3 params:
     * <ol>
     *   <li>an assoc array of JUST the values that passed validation.  Some values might be scrubbed as well, like spaced trimmed if requested.  Use this array to later call {@link set()}.
     *   <li>boolean, true or false if all the validations passed
     *   <li>an assoc array of errors where the values is a numeric array in the style of the {@link do_validation()}
     * </ol>
     *
     * There are several variations of calling syntax:
     * <ol>
     *   <li>validate($form_to_validate, $col_name_1, $col_name_2, $col_name_3, ...)
     *   <li>validate($form_to_validate, $array_of_column_names)
     *   <li>validate($form_to_validate, $array_of_column_names, $col_name_prefix)
     * </ol>
     *
     * If the $form_to_validate is not passed then $_REQUEST is used.
     *
     * The $col_name_prefix is used when the assoc key names in
     * the form have a prefix, and the error assoc array also
     * needs to use that prefix.  This is common when 2 or more
     * elements are being edited simiultaneously in one web
     * interface and the fields of the different entities would
     * otherwise collide and be mixed (e.g. both a class and an
     * instructor being edited in the same form and they both
     * have a 'name' field, you could then prefix all the class
     * fields with 'class_' and all the instructor fields with
     * 'inst_'.  Note, that the prefix should NOT be already
     * included in the column names list.
     *
     */
    public function extract($form = null) { $tmp = func_get_args();  return call_user_func_array( 'form_extract', $tmp ); }

    /**
     * validate() - Take an assoc array, validate all parameters, and return good values, status and errors
     *
     * Returns 3 params:
     * <ol>
     *   <li>an assoc array of JUST the values that passed validation.  Some values might be scrubbed as well, like spaced trimmed if requested.  Use this array to later call {@link set()}.
     *   <li>boolean, true or false if all the validations passed
     *   <li>an assoc array of errors where the values is a numeric array in the style of the {@link do_validation()}
     * </ol>
     *
     * There are several variations of calling syntax:
     * <ol>
     *   <li>validate($form_to_validate, $col_name_1, $col_name_2, $col_name_3, ...)
     *   <li>validate($form_to_validate, $array_of_column_names)
     *   <li>validate($form_to_validate, $array_of_column_names, $col_name_prefix)
     * </ol>
     *
     * If the $form_to_validate is not passed then $_REQUEST is used.
     *
     * The $col_name_prefix is used when the assoc key names in
     * the form have a prefix, and the error assoc array also
     * needs to use that prefix.  This is common when 2 or more
     * elements are being edited simiultaneously in one web
     * interface and the fields of the different entities would
     * otherwise collide and be mixed (e.g. both a class and an
     * instructor being edited in the same form and they both
     * have a 'name' field, you could then prefix all the class
     * fields with 'class_' and all the instructor fields with
     * 'inst_'.  Note, that the prefix should NOT be already
     * included in the column names list.
     *
     */
    public function validate($form = null) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $tmp = func_get_args();  $tmp[] = $this->schema;  return call_user_func_array( 'validate', $tmp );
    }

    /**
     * extract_and_validate() - Combination function for {@link extract()} and {@link validate()}
     *
     * Extracts the values from the form hash passed, and passes
     * each through the {@link validate()} function and returns
     * it's output.
     *
     * Calling style is exactly the same as either {@link
     * extract()} or {@link validate()}, including prefix
     * functionality.
     *
     * @param string $col     The column name to validate.  It uses this to read the schema definition and get the criteria
     * @param mixed  $value   The value to be tested
     */
    public function extract_and_validate($form = null) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        $tmp = func_get_args();  $tmp[] = $this->schema;  return call_user_func_array( 'extract_and_validate', $tmp );
    }

    /**
     * validate_column_value() - Single value validations
     *
     * Returns the output of {@link do_validation()}.
     *
     * @param string $col     The column name to validate.  It uses this to read the schema definition and get the criteria
     * @param mixed  $value   The value to be tested
     */
    public function validate_column_value($col, $value) {
        if ( isset( $this->object_forward ) ) return $this->do_object_forward_method();
        return validate_column_value($col, $value, $this->schema);
    }
}


/**
 * validation.inc.php
 * 
 * This file contains data validation routines, especially for use by the {@link SimpleORM} class
 * @author Dave Buchanan <dave@StarkMVC.com>
 * @package StarkMVC
 * @version $Id: SimpleORM.class.php,v 1.5 2010/09/28 21:20:56 dave Exp $
 */

/**
 * form_extract() - Just extract the given cols from the form
 *
 * Returns 3 params:
 * <ol>
 *   <li>an assoc array of JUST the values that passed validation.  Some values might be scrubbed as well, like spaced trimmed if requested.  Use this array to later call {@link set()}.
 *   <li>boolean, true or false if all the validations passed
 *   <li>an assoc array of errors where the values is a numeric array in the style of the {@link do_validation()}
 * </ol>
 *
 * There are several variations of calling syntax:
 * <ol>
 *   <li>validate($form_to_validate, $col_name_1, $col_name_2, $col_name_3, ...)
 *   <li>validate($form_to_validate, $array_of_column_names)
 *   <li>validate($form_to_validate, $array_of_column_names, $col_name_prefix)
 * </ol>
 *
 * If the $form_to_validate is not passed then $_REQUEST is used.
 *
 * The $col_name_prefix is used when the assoc key names in
 * the form have a prefix, and the error assoc array also
 * needs to use that prefix.  This is common when 2 or more
 * elements are being edited simiultaneously in one web
 * interface and the fields of the different entities would
 * otherwise collide and be mixed (e.g. both a class and an
 * instructor being edited in the same form and they both
 * have a 'name' field, you could then prefix all the class
 * fields with 'class_' and all the instructor fields with
 * 'inst_'.  Note, that the prefix should NOT be already
 * included in the column names list.
 *
 */
function form_extract($form = null) {
    if ( is_null($form) ) $form = &$_REQUEST;
    $cols = array_slice( func_get_args(), 1 );
    $prefix = '';
    if ( count( $cols ) == 1 && is_array(array_shift(array_values($cols))) ) { $cols = array_shift(array_values($cols)); };
    if ( count( $cols ) == 2 && is_array(array_shift(array_values($cols)))
         &&                   ! is_array(array_pop(  array_values($cols))) ) { $prefix = array_pop(array_values($cols));  $cols = array_shift(array_values($cols)); };
    
    $ret_array = array();  foreach ($cols as $col) { if (isset($form[$prefix.$col])) $ret_array[$prefix.$col] = $form[$prefix.$col]; }
    return $ret_array;
}

/**
 * validate() - Take an assoc array, validate all parameters, and return good values, status and errors
 *
 * Returns 3 params:
 * <ol>
 *   <li>an assoc array of JUST the values that passed validation.  Some values might be scrubbed as well, like spaced trimmed if requested.  Use this array to later call {@link set()}.
 *   <li>boolean, true or false if all the validations passed
 *   <li>an assoc array of errors where the values is a numeric array in the style of the {@link do_validation()}
 * </ol>
 *
 * There are several variations of calling syntax:
 * <ol>
 *   <li>validate($form_to_validate, $col_name_1, $col_name_2, $col_name_3, ...)
 *   <li>validate($form_to_validate, $array_of_column_names)
 *   <li>validate($form_to_validate, $array_of_column_names, $col_name_prefix)
 * </ol>
 *
 * If the $form_to_validate is not passed then $_REQUEST is used.
 *
 * The $col_name_prefix is used when the assoc key names in
 * the form have a prefix, and the error assoc array also
 * needs to use that prefix.  This is common when 2 or more
 * elements are being edited simiultaneously in one web
 * interface and the fields of the different entities would
 * otherwise collide and be mixed (e.g. both a class and an
 * instructor being edited in the same form and they both
 * have a 'name' field, you could then prefix all the class
 * fields with 'class_' and all the instructor fields with
 * 'inst_'.  Note, that the prefix should NOT be already
 * included in the column names list.
 *
 */
function validate($form = null) {
    if ( is_null($form) ) $form = &$_REQUEST;
    $cols = array_slice( func_get_args(), 1 );
    $prefix = '';
    ///  Schema will be an array of arrays as the last param...
    if ( count( $cols ) > 1
         && is_array(array_pop(array_values($cols)))
         && is_array(array_shift(array_pop(array_values($cols))))
         ) { $schema = array_pop($cols); }
    else {
        ###  Error if they didn't pass a schema
        trigger_error( 'No schema passed as last argument to validate() in '. trace_blame_line(array('validate','extract_and_validate')), E_USER_ERROR);
        return null;
    }
    if ( count( $cols ) == 1 && is_array(array_shift(array_values($cols))) ) { $cols = array_shift(array_values($cols)); };
    if ( count( $cols ) == 2 && is_array(array_shift(array_values($cols)))
         &&                   ! is_array(array_pop(  array_values($cols))) ) { $prefix = array_pop(array_values($cols));  $cols = array_shift(array_values($cols)); };
    
    $to_set = array();  $errors = array();
    $all_ok = true;
    foreach ($cols as $col) {
        list($ok, $scrubbed_value, $col_errors) = validate_column_value($col, (isset($form[$prefix.$col]) ? $form[$prefix.$col] : null), $schema );
        if ( ! $ok ) {
            $errors[$prefix.$col] = $col_errors;
            $all_ok = false;
        }
        else { $to_set[$col] = $scrubbed_value; }
    }
    return array( $to_set, $all_ok, $errors );
}

/**
 * extract_and_validate() - Combination function for {@link extract()} and {@link validate()}
 *
 * Extracts the values from the form hash passed, and passes
 * each through the {@link validate()} function and returns
 * it's output.
 *
 * Calling style is exactly the same as either {@link
 * extract()} or {@link validate()}, including prefix
 * functionality.
 *
 * @param string $col     The column name to validate.  It uses this to read the schema definition and get the criteria
 * @param mixed  $value   The value to be tested
 */
function extract_and_validate($form = null) {
    if ( is_null($form) ) $form = &$_REQUEST;
    $cols = array_slice( func_get_args(), 1 );
    $prefix = '';
    ///  Schema will be an array of arrays as the last param...
    if ( count( $cols ) > 1
         && is_array(array_pop(array_values($cols)))
         && is_array(array_shift(array_pop(array_values($cols))))
         ) { $schema = array_pop($cols); }
    else {
        ###  Error if they didn't pass a schema
        trigger_error( 'No schema passed as last argument to extract_and_validate() in '. trace_blame_line(array('validate','extract_and_validate')), E_USER_ERROR);
        return null;
    }
    if ( count( $cols ) == 1 && is_array(array_shift(array_values($cols))) ) { $cols = array_shift(array_values($cols)); };
    if ( count( $cols ) == 2 && is_array(array_shift(array_values($cols)))
         &&                   ! is_array(array_pop(  array_values($cols))) ) { $prefix = array_pop(array_values($cols));  $cols = array_shift(array_values($cols)); };
    
    $extracted = form_extract($form, $cols, $prefix);
    list($to_set, $all_ok, $errors) = validate($extracted, $cols, $prefix, $schema);
    return array( $to_set, $all_ok, $errors );
}

/**
 * validate_column_value() - Single value validations
 *
 * Returns the output of {@link do_validation()}.
 *
 * @param string $col     The column name to validate.  It uses this to read the schema definition and get the criteria
 * @param mixed  $value   The value to be tested
 */
function validate_column_value($col, $value, $schema) {
    ###  Error out if not in schema
    if ( ! isset( $schema[ $col ] ) || ! is_array( $schema[ $col ] ) ) {
        ###  Error if they use an invalid relationship type
        trigger_error( 'Call to validate invalid column '. $col . ' in '. trace_blame_line(array('validate','extract_and_validate')), E_USER_ERROR);
        return null;
    }
    
    return do_validation( $col, $value, $schema[ $col ] );
}


/**
 * Main validation function
 *
 * The validation parameters are passed in an assoc array, (often
 * coming from the schema definition of a {@link SimpleORM}
 * derived object).
 *
 * These are some scrubbing operations:
 * <ul>
 *   <li>Default stripping off of leading and trailing whitespace from all values unless the 'no_strip_ws' parameter is passed.
 *   <li>If the 'format' param is 'decimal' it will strip off any leading '+' chars
 *   <li>If the 'format' param is 'bool' it will turn all values that are (! isset()) to an actual boolean false value.
 *   <li>If the 'format' param is 'credit_card_number' it will drop all space and dash ('-') chars
 *   <li>The 'not_empty_string' param will turn empty string values into an actual NULL value
 * </ul>
 * 
 * These are the different validation types:
 * <ul>
 *   <li>The 'required' param is checked first, and if it's present, but the value is not isset(), then it adds an error and returns.  If there is no value, and the 'required' param is not there it will then return success and do no further checks.
 *   <li>The 'maxlength' and 'minlength' to dtring length checks
 *   <li>The 'regex' and 'negative_regex' params check if the value passes the given regular expression (or not)
 *   <li>The 'format' param tests various types including: 'email', 'bool', 'decimal', 'integer', 'date' (UTC), 'datetime' (UTC), 'credit_card' (using LUHN10), 'ip' (v4)
 *   <li>The 'gt', 'ge', 'lt', 'le' test if the numeric value is greater than, less than, etc...
 * </ul>
 * 
 * If the column value fails, the error format is a numeric array with 2 values:
 * <ol>
 *   <li>The error phrased in English (used until we get Language abstraction completed and used as a default as coding continues)
 *   <li>The error as a more generic code (e.g. 'invalid_regex') that can be used for language abstraction.
 * </ol>
 *
 * The function returns these values:
 * <ol>
 *   <li>boolean, true or false if the validation passed
 *   <li>the (possibly) scrubbed value
 *   <li>If failure, an array in the above format, if passed, an empty array.
 * </ol>
 *
 * <code>
 * list($ok, $item_count, $error) = do_validation('item_count',$_REQUEST['item_count'], array( 'name' => "number of items", 'format' => 'integer', 'required' => true ));
 * if ( ! $ok ) $request->page->add_error('item_count', $error);
 * </code>
 *
 * @param string $col      the name of the data or database column to be validated (used as a default name if none is passed in the $valhash)
 * @param string $value    value to test
 * @param string $valhash  an array containing validation parameters
 * @return mixed
 */
function do_validation( $col, $value, array $valhash ) {

    if ( ! empty( $valhash['name'] ) ) $name = ucfirst( $valhash['name'] );
    if ( empty($name) )                $name = ucfirst( preg_replace('/_/',' ', $col) );
    
    ###  Strip off whitespace unless 'no_strip_ws'
    if (   empty($valhash['no_strip_ws'])                                          &&     isset($value) )  $value = preg_replace('/^\s+|\s+$/','', $value);
    ###  Strip off optional '+' for format=decimal
    if ( ! empty($valhash['format']) && $valhash['format'] == 'decimal'            &&     isset($value) )  $value = preg_replace('/^\+/',      '', $value);
    if ( ! empty($valhash['format']) && $valhash['format'] == 'integer'            &&     isset($value) )  $value = preg_replace('/\.0+$/',    '', $value);
    if ( ! empty($valhash['format']) && $valhash['format'] == 'bool'               && ( ! isset($value) || preg_match('/^(f|false|n|no|0)$/i', $value) != 0 ) )  $value = false;
    if ( ! empty($valhash['format']) && $valhash['format'] == 'bool'               &&     isset($value) && preg_match('/^(t|true|y|yes|1)$/i', $value) != 0 )    $value = true;
    if ( ! empty($valhash['format']) && $valhash['format'] == 'credit_card_number' && (!  empty($value)) ) $value = preg_replace('/[\s\-]/',   '', $value);
    ###  If 'not_empty_string', then turn into null
    if ( ! empty($valhash['not_empty_string'])                  && isset($value) && strlen($value) == 0 ) $value = null;

    ###  If there is NO value
    if ( ! isset( $value ) || $value == '') {
        ###  Required
        if    ( ! empty($valhash['required']) 
            ) { return array( false, $value, array( array($name." is required",'required')) ); }
    }
    ###  If there IS a value, validate it...
    else {
        ###  Max length
        if    ( ! empty($valhash['maxlength'])
                && ( (    ini_get('default_charset') == 'UTF-8' && utf8_strlen($value) > $valhash['maxlength'] ) 
                     || ( ini_get('default_charset') != 'UTF-8' &&      strlen($value) > $valhash['maxlength'] )
                     )
            ) { return array( false, $value, array( array($name." may not be longer than ". $valhash['maxlength'] ." characters",'too_long')) ); }
        ###  Min length
        else if ( ! empty($valhash['minlength'])
                && ( (    ini_get('default_charset') == 'UTF-8' && utf8_strlen($value) < $valhash['minlength'] ) 
                     || ( ini_get('default_charset') != 'UTF-8' &&      strlen($value) < $valhash['minlength'] )
                     )
            ) { return array( false, $value, array( array($name." must be at least ". $valhash['minlength'] ." characters",'too_short')) ); }
        ###  Regular Expression
        else if ( ! empty($valhash['regex'])          && preg_match($valhash['regex'],          $value) == 0
                  ) { return array( false, $value, array( array($name." is not valid". (isset($valhash['regex_advice']) ? ('.  '. $valhash['regex_advice']) : ''),'invalid_regex')) ); }
        ###  Negative Match Regular Expression
        else if ( ! empty($valhash['negative_regex']) && preg_match($valhash['negative_regex'], $value) > 0
                  ) { return array( false, $value, array( array($name." is not valid". (isset($valhash['regex_advice']) ? ('.  '. $valhash['regex_advice']) : ''),'invalid_regex')) ); }
        ###  Format : email
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'email'
                && preg_match('/^[a-z0-9][a-z0-9\._\-\+]*\@([a-z0-9\-]+\.)+[a-z]{2,}$/i', $value) == 0
            ) { return array( false, $value, array( array($name." is not a valid email address",'invalid_email')) ); }
        ###  Format : boolean
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'bool'
                && preg_match('/^(t|true|y|yes|1|f|false|n|no|0|1)$/i', $value) == 0
            ) {
            return array( false, $value, array( array($name." is not a valid boolean value",'invalid_boolean')) );
        }
        ###  Format : decimal
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'decimal'
                && preg_match('/^\-?(\d+(\.\d+)?|\.\d+)$/', $value) == 0
            ) { return array( false, $value, array( array($name." is not a valid number",'invalid_decimal')) ); }
        ###  Format : integer
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'integer'
                && preg_match('/^\-?\d+$/', $value) == 0
            ) { return array( false, $value, array( array($name." is not a valid number",'invalid_integer')) ); }
        ###  Format : date
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'date'
                && ( preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $value) == 0
                     || strtotime( $value ) === false
                    )
            ) { return array( false, $value, array( array($name." is not a valid date",'invalid_date')) ); }
        ###  Format : datetime
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'datetime'
                && ( preg_match('/^\d{4}\-\d{2}\-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value) == 0
                     || strtotime( $value ) === false
                    )
            ) { return array( false, $value, array( array($name." is not a valid date",'invalid_date')) ); }
        ###  Format : credit_card_number
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'credit_card_number'
                && ( preg_match('/^\d{13,16}$/', $value) == 0
                     || ! luhn_10( $value )
                    )
            ) { return array( false, $value, array( array($name." is not a valid credit card number",'invalid_cc_number')) ); }
        ###  Format : ip
        else if ( ! empty($valhash['format'])
                && $valhash['format'] == 'ip'
                && ( preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}?$/', $value) == 0
                     || ( max( split('.', $value) ) > 255 )
                    )
            ) { return array( false, $value, array( array($name." is not a valid IP address",'invalid_ip_address')) ); }
        ###  Greater Than
        else if ( ! empty($valhash['gt'])  && $value <= $valhash['gt'] 
            ) { return array( false, $value, array( array($name." may not be less than or equal to ". $valhash['gt'],'greater_than')) ); }
        ###  Greater Than or equal To
        else if ( ! empty($valhash['ge']) && $value < $valhash['ge'] 
            ) { return array( false, $value, array( array($name." may not be less than ". $valhash['ge'],'greater_than_or_eq')) ); }
        ###  Less Than
        else if ( ! empty($valhash['lt'])  && $value >= $valhash['lt'] 
            ) { return array( false, $value, array( array($name." may not be greater than or equal to ". $valhash['lt'],'less_than')) ); }
        ###  Less Than or equal To
        else if ( ! empty($valhash['le']) && $value > $valhash['le'] 
            ) { return array( false, $value, array( array($name." may not be greater than ". $valhash['le'],'less_than_or_eq')) ); }
    }
    
    return array( true, $value, array() );
  }

function utf8_strlen($str) {
  $count = 0;
  for ($i = 0; $i < strlen($str); ++$i) {
    if ((ord($str[$i]) & 0xC0) != 0x80) {
      ++$count;
    }
  }
  return $count;
}

###  LUHN(10) algorithm
function luhn_10($cc) {
    $i = 1;  $s = array();
    foreach(array_reverse(str_split($cc)) as $c) {
        $s = array_merge($s, (($i++%2) ? array($c) : str_split($c*2)));
    }
    return (array_sum($s) % 10) ? 0 : 1;
}

### function to check if a given string represents a valid (non 0000-00-00 00:00:00) datetime
### from http://stackoverflow.com/questions/141315/php-check-for-a-valid-date-wierd-date-conversions
function is_valid_date_time($dateTime)
{
	// preg_match checks that it has all the right parts and that the time part is valid
	// checkdate checks that the date part is valid
    if (preg_match("/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/", $dateTime, $matches)) {
        if (checkdate($matches[2], $matches[3], $matches[1])) {
            return true;
        }
    }

    return false;
}

/**
 * dbh_query_bind() - Run a read-only SQL query with bound parameters
 *
 * @param string $sql      The SQL query to run
 * @param mixed $params   this can either be called passing an array of bind params, or just by passing the bind params as args after the SQL arg
 * @return PDOStatement
 */
function dbh_query_bind( $sql ) {
    if ( isset( $GLOBALS['orm_dbh'] ) ) $use_dbh = $GLOBALS['orm_dbh'];
    if ( ORM_SQL_PROFILE ) START_TIMER('dbh_query_bind');
    $bind_params = array_slice( func_get_args(), 1 );
    ###  Allow params passed in an array or as args
    if ( is_a( $bind_params[ count($bind_params) - 1 ], 'PDO' ) || is_a( $bind_params[ count($bind_params) - 1 ], 'PhoneyPDO' ) ) $use_dbh = array_pop($bind_params);
    if ( ! isset( $GLOBALS['orm_dbh'] ) ) $GLOBALS['orm_dbh'] = $use_dbh; # steal their DBH for global use, hehehe
    if ( count( $bind_params ) == 1 && is_array(array_shift(array_values($bind_params))) ) { $bind_params = array_shift(array_values($bind_params)); };
#    if (ORM_SQL_DEBUG) trace_dump();
    reverse_t_bools($bind_params);
    if (ORM_SQL_DEBUG) bug($sql, $bind_params);
    try { 
        $sth = $use_dbh->prepare($sql);
        $rv = $sth->execute($bind_params);
    } catch (PDOException $e) {
        trace_dump();
        $err_msg = 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line();
        if ( strlen($err_msg) > 1024 ) {
            bug($err_msg,$sql,$bind_params,$e->getMessage());
            $sql = substr($sql,0,1020 + strlen($sql) - strlen($err_msg) ).'...';
        }
        trigger_error( 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line(), E_USER_ERROR);
        return false;
    }
    if ( ORM_SQL_PROFILE ) END_TIMER('dbh_query_bind');
    return $sth;
}
/**
 * dbh_do_bind() - Execute a (possibly write access) SQL query with bound parameters
 *
 * @param string $sql      The SQL query to run
 * @param mixed $params   this can either be called passing an array of bind params, or just by passing the bind params as args after the SQL arg
 * @return PDOStatement
 */
function dbh_do_bind( $sql ) {
    if ( isset( $GLOBALS['orm_dbh'] ) ) $use_dbh = $GLOBALS['orm_dbh'];
    if ( ORM_SQL_PROFILE ) START_TIMER('dbh_do_bind');
    $bind_params = array_slice( func_get_args(), 1 );
    ###  Allow params passed in an array or as args
    if ( is_a( $bind_params[ count($bind_params) - 1 ], 'PDO' ) || is_a( $bind_params[ count($bind_params) - 1 ], 'PhoneyPDO' ) ) $use_dbh = array_pop($bind_params);
    if ( ! isset( $GLOBALS['orm_dbh'] ) ) $GLOBALS['orm_dbh'] = $use_dbh; # steal their DBH for global use, hehehe
    if ( count( $bind_params ) == 1 && is_array(array_shift(array_values($bind_params))) ) { $bind_params = array_shift(array_values($bind_params)); };
    
    reverse_t_bools($bind_params);
    if (ORM_SQL_DEBUG || ORM_SQL_WRITE_DEBUG) bug($sql, $bind_params);
    try { 
        $sth = $use_dbh->prepare($sql);
        $rv = $sth->execute($bind_params);
    } catch (PDOException $e) {
        trace_dump();
        $err_msg = 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line();
        if ( strlen($err_msg) > 1024 ) {
            bug($err_msg,$sql,$bind_params,$e->getMessage());
            $sql = substr($sql,0,1020 + strlen($sql) - strlen($err_msg) ).'...';
        }
        trigger_error( 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line(), E_USER_ERROR);
        return false;
    }
    if ( ORM_SQL_PROFILE ) END_TIMER('dbh_do_bind');
    return $rv;
}
function reverse_t_bools(&$ary) { if (! is_array($ary)) return;  foreach($ary as $k => $v) { if ($v === true) $ary[$k] = 't';  if ($v === false) $ary[$k] = 'f'; } }
