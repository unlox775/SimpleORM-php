<?php

#########################
###  Simulation of PDO for some methods (because the MAPPStack local development doesn't include the PDO PGSQL Driver)

/**
 * PhoneyPDO - Hack! Semi-complete implementation of PDO from the legacy pg_ methods.  For our local sandboxes on Mac OS X because MAPPStack doesn't have PDO
 *
 * This Only gets used if a PDO DB connection fails.
 */
class PhoneyPDO {
    public $conn_rsc = null;
    public $trans_started = false;
    public $si = 0;
    public function __construct(array $pdo_conn_ary) {
        $new_conn_str = join(' ', split(';',preg_replace('/^pgsql:/','',$pdo_conn_ary[0]))).' user='.$pdo_conn_ary[1].' password='.$pdo_conn_ary[2];

        $this->conn_rsc = pg_connect($new_conn_str);
        if ( $this->conn_rsc === false ) { throw new PhoneyPDOException("Connection error with these params: $new_conn_str"); }
    }
    public function prepare($sql)   { $sth = new PhoneyPDOStatement($this, 'stmt_'. $this->si, pg_prepare($this->conn_rsc,'stmt_'. $this->si++, $this->qm_trans($sql)));  $this->err_ck();  return $sth; }
    function qm_trans($sql) { global $ppdo_i; $ppdo_i = 1;  return preg_replace_callback('/\?/', create_function('',"global \$ppdo_i; return '\$'.\$ppdo_i++;"), $sql); }
    public function err_ck()   { if ( pg_last_error() !== false && strlen(pg_last_error()) > 0 ) throw new PhoneyPDOException("SQL Error: ". pg_last_error()); }
    public function exec($sql)   { $rv = pg_query($this->conn_rsc,$sql);  $this->err_ck();  return $rv; }
    public function lastInsertId($seq_name) { list($id) = pg_fetch_row(pg_query($this->conn_rsc,"select currval('$seq_name')"));  return $id; }
    public function beginTransaction() { if ( $this->trans_started ) throw new PhoneyPDOException("Trans already started, dummy!"); else { $this->trans_started = true;   return $this->exec('START TRANSACTION'); }; }
    public function commit()   {                                                                                                           $this->trans_started = false;  return $this->exec('COMMIT'); }
    public function rollBack() {                                                                                                           $this->trans_started = false;  return $this->exec('ROLLBACK'); }
    public function __destruct() { if ( $this->trans_started ) $this->rollBack(); }
    public function __call($name, $args) { }
}
/**
 * PhoneyPDOStatement - Hack! Semi-complete implementation of PDO from the legacy pg_ methods.  For our local sandboxes on Mac OS X because MAPPStack doesn't have PDO
 *
 * This is a hacked version of a PDOStatement Object
 */
class PhoneyPDOStatement {
    public $dbh = null;
    public $s_name = null;
    public $sth_rsc = null;
    public $res_rsc = null;
    public $fi = 0;
    public function __construct($dbh, $s_name, $sth_rsc) { list( $this->dbh, $this->s_name, $this->sth_rsc ) = func_get_args(); }
    public function execute ($a = array()) { $rv = pg_execute($this->dbh->conn_rsc, $this->s_name, $a);  $this->dbh->err_ck();  $this->res_rsc = $rv;  return $rv; }
    public function fetchAll($type = null) { $ttype = $this->tt($type);  $ra = array();  while ( $row = pg_fetch_array($this->res_rsc, null, $ttype) ) { $this->t_bools($row);  $ra[] = $row; } $this->dbh->err_ck(); return $ra; }
    public function fetch(   $type = null) { $ttype = $this->tt($type);                           $rv = pg_fetch_array($this->res_rsc, null, $ttype);    $this->t_bools($rv);                  $this->dbh->err_ck(); return $rv; }
    public function tt($type = null) { return( ($type == PDO::FETCH_NUM) ? PGSQL_NUM : (($type == PDO::FETCH_ASSOC) ? PGSQL_ASSOC : PGSQL_BOTH) ); }
    public function t_bools(&$ary) { if (! is_array($ary)) return;  foreach($ary as $k => $v) { if ($v === 't') $ary[$k] = true;  if ($v === 'f') $ary[$k] = false; } }
}
/**
 * PhoneyPDOException - Hack! Semi-complete implementation of PDO from the legacy pg_ methods.  For our local sandboxes on Mac OS X because MAPPStack doesn't have PDO
 *
 * This is a hacked version of a PDOStatement Object
 */
class PhoneyPDOException extends PDOException { }
