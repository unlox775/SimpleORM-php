<?php

#########################
###  Simulation of PDO for some methods (for hosts that don't include PDO)

/**
 * PhoneyPDO - Hack! Semi-complete implementation of PDO from the legacy mysql_ methods.   
 *
 * This Only gets used if a PDO DB connection fails.
 */
class PDO { 
    const FETCH_NUM = 234523;
    const FETCH_ASSOC = 234524;
    const FETCH_BOTH = 234525;
}

class PhoneyPDO {
    public $conn_rsc = null;
    public $trans_started = false;
    public $si = 0;
    public function __construct(array $pdo_conn_ary, $conn_rsc = null) {
        if ( ! is_null($conn_rsc) ) {
            $this->conn_rsc = $conn_rsc;
            return true;
        }
        
        $host_and_db_name = split(';',preg_replace('/(mysql:host=|dbname=)/','',$pdo_conn_ary[0]));

        $this->conn_rsc = mysql_connect($host_and_db_name[0], $pdo_conn_ary[1], $pdo_conn_ary[2]);
        mysql_select_db($host_and_db_name[1],$this->conn_rsc);
        if ( $this->conn_rsc === false ) { throw new PhoneyPDOException("Connection error with these params: $new_conn_str"); }
    }
    public function prepare($sql)   { $sth = new PhoneyPDOStatement($this, 'stmt_'. $this->si, $sql);  $this->err_ck();  return $sth; }
    public function err_ck()   { if ( mysql_error() !== false && strlen(mysql_error()) > 0 ) throw new PhoneyPDOException("SQL Error: ". mysql_error()); }
    public function exec($sql)   { $rv = mysql_query($sql, $this->conn_rsc);  $this->err_ck();  return $rv; }
    public function lastInsertId() { return mysql_insert_id($this->conn_rsc); }
    public function beginTransaction() { if ( $this->trans_started ) throw new PhoneyPDOException("Trans already started, dummy!"); else { $this->trans_started = true;   return $this->exec('START TRANSACTION'); }; }
    public function commit()   {                                                                                                           $this->trans_started = false;  return $this->exec('COMMIT'); }
    public function rollBack() {                                                                                                           $this->trans_started = false;  return $this->exec('ROLLBACK'); }
    public function __destruct() { if ( $this->trans_started ) $this->rollBack(); }
    public function __call($name, $args) { }
}
/**
 * PhoneyPDOStatement - Hack! Semi-complete implementation of PDO from the legacy mysql_ methods.  For our local sandboxes on Mac OS X because MAPPStack doesn't have PDO
 *
 * This is a hacked version of a PDOStatement Object
 */
class PhoneyPDOStatement {
    public $dbh = null;
    public $s_name = null;
    public $sql = null;
    public $res_rsc = null;
    public $fi = 0;
    public function __construct($dbh, $s_name, $sql) { list( $this->dbh, $this->s_name, $this->sql ) = func_get_args(); }
    public function execute ($a = array()) { $rv = mysql_query($this->qm_trans($this->sql,$a), $this->dbh->conn_rsc);  $this->dbh->err_ck();  $this->res_rsc = $rv;  return $rv; }
    function qm_trans($sql,$a) { global $ppdo_i,$ppdo_a;$ppdo_i = 1;$ppdo_a = $a; return preg_replace_callback('/\?/', create_function('',"global \$ppdo_i,\$ppdo_a; return \"'\".mysql_real_escape_string(\$ppdo_a[(\$ppdo_i++) - 1]).\"'\";"), $sql); }
    public function fetchAll($type = null) { $ttype = $this->tt($type);  $ra = array();  while ( $row = mysql_fetch_array($this->res_rsc, $ttype) ) { $this->t_bools($row);  $ra[] = $row; } $this->dbh->err_ck(); return $ra; }
    public function fetch(   $type = null) { $ttype = $this->tt($type);                           $rv = mysql_fetch_array($this->res_rsc, $ttype);    $this->t_bools($rv);                  $this->dbh->err_ck(); return $rv; }
    public function tt($type = null) { return( ($type == PDO::FETCH_NUM) ? MYSQL_NUM : (($type == PDO::FETCH_ASSOC) ? MYSQL_ASSOC : MYSQL_BOTH) ); }
    public function t_bools(&$ary) { if (! is_array($ary)) return;  foreach($ary as $k => $v) { if ($v === 't') $ary[$k] = true;  if ($v === 'f') $ary[$k] = false; } }
}
/**
 * PhoneyPDOException - Hack! Semi-complete implementation of PDO from the legacy mysql_ methods.  For our local sandboxes on Mac OS X because MAPPStack doesn't have PDO
 *
 * This is a hacked version of a PDOStatement Object
 */
class PhoneyPDOException extends Exception { }
