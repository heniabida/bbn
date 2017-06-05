<?php
/**
 * @package user
 */
namespace bbn\user;
use bbn;
/**
 * A permission system linked to options and user classes
 *
 *
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright BBN Solutions
 * @since Oct 28, 2015, 10:23:55 +0000
 * @category  Authentication
 * @license   http://opensource.org/licenses/MIT MIT
 * @version 0.1
 * @todo Groups and hotlinks features
 */

class preferences extends bbn\models\cls\db
{

  use bbn\models\tts\retriever,
      bbn\models\tts\optional;

  protected static
		/** @var array */
		$_defaults = [
			'errors' => [
			],
			'table' => 'bbn_user_options',
			'cols' => [
				'id' => 'id',
				'id_option' => 'id_option',
				'id_user' => 'id_user',
				'id_group' => 'id_group',
				'id_link' => 'id_link',
				'cfg' => 'cfg'
			],
			'id_user' => null,
			'id_group' => null
		];

	protected
		/** @var bbn\appui\options */
    $options,
		/** @var db */
    $db,
		/** @var array */
    $class_cfg = [],
		/** @var int */
		$id_user,
		/** @var int */
		$id_group;

  /**
   * @return bbn\appui\options
   */
  public static function get_preferences(){
    return self::get_instance();
  }

  /**
	 * @return bbn\user\permissions
	 */
	public function __construct(bbn\appui\options $options, bbn\db $db, array $cfg = []){
		$this->class_cfg = bbn\x::merge_arrays(self::$_defaults, $cfg);
		$this->options = bbn\appui\options::get_options();
		$this->db = $db;
		$this->id_user = $this->class_cfg['id_user'] ?: false;
		$this->id_group = $this->class_cfg['id_group'] ?: false;
    self::retriever_init($this);
	}

  public function get_user(){
    return $this->id_user;
  }

  public function get_group(){
    return $this->id_group;
  }

  /**
   * Changes the current user
   * @param $id_user
   * @return $this
   */
  public function set_user($id_user){
    if ( is_int($id_user) ){
      $this->id_user = $id_user;
    }
    return $this;
  }

  /**
   * Changes the current user's group
   * @param $id_group
   * @return $this
   */
  public function set_group($id_group){
    if ( is_int($id_group) ){
      $this->id_group = $id_group;
    }
    return $this;
  }

  /**
   * Sets the cfg field of a given preference based on its ID
   * @param int $id
   * @param array $cfg
   * @return int
   */
  public function set_cfg($id, $cfg){
		if ( is_array($cfg) ){
			foreach ( $cfg as $k => $v ){
				if ( in_array($k, $this->class_cfg['cols']) ){
					unset($cfg[$k]);
				}
			}
			$cfg = json_encode($cfg);
		}
		return $this->db->update($this->class_cfg['table'], [
			$this->class_cfg['cols']['cfg'] => $cfg
		], [
			$this->class_cfg['cols']['id'] => $id
		]);
	}

	public function get_class_cfg(){
	  return $this->class_cfg;
  }

  /**
   * Gets the cfg field of a given preference based on its ID
   * @param $id
   * @param null $cfg
   * @return array
   */
  public function get_cfg($id, &$cfg=null){
		if ( is_null($cfg) ){
			$cfg = $this->db->rselect(
				$this->class_cfg['table'],
				[$this->class_cfg['cols']['cfg']],
				[ $this->class_cfg['cols']['id'] => $id ]
			);
		}
		if ( isset($cfg[$this->class_cfg['cols']['cfg']]) && bbn\str::is_json($cfg[$this->class_cfg['cols']['cfg']]) ){
			$cfg = bbn\x::merge_arrays(json_decode($cfg[$this->class_cfg['cols']['cfg']], 1), $cfg);
		}
		$new = [];
		if ( is_array($cfg) ){
			foreach ( $cfg as $k => $v){
				if ( !in_array($k, $this->class_cfg['cols']) ){
					$cfg[$k] = $v;
					$new[$k] = $v;
				}
			}
			unset($cfg[$this->class_cfg['cols']['cfg']]);
		}
		return $new;
	}

	/**
	 * Returns the current user's preference based on his own profile and his group's
	 * @param int $id_option
	 * @return
	 */
	public function get($id_option){
    $res = [];
    if ( $this->id_group &&
      ($res1 = $this->db->rselect($this->class_cfg['table'], $this->class_cfg['cols'], [
				$this->class_cfg['cols']['id_option'] => $id_option,
				$this->class_cfg['cols']['id_group'] => $this->id_group
			]))
    ){
      $res = bbn\x::merge_arrays($res, $this->get_cfg($res1['id'], $res1));
		}
    if ( $this->id_user &&
      ($res2 = $this->db->rselect($this->class_cfg['table'], $this->class_cfg['cols'], [
				$this->class_cfg['cols']['id_option'] => $id_option,
				$this->class_cfg['cols']['id_user'] => $this->id_user
			]))
    ){
      $res = bbn\x::merge_arrays($res, $this->get_cfg($res2['id'], $res2));
		}
    return empty($res) ? false : $res;
	}

	/**
	 * Returns true if a user/group has a preference, false otherwise
	 *
	 * @return bool
	 */
	public function has($id_option, $id_user = null, $id_group = null, $force = false){
    if ( !bbn\str::is_integer($id_option) ){
      $id_option = $this->from_path($id_option);
    }
    if ( !$force && $this->id_group === 1 ){
      return true;
    }
    if ( $id_user && $this->retrieve_id($id_option, $id_user, false, $force) ){
      return true;
    }
    if ( $id_group && $this->retrieve_id($id_option, false, $id_group, $force) ){
      return true;
    }
		return false;
	}

  /**
   * 
   * @param $id_pref
   * @param $id_link
   * @return int
   */
  public function set_link($id_option, $id_link, $id_user = null, $id_group = null){
    if ( $id = $this->retrieve_id($id_option, $id_user, $id_group) ){
      return $this->db->update($this->class_cfg['table'], [
        $this->class_cfg['cols']['id_link'] => $id_link
      ], [
        $this->class_cfg['cols']['id'] => $id
      ]);
    }
    else{
      if ( !bbn\str::is_integer($id_option) ){
        $id_option = $this->from_path($id_option);
      }
      if ( bbn\str::is_integer($id_option, $id_link) ){
        return $this->db->insert($this->class_cfg['table'], [
          $this->class_cfg['cols']['id_option'] => $id_option,
          $this->class_cfg['cols']['id_link'] => $id_link,
          $this->class_cfg['cols']['id_group'] => $id_group ? $id_group : null,
          $this->class_cfg['cols']['id_user'] => $id_group ? null : ( $id_user ?: $this->id_user )
        ]);
      }
    }
  }

  /**
   *
   * @param $id_pref
   * @param $id_link
   * @return int
   */
  public function unset_link($id_option, $id_user = null, $id_group = null){
    if ( $id = $this->retrieve_id($id_option, $id_user, $id_group) ){
      return $this->db->update($this->class_cfg['table'], [
        $this->class_cfg['cols']['id_link'] => null
      ], [
        $this->class_cfg['cols']['id'] => $id
      ]);
    }
  }

  public function get_links($id, $id_user = null, $id_group = null){
    if ( !empty($id_group) ){
      $cfg = ['id_group' => $id_group];
    }
    else if ( !empty($id_user) || !empty($this->id_user) ){
      $cfg = ['id_user' => $id_user ?: $this->id_user];
    }
    if ( isset($cfg) ){
      $cfg[$this->class_cfg['cols']['id_link']] = $id;
      return $this->db->get_column_values($this->class_cfg['table'], $this->class_cfg['cols']['id_option'], $cfg);
    }
  }

  /**
   * Returns the ID of a preference from the table
   *
   * @param int $id_option
   * @param null|int $id_user
   * @param null|int $id_group
   * @return false|int
   */
  public function retrieve_id($id_option, $id_user = null, $id_group = null){
    if ( !bbn\str::is_integer($id_option) ){
      $id_option = $this->from_path($id_option);
    }
    if ( !$id_user && $id_group ){
      return $this->db->get_val($this->class_cfg['table'], $this->class_cfg['cols']['id'], [
        $this->class_cfg['cols']['id_option'] => $id_option,
        $this->class_cfg['cols']['id_group'] => $id_group ?: $this->id_group
      ]);
    }
    else if ( $id_user || $this->id_user ){
      return $this->db->get_val($this->class_cfg['table'], $this->class_cfg['cols']['id'], [
        $this->class_cfg['cols']['id_option'] => $id_option,
        $this->class_cfg['cols']['id_user'] => $id_user ?: $this->id_user
      ]);
    }
    return false;
  }

  /**
   * Sets permission's config to user or group - and adds the permission if needed.
   *
   * @param $id_option
   * @param array $cfg
   * @param int|null $id_user
   * @param int|null $id_group
   * @return int
   */
  public function set(int $id_option, array $cfg, int $id_user = null, int $id_group = null){
		if ( $id = $this->retrieve_id($id_option, $id_user, $id_group) ){
			return $this->set_cfg($id, $cfg);
		}
		return $this->db->insert($this->class_cfg['table'], [
			'id_option' => $id_option,
			'id_user' => !$id_group && ($id_user || $this->id_user) ? ($id_user ? $id_user : $this->id_user)  : null,
			'id_group' => $id_group ?: null,
			'cfg' => json_encode($this->get_cfg(false, $cfg))
		]);
	}

  /**
   * Updates config to user or group - and adds pref if needed.
   *
   * @param $id_option
   * @param array $cfg
   * @param int|null $id_user
   * @param int|null $id_group
   * @return int
   */
  public function add(int $id_option, array $cfg, int $id_user = null, int $id_group = null){
    if ( $id = $this->retrieve_id($id_option, $id_user, $id_group) ){
      $tmp = $this->get_cfg($id);
      \bbn\x::log(["MODIFYING", $this->options->text($id_option) ,$tmp, $cfg]);
      return $this->set_cfg($id, \bbn\x::merge_arrays($tmp, $cfg));
    }
    \bbn\x::log("INSERTING");
    return $this->db->insert($this->class_cfg['table'], [
      'id_option' => $id_option,
      'id_user' => !$id_group && ($id_user || $this->id_user) ? ($id_user ? $id_user : $this->id_user)  : null,
      'id_group' => $id_group ?: null,
      'cfg' => json_encode($this->get_cfg(false, $cfg))
    ]);
  }

	/**
	 * Returns all the current user's permissions
	 *
	 * @return array
	 */
	public function delete($id_option, $id_user = null, $id_group = null){
    if ( !bbn\str::is_integer($id_option) ){
      $id_option = $this->from_path($id_option);
    }
		if ( $id_group ){
			return $this->db->delete($this->class_cfg['table'], [
				$this->class_cfg['cols']['id_option'] => $id_option,
				$this->class_cfg['cols']['id_group'] => $id_group
			]);
		}
		if ( $id_user || $this->id_user ){
			return $this->db->delete($this->class_cfg['table'], [
				$this->class_cfg['cols']['id_option'] => $id_option,
				$this->class_cfg['cols']['id_user'] => $id_user ? $id_user : $this->id_user
			]);
		}
	}

	/**
	 * Adapts a given array of options' to user's permissions
	 *
	 * @param array $arr
	 * @return array
	 */
	public function customize(array $arr){
		$res = [];
		if ( isset($arr[0]) ){
			foreach ( $arr as $a ){
				if ( isset($a['id']) && $this->has($a['id']) ){
					$res[] = $a;
				}
			}
		}
		else if ( isset($arr['items']) ){
			$res = $arr;
			unset($res['items']);
			foreach ( $arr['items'] as $a ){
				if ( isset($a['id']) && $this->has($a['id']) ){
					if ( !isset($res['items']) ){
						$res['items'] = [];
					}
					$res['items'][] = $a;
				}
			}
		}
		return $res;
	}

	public function items($code){
	  if ( $items = $this->options->items(func_get_args()) ){
	    $res = [];
      foreach ( $items as $i => $it ){
        $res[] = ['id' => $it, 'num' => $i + 1];
        if (
          ($tmp = $this->get($it)) &&
          (isset($tmp['num']))
        ){
          $res[$i]['num'] = $tmp['num'];
        }
      }
      \bbn\x::sort_by($res, 'num');
      return array_map(function($a){
        return $a['id'];
      }, $res);
    }
    return $items;
  }

  public function option($code){
    if ( $o = $this->options->option(func_get_args()) ){
      if ( $id = $this->retrieve_id($o['id']) ){
        $o = bbn\x::merge_arrays($o, $this->get_cfg($id));
      }
      return $o;
    }
    return false;
  }

  public function full_options($code){
    if ( bbn\str::is_integer($id = $this->options->from_code(func_get_args())) ){
      $list = $this->items($id);
      if ( is_array($list) ){
        $res = [];
        foreach ($list as $i){
          array_push($res, $this->option($i));
        }
        return $res;
      }
    }
    return false;
  }

	public function order($id_option, int $index){
    $id_parent = $this->options->get_id_parent($id_option);
    \bbn\x::log(["ID_PARENT", $id_parent, $this->options->text($id_parent)]);
    if ( ($id_parent !== false) && $this->options->is_sortable($id_parent) ){
      $items = $this->items($id_parent);
      \bbn\x::log(["ITEMS", $items]);
      $res = [];
      $to_change = false;
      foreach ( $items as $i => $it ){
        $res[] = [
          'id' => $it,
          'num' => $i + 1
        ];
        if ( $cfg = $this->get($it) ){
          $res[$i] = \bbn\x::merge_arrays($res[$i], $cfg);
        }
        if ( $it === $id_option ){
          $to_change = $i;
        }
      }
      \bbn\x::log(["TO_CHANGE", $to_change]);
      if ( $to_change !== false ){
        if ( $to_change > $index ){
          for ( $i = $index; $i < $to_change; $i++ ){
            $res[$i]['num']++;
          }
        }
        else if ( $to_change < $index ){
          for ( $i = $to_change + 1; $i <= $index; $i++ ){
            $res[$i]['num']--;
          }
        }
        $res[$to_change]['num'] = $index + 1;
        foreach ( $res as $i => $r ){
          \bbn\x::log(["ADDING", $r, $this->options->text($r['id'])]);
          $this->add($r['id'], $r);
        }
        return $res;
      }
    }
  }
}
