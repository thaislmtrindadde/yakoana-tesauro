<?php
/**
 * ARC2 SPARQL ASK query handler
 *
 * @author Benjamin Nowack
 * @license <http://arc.semsol.org/license>
 * @homepage <http://arc.semsol.org/>
 * @package ARC2
 * @version 2010-11-16
*/

ARC2::inc('StoreSelectQueryHandler');

class ARC2_StoreAskQueryHandler extends ARC2_StoreSelectQueryHandler {

  function __construct($a, &$caller) {/* caller has to be a store */
    parent::__construct($a, $caller);
  }
  
  function __init() {/* db_con */
    parent::__init();
    $this->store = $this->caller;
  }

  /*  */
  
  function runQuery($infos) {
    $infos['query']['limit'] = 1;
    $this->infos = $infos;
    $this->buildResultVars();
    return parent::runQuery($this->infos);
  }
  
  /*  */
  
  function buildResultVars() {
    $this->infos['query']['result_vars'][] = array('var' => '1', 'aggregate' => '', 'alias' => 'success');
  }

  /*  */
  
  function getFinalQueryResult($q_sql, $tmp_tbl) {
    $con = $this->store->getDBCon();
    $rs = mysqli_query( $con, 'SELECT success FROM ' . $tmp_tbl);
    $r = ($row = mysqli_fetch_array($rs)) ? $row['success'] : 0;
    return $r ? true : false;
  }

  /*  */
  
}


