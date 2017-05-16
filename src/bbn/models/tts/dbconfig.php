<?php
/**
 * Created by PhpStorm.
 * User: BBN
 * Date: 05/11/2016
 * Time: 02:47
 */

namespace bbn\models\tts;

use bbn;


trait dbconfig
{

  protected
    /** @var array */
    $class_cfg,
    /** @var string */
    $class_table,
    /** @var string */
    $class_table_index;

  /**
   * Sets the class configuration as defined in $this->_defaults
   * @param array $cfg
   * @return $this
   */
  private function _init_class_cfg(array $cfg = []){
    $cfg = bbn\x::merge_arrays(self::$_defaults, $cfg);

    if ( !isset($cfg['tables'], $cfg['table'], $cfg['arch']) ){
      die("The class ".get_class($this)."is not configured properly to work with trait dbconfig");
    }

    // We completely replace the table structure, no merge
    if ( !empty($cfg['arch']) ){
      foreach ( $cfg['arch'] as $t => $a ){
        if ( $cfg['tables'][$t] === $cfg['table'] ){
          $this->class_table_index = $t;
        }
      }
    }
    $this->class_table = $cfg['table'];
    /*
     * The selection comprises the defined fields of the users table
     * Plus a bunch of user-defined additional fields in the same table
     */
    $this->fields = $cfg['arch'][$this->class_table_index];
    $this->class_cfg = $cfg;
    return $this;
  }

  public function exists($id){
    return $this->db->count($this->class_table, [
      $this->class_cfg['arch'][$this->class_table_index]['id'] => $id
    ]) ? true : false;
  }


}