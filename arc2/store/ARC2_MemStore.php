<?php
/**
 * ARC2 Memory Store
 *
 * @author Benjamin Nowack <bnowack@semsol.com>
 * @license http://arc.semsol.org/license
 * @package ARC2
 * @version 2010-11-16
*/

ARC2::inc('Class');

class ARC2_MemStore extends ARC2_Class {

  function __construct($a, &$caller) {
    parent::__construct($a, $caller);
    $this->is_mem = 1;
  }
  
  function __init() {
    parent::__init();
    $this->data = array();
  }

  /*  */

  function isSetUp() {
    return 1;
  }
  
  function setUp() {}
  
  /*  */
  
  function reset() {
    $this->data = array();
  }
  
  function drop() {
    $this->reset();
  }

  /*  */

  function insert($doc, $g = 'http://localhost/') {
    $index = $this->v($g, array(), $this->data);
    $this->data[$g] = ARC2::getMergedIndex($index, $this->toIndex($doc));
  }
  
  /*  */
  /*  */

  
  function delete($doc, $g = 'http://localhost/') {
    $index = $this->v($g, array(), $this->data);
    $this->data[$g] = ARC2::getCleanedIndex($index, $this->toIndex($doc));
  }

  function replace($doc, $g, $doc_2) {
    return array($this->delete($doc, $g), $this->insert($doc_2, $g));
  }
  
  /*  */
  
  function query($q, $result_format = '', $src = '', $keep_bnode_ids = 0, $log_query = 0) {
    if ($log_query) $this->logQuery($q);
    ARC2::inc('SPARQLPlusParser');
    $p = new ARC2_SPARQLPlusParser($this->a, $this);
    $p->parse($q, $src);
    $infos = $p->getQueryInfos();
    $t1 = ARC2::mtime();
    if (!$errs = $p->getErrors()) {
      $qt = $infos['query']['type'];
      $r = array('query_type' => $qt, 'result' => $this->runQuery($q, $qt));
    }
    else {
      $r = array('result' => '');
    }
    $t2 = ARC2::mtime();
    $r['query_time'] = $t2 - $t1;
    /* query result */
    if ($result_format == 'raw') {
      return $r['result'];
    }
    if ($result_format == 'rows') {
      return $this->v('rows', array(), $r['result']);
    }
    if ($result_format == 'row') {
      return $r['result']['rows'] ? $r['result']['rows'][0] : array();
    }
    return $r;
  }

  function runQuery($q, $qt = '') {
    /* ep */
    $ep = $this->v('remote_store_endpoint', 0, $this->a);
    if (!$ep) return false;
    /* prefixes */
    $ns = isset($this->a['ns']) ? $this->a['ns'] : array();
    $added_prefixes = array();
    $prologue = '';
    foreach ($ns as $k => $v) {
      $k = rtrim($k, ':');
      if (in_array($k, $added_prefixes)) continue;
      if (preg_match('/(^|\s)' . $k . ':/s', $q) && !preg_match('/PREFIX\s+' . $k . '\:/is', $q)) {
        $prologue .=  "\n" . 'PREFIX ' . $k . ': <' . $v . '>';
      }
      $added_prefixes[] = $k;
    }
    $q = $prologue . "\n" . $q;
    /* http verb */
    $mthd = in_array($qt, array('load', 'insert', 'delete')) ? 'POST' : 'GET';
    /* reader */
    ARC2::inc('Reader');
    $reader = new ARC2_Reader($this->a, $this);
    $reader->setAcceptHeader('Accept: application/sparql-results+xml; q=0.9, application/rdf+xml; q=0.9, */*; q=0.1');
    if ($mthd == 'GET') {
      $url = $ep;
      $url .= strpos($ep, '?') ? '&' : '?';
      $url .= 'query=' . urlencode($q);
      if ($k = $this->v('store_read_key', '', $this->a)) $url .= '&key=' . urlencode($k);
    }
    else {
      $url = $ep;
      $reader->setHTTPMethod($mthd);
      $reader->setCustomHeaders("Content-Type: application/x-www-form-urlencoded");
      $suffix = ($k = $this->v('store_write_key', '', $this->a)) ? '&key=' . rawurlencode($k) : '';
      $reader->setMessageBody('query=' . rawurlencode($q) . $suffix);
    }
    $to = $this->v('remote_store_timeout', 0, $this->a);
    $reader->activate($url, '', 0, $to);
    $format = $reader->getFormat();
    $resp = '';
    while ($d = $reader->readStream()) {
      $resp .= $d;
    }
    $reader->closeStream();
    $ers = $reader->getErrors();
    unset($this->reader);
    if ($ers) return array('errors' => $ers);
		$mappings = array('rdfxml' => 'RDFXML', 'sparqlxml' => 'SPARQLXMLResult', 'turtle' => 'Turtle');
    if (!$format || !isset($mappings[$format])) {
      return $resp;
      //return $this->addError('No parser available for "' . $format . '" SPARQL result');
    }
    /* format parser */
    $suffix = $mappings[$format] . 'Parser';
    ARC2::inc($suffix);
    $cls = 'ARC2_' . $suffix;
    $parser = new $cls($this->a, $this);
    $parser->parse($ep, $resp);
    /* ask|load|insert|delete */
    if (in_array($qt, array('ask', 'load', 'insert', 'delete'))) {
      $bid = $parser->getBooleanInsertedDeleted();
      switch ($qt) {
        case 'ask': return $bid['boolean'];
        default: return $bid;
      }
    }
    /* select */
    if (($qt == 'select') && !method_exists($parser, 'getRows')) return $resp;
    if ($qt == 'select') return array('rows' => $parser->getRows(), 'variables' => $parser->getVariables());
    /* any other */
    return $parser->getSimpleIndex(0);
  }
  
  /*  */
  
  function optimizeTables() {}
  
  /*  */

  function getResourceLabel($res, $unnamed_label = 'An unnamed resource') {
    if (!isset($this->resource_labels)) $this->resource_labels = array();
    if (isset($this->resource_labels[$res])) return $this->resource_labels[$res];
    if (!preg_match('/^[a-z0-9\_]+\:[^\s]+$/si', $res)) return $res;/* literal */
    $r = '';
    if (preg_match('/^\_\:/', $res)) {
      return $unnamed_label;
    }
    $row = $this->query('SELECT ?o WHERE { <' . $res . '> ?p ?o . FILTER(REGEX(str(?p), "(label|name)$", "i"))}', 'row');
    if ($row) {
      $r = $row['o'];
    }
    else {
      $r = preg_replace("/^(.*[\/\#])([^\/\#]+)$/", '\\2', str_replace('#self', '', $res));
      $r = str_replace('_', ' ', $r);
      $r = preg_replace_callback('/([a-z])([A-Z])/', function($matches) {
        return $matches[1] . ' ' . strtolower($matches[2]);
      }, $r);
    }
    $this->resource_labels[$res] = $r;
    return $r;
  }

}
