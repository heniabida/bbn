<?php
/*
 * Copyright (C) 2014 BBN
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace bbn\appui;
use bbn;

class grid extends bbn\models\cls\basic
{

	private
          /* @var db The DB connection */
          $db = false,
          /* @var string The tables' prefix (the tables will be called ?cron and ?journal) */
          $prefix = 'bbn_',
          $primary = 'id',
          $cfg = [],
          $table = false,
          $structure = false,
          $fields = null,
          $additional_fields = [];

  public function __construct(bbn\db $db, $cfg, $table = null, $fields = null){
    $this->db = $db;
    if ( is_array($cfg) ){
      $this->cfg['start'] = isset($cfg['skip']) &&
              bbn\str::is_number($cfg['skip']) ?
                      $cfg['skip'] : 0;

      $this->cfg['limit'] = ( isset($cfg['take']) &&
              bbn\str::is_number($cfg['take']) ) ?
                      $cfg['take'] : 20;

      $this->cfg['order'] = $this->cfg['dir'] = false;

      if ( is_array($table) ){
        $this->fields = $table;
      }
      else if ( is_string($table) ){
        $this->table = $table;
        $this->structure = $this->db->modelize($this->table);
        $this->fields = array_keys($this->structure['fields']);
        if ( is_array($fields) ){
          $this->additional_fields = $fields;
        }
      }

      $this->cfg['sort'] = isset($cfg['sort']) ? $cfg['sort'] : [];
      $this->cfg['filter'] = isset($cfg['filter']['filters']) ? $cfg['filter'] : [];
    }
  }

  public function select(){
    var_dump($this->fields, $this->additional_fields, $this->where(), $this->order());
    return 1;
  }

  public function check()
  {
    return is_array($this->cfg);
  }

  public function start()
  {
    return $this->cfg['start'];
  }

  public function limit()
  {
    return $this->cfg['limit'];
  }

  public function where()
  {
    if ( $this->check() && isset($this->cfg['filter']) ){
      return $this->filter($this->cfg['filter']);
    }
    return '';
  }

  public function get_field($f, $array = false){
    if ( is_array($f) && isset($f['field']) ){
      $f = $f['field'];
    }
    if ( empty($this->fields) || $array ){
      return $this->db->col_simple_name($f, $array ? false : true);
    }
    if ( is_string($f) && (in_array($f, $this->fields) || in_array($f, $this->additional_fields)) ){
      return ( $this->table && !in_array($f, $this->additional_fields) ) ?
              $this->db->col_full_name($f, $this->table, 1) :
              $this->db->col_simple_name($f, 1);
    }
    return false;
  }

  public function filter($filters, $array = false){
    $res = $array ? [] : '';
    if ( $this->check() ){
      if ( isset($filters['filters']) && (count($filters['filters']) > 0) ){
        $logic = isset($filters['logic']) && ($filters['logic'] === 'or') ? 'OR' : 'AND';
        foreach ( $filters['filters'] as $f ){
          $ok = false;
          if ( empty($res) ){
            $pre = " ( ";
          }
          else{
            $pre = " $logic ";
          }
          if ( isset($f['logic']) ){
            if ( $array ){
              $res = \bbn\x::merge_arrays($res, $this->filter($f, true));
            }
            else{
              $res .= $pre.$this->filter($f);
            }
          }
          else if ( $field = $this->get_field($f, $array) ){
            if ( $this->structure && isset($this->structure['fields'][$f['field']]) ){
              if ( ($this->structure['fields'][$f['field']]['type'] === 'int') &&
                      ($this->structure['fields'][$f['field']]['maxlength'] == 1) &&
                      !bbn\str::is_integer($f['value']) ){
                $f['value'] = ($f['value'] === 'true') ? 1 : 0;
              }
            }
            if ( !$array ){
              $res .= $pre.$field." ";
            }
            switch ( $f['operator'] ){
              case 'eq':
                if ( $array ){
                  array_push($res, [$field, bbn\str::is_number($f['value']) ? ' = ' : 'LIKE', $f['value']]);
                }
                else{
                  $res .= bbn\str::is_number($f['value']) ? "= ".$f['value'] : "LIKE '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'neq':
                if ( $array ){
                  array_push($res, [$field, bbn\str::is_number($f['value']) ? '!=' : 'NOT LIKE', $f['value']]);
                }
                else{
                  $res .= bbn\str::is_number($f['value']) ? "!= ".$f['value'] : "NOT LIKE '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'startswith':
                if ( $array ){
                  array_push($res, [$field, 'LIKE', $f['value'].'%']);
                }
                else{
                  $res .= "LIKE '".$this->db->escape_value($f['value'])."%'";
                }
                break;

              case 'endswith':
                if ( $array ){
                  array_push($res, [$field, 'LIKE', '%'.$f['value']]);
                }
                else{
                  $res .= "LIKE '%".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'gte':
                if ( $array ){
                  array_push($res, [$field, '>=', $f['value']]);
                }
                else{
                  $res .= ">= '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'gt':
                if ( $array ){
                  array_push($res, [$field, '>', $f['value']]);
                }
                else{
                  $res .= "> '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'lte':
                if ( $array ){
                  array_push($res, [$field, '<=', $f['value']]);
                }
                else{
                  $res .= "<= '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'lt':
                if ( $array ){
                  array_push($res, [$field, '<', $f['value']]);
                }
                else{
                  $res .= "< '".$this->db->escape_value($f['value'])."'";
                }
                break;

              case 'isnull':
                if ( $array ){
                }
                else{
                  $res .= "IS NULL";
                }
                break;

              case 'isnotnull':
                if ( $array ){
                }
                else{
                  $res .= "IS NOT NULL";
                }
                break;

              case 'isempty':
                if ( $array ){
                  array_push($res, [$field, 'LIKE', '']);
                }
                else{
                  $res .= "LIKE ''";
                }
                break;

              case 'isnotempty':
                if ( $array ){
                  array_push($res, [$field, 'NOT LIKE', '']);
                }
                else{
                  $res .= "NOT LIKE ''";
                }
                break;

              case 'doesnotcontain':
                if ( $array ){
                  array_push($res, [$field, 'NOT LIKE', '%'.$f['value'].'%']);
                }
                else{
                  $res .= "NOT LIKE '%".$this->db->escape_value($f['value'])."%'";
                }
                break;

              case 'contains':
              default:
                if ( $array ){
                  array_push($res, [$field, 'LIKE', '%'.$f['value'].'%']);
                }
                else{
                  $res .= "LIKE '%".$this->db->escape_value($f['value'])."%'";
                }
                break;

            }
          }
        }
        if ( !$array && !empty($res) ){
          $res .= " ) ";
        }
      }
    }
    return $res;
  }

  public function order(){
    $st = '';
    if ( !empty($this->cfg['sort']) ){
      foreach ( $this->cfg['sort'] as $f ){
        if ( $field = $this->get_field($f) ){
          if ( !empty($st) ){
            $st .= ", ";
          }
          $st .= $field." ".( strtolower($f['dir']) === 'desc' ? 'DESC' : 'ASC' );
        }
      }
    }
    return $st;
  }

}
