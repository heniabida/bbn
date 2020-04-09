<?php
/**
 * @package user
 */
namespace bbn\user;
use bbn;
/**
 * A class for managing users
 *
 *
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright BBN Solutions
 * @since Apr 4, 2011, 23:23:55 +0000
 * @category  Authentication
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @version 0.2r89
 */
class manager
{
  protected static $admin_group;

  protected static $dev_group;

  protected $messages = [
    'creation' => [
      'subject' => "Account created",
      'link' => "",
      'text' => "
A new user has been created for you.<br>
Please click the link below in order to activate your account:<br>
%1\$s"
    ],
    'password' => [
      'subject' => "Password change",
      'link' => "",
      'text' => "
You can click the following link to change your password:<br>
%1\$s"
    ],
    'hotlink' => [
      'subject' => "Hotlink",
      'link' => "",
      'text' => "
You can click the following link to access directly your account:<br>
%1\$s"
    ]
  ];
  // 1 day
  protected $hotlink_length = 86400;

  protected $list_fields;

  protected $usrcls;

  protected $mailer = false;

  protected $db;

  protected $class_cfg = false;

  public function get_list_fields(){
    return $this->list_fields;
  }

  public function get_mailer(){
    if ( !$this->mailer ){
      $this->mailer = $this->usrcls->get_mailer();
    }
    return $this->mailer;
  }
  
  public function find_sessions(string $id_user=null, int $minutes = 5): array
  {
    $cfg = [
      'table' => $this->class_cfg['tables']['sessions'],
      'fields' => [],
      'where' => [
        [
          'field' => $this->class_cfg['arch']['sessions']['last_activity'],
          'operator' => '<',
          'value' => 'DATE_SUB(NOW(), INTERVAL '.$this->db->csn($this->class_cfg['sess_length'], true).' MINUTE)'
        ]
      ]
    ];
    if ( !\is_null($id_user) ){
      $cfg['where'][] = [
        'field' => $this->class_cfg['arch']['sessions']['id_user'],
        'value' => $id_user
      ];
    }
    return $this->db->rselect_all($cfg);
  }

  public function destroy_sessions($id_user, int $minutes = 5): bool
  {
    $sessions = $this->find_sessions($id_user, $minutes);    
    //$num = count($sessions);
    foreach ( $sessions as $s ){
      $this->db->delete($this->class_cfg['tables']['sessions'], [$this->class_cfg['arch']['sessions']['id'] => $s['id']]);
    }    
    return true;
  }

	/**
	 * @param object $obj A user's connection object (\connection or subclass)
   * @param object|false $mailer A mail object with the send method
   * 
	 */
  public function __construct(bbn\user $obj)
  {
    if ( \is_object($obj) && method_exists($obj, 'get_class_cfg') ){
      $this->usrcls = $obj;
      $this->class_cfg = $this->usrcls->get_class_cfg();
      if ( !$this->list_fields ){
        $this->set_default_list_fields();
      }
      $this->db =& $this->usrcls->db;
    }
  }

  public function is_online(string $id_user, int $delay = 180): bool
  {
    $a =& $this->class_cfg['arch'];
    $t =& $this->class_cfg['tables'];
    if (
      ($max = $this->db->select_one($t['sessions'], 'MAX('.$a['sessions']['last_activity'].')', ['id_user' => $id_user]))
      &&(strtotime($max) > (time() - $delay))
    ) {
      return true;
    }
    return false;
  }

  /**
   * Returns all the users' groups - with or without admin
   * @param bool $adm
   * @return array|false
   */
  public function groups(){
    $a =& $this->class_cfg['arch'];
    $t =& $this->class_cfg['tables'];
    $id = $this->db->cfn($a['groups']['id'], $t['groups'], 1);
    $group = $this->db->cfn($a['groups']['group'], $t['groups'], 1);
    $id_group = $this->db->cfn($a['users']['id_group'], $t['users'], 1);
    $type = $this->db->cfn($a['groups']['type'], $t['groups'], 1);
    $code = $this->db->cfn($a['groups']['code'], $t['groups'], 1);
    $active = $this->db->cfn($a['users']['active'], $t['users'], 1);
    $users_id = $this->db->cfn($a['users']['id'], $t['users'], 1);
    $groups = $this->db->escape($t['groups']);
    $users = $this->db->escape($t['users']);
    return $this->db->get_rows("
      SELECT $id, $group, $type, $code,
      COUNT($users_id) AS `num`
      FROM $groups
        LEFT JOIN $users
          ON $id_group = $id
      GROUP BY $id");
  }

  public function text_value_groups(){
    return $this->db->rselect_all(
      $this->class_cfg['tables']['groups'], [
        'value' => $this->class_cfg['arch']['groups']['id'],
        'text' => $this->class_cfg['arch']['groups']['group'],
      ]);
  }

  public function get_email($id){
    if ( bbn\str::is_uid($id) ){
      $email = $this->db->select_one($this->class_cfg['tables']['users'], $this->class_cfg['arch']['users']['email'], [$this->class_cfg['arch']['users']['id'] => $id]);
      if ( $email && bbn\str::is_email($email) ){
        return $email;
      }
    }
    return false;
  }
  
  public function get_list($group_id = null){
    $db =& $this->db;
    $arch =& $this->class_cfg['arch'];
    $s =& $arch['sessions'];
    $tables =& $this->class_cfg['tables'];

    if ( !empty($arch['users']['username']) ){
      $sort = $arch['users']['username'];
    }
    else if ( !empty($arch['users']['login']) ){
      $sort = $arch['users']['login'];
    }
    else{
      $sort = $arch['users']['email'];
    }

    $sql = "SELECT ";
    $done = [];
    foreach ( $arch['users'] as $n => $f ){
      if ( !\in_array($f, $done) ){
        $sql .= $db->cfn($f, $tables['users'], 1).', ';
        array_push($done, $f);
      }
    }
    $gr = !empty($group_id) && \bbn\str::is_uid($group_id) ?
      "AND " . $db->cfn($arch['groups']['id'], $tables['groups'], 1) . " = UNHEX('$group_id')" : '';
    $sql .= "
      MAX({$db->cfn($s['last_activity'], $tables['sessions'], 1)}) AS {$db->csn($s['last_activity'], 1)},
      COUNT({$db->cfn($s['sess_id'], $tables['sessions'])}) AS {$db->csn($s['sess_id'], 1)}
      FROM {$db->escape($tables['users'])}
        JOIN {$db->tsn($tables['groups'], 1)}
          ON {$db->cfn($arch['users']['id_group'], $tables['users'], 1)} = {$db->cfn($arch['groups']['id'], $tables['groups'], 1)}
          $gr
        LEFT JOIN {$db->tsn($tables['sessions'])}
          ON {$db->cfn($s['id_user'], $tables['sessions'], 1)} = {$db->cfn($arch['users']['id'], $tables['users'], 1)}
      WHERE {$db->cfn($arch['users']['active'], $tables['users'], 1)} = 1
      GROUP BY {$db->cfn($arch['users']['id'], $tables['users'], 1)}
      ORDER BY {$db->cfn($sort, $tables['users'], 1)}";
    return $db->get_rows($sql);
  }

  public function get_user($id){
    $u = $this->class_cfg['arch']['users'];
    if ( bbn\str::is_uid($id) ){
      $where = [$u['id'] => $id];
    }
    else{
      $where = [$u['login'] => $id];
    }
    if ( $user = $this->db->rselect(
      $this->class_cfg['tables']['users'],
      array_values($u),
      $where)
    ){
      if ( $session = $this->db->rselect(
        $this->class_cfg['tables']['sessions'],
        array_values($this->class_cfg['arch']['sessions']),
        [$this->class_cfg['arch']['sessions']['id_user'] => $user[$u['id']]],
        [$this->class_cfg['arch']['sessions']['last_activity'] => 'DESC']
      ) ){
        $session['id_session'] = $session['id'];
      }
      else{
        $session = array_fill_keys(
          array_values($this->class_cfg['arch']['sessions']),
          '');
        $session['id_session'] = false;
      }
      return array_merge($session, $user);
    }
    return false;
  }

  public function get_group($id){
    $g = $this->class_cfg['arch']['groups'];
    if ( $group = $this->db->rselect($this->class_cfg['tables']['groups'], [], [
      $g['id'] => $id
    ]) ){
      $group[$g['cfg']] = $group[$g['cfg']] ? json_decode($group[$g['cfg']], 1) : [];
      return $group;
    }
    return false;
  }

  public function get_users($group_id = null){
    return $this->db->get_col_array("
      SELECT ".$this->class_cfg['arch']['users']['id']."
      FROM ".$this->class_cfg['tables']['users']."
      WHERE {$this->db->escape($this->class_cfg['tables']['users'].'.'.$this->class_cfg['arch']['users']['active'])} = 1
      AND ".$this->class_cfg['arch']['users']['id_group']." ".( $group_id ? "= ".(int)$group_id : "!= 1" )
    );
  }

  public function full_list(){
    $r = [];
    $u = $this->class_cfg['arch']['users'];
    foreach ( $this->db->rselect_all('bbn_users') as $a ){
      $r[] = [
        'value' => $a[$u['id']],
        'text' => $this->get_name($a, false),
        'id_group' => $a[$u['id_group']],
        'active' => $a[$u['active']] ? true : false
      ];
    }
    return $r;
  }

  public function get_user_id($login)
  {
    return $this->db->select_one(
      $this->class_cfg['tables']['users'], 
      $this->class_cfg['arch']['users']['id'],
      [
        $this->class_cfg['arch']['users']['login'] => $login
      ]);
  }

  public function get_admin_group()
  {
    if (!self::$admin_group) {
      if ($res = $this->db->select_one(
        $this->class_cfg['tables']['groups'], 
        $this->class_cfg['arch']['groups']['id'],
        [
          $this->class_cfg['arch']['groups']['code'] => 'admin'
        ]
        )
      ) {
        self::set_admin_group($res);
      }
    }
    return self::$admin_group;
  }

  public function get_dev_group()
  {
    if (!self::$dev_group) {
      if ($res = $this->db->select_one(
        $this->class_cfg['tables']['groups'], 
        $this->class_cfg['arch']['groups']['id'],
        [
          $this->class_cfg['arch']['groups']['code'] => 'dev'
        ]
        )
      ) {
        self::set_dev_group($res);
      }
    }
    return self::$dev_group;
  }

  public function get_name($user, $full = true){
    if ( !\is_array($user) ){
      $user = $this->get_user($user);
    }
    if ( \is_array($user) ){
      $idx = 'email';
      if ( !empty($this->class_cfg['arch']['users']['username']) ){
        $idx = 'username';
      }
      else if ( !empty($this->class_cfg['arch']['users']['login']) ){
        $idx = 'login';
      }
      return $user[$this->class_cfg['arch']['users'][$idx]];
    }
    return '';
  }

  public function get_group_type($id_group)
  {
    $g =& $this->class_cfg['arch']['groups'];
    return $this->db->select_one($this->class_cfg['tables']['groups'], $g['type'], [$g['id'] => $id_group]);
  }

  /**
   * Creates a new user and returns its configuration (with the new ID)
   * 
   * @param array $cfg A configuration array
	 * @return array|false
	 */
	public function add($cfg): ?array
	{
    $u =& $this->class_cfg['arch']['users'];
    $fields = array_unique(array_values($u));
    $cfg[$u['active']] = 1;
    $cfg[$u['cfg']] = '{}';
    foreach ( $cfg as $k => $v ){
      if ( !\in_array($k, $fields) ){
        unset($cfg[$k]);
      }
    }
    if ( isset($cfg['id']) ){
      unset($cfg['id']);
    }
    if (!empty($cfg[$u['id_group']])) {
      $group = $this->get_group_type($cfg[$u['id_group']]);
      switch ($group) {
        case 'real':
          if (
            bbn\str::is_email($cfg[$u['email']]) &&
                  $this->db->insert($this->class_cfg['tables']['users'], $cfg)
          ){
            $cfg[$u['id']] = $this->db->last_id();
  
            // Envoi d'un lien
            $this->make_hotlink($cfg[$this->class_cfg['arch']['users']['id']], 'creation');
            return $cfg;
          }
          break;
        case 'api':
          $cfg[$u['email']] = null;
          $cfg[$u['login']] = null;
          if ($this->db->insert($this->class_cfg['tables']['users'], $cfg)) {
            $cfg[$u['id']] = $this->db->last_id();
            return $cfg;
          }
          break;
      }
    }   
		return null;
	}
  
	/**
   * Creates a new user and returns its configuration (with the new ID)
   * 
   * @param array $cfg A configuration array
	 * @return array|false
	 */
	public function edit($cfg, $id_user=false)
	{
	  $u =& $this->class_cfg['arch']['users'];
    $fields = array_unique(array_values($this->class_cfg['arch']['users']));
    $cfg[$u['active']] = 1;
    foreach ( $cfg as $k => $v ){
      if ( !\in_array($k, $fields) ){
        unset($cfg[$k]);
      }
    }
    if ( !$id_user && isset($cfg[$u['id']]) ){
      $id_user = $cfg[$u['id']];
    }
    if ( $id_user && (
            !isset($cfg[$this->class_cfg['arch']['users']['email']]) ||
            bbn\str::is_email($cfg[$this->class_cfg['arch']['users']['email']])
          )
    ){
      if ( $this->db->update($this->class_cfg['tables']['users'], $cfg, [
        $u['id'] => $id_user
      ]) ){
        $cfg['id'] = $id_user;
        return $cfg;
      }
    }
		return false;
  }
  
  public function send_mail(string $id_user, string $subject, string $text, array $attachments = []): ?int
  {
    if ( !$this->get_mailer() ){
      die("Impossible to make hotlinks without a proper mailer parameter");
    }
    if ( ($usr = $this->get_user($id_user)) && $usr['email']){
      return $this->mailer->send([
        'to' => $usr['email'],
        'subject' => $subject,
        'text' => $text,
        'attachments' => $attachments
      ]);
    }
    return null;
  }
  
  /**
   *
   * @param string $id_user User ID
   * @param string $message Type of the message
   * @param int $exp Timestamp of the expiration date
   * @return manager
   */
  public function make_hotlink($id_user, $message='hotlink', $exp=null){
    if ( !isset($this->messages[$message]) || empty($this->messages[$message]['link']) ){
      die("Impossible to make hotlinks without a link configured");
    }
    if ( $usr = $this->get_user($id_user) ){
      // Expiration date
      if ( !\is_int($exp) || ($exp < 1) ){
        $exp = time() + $this->hotlink_length;
      }
      $hl =& $this->class_cfg['arch']['hotlinks'];
      // Expire existing valid hotlinks
      $this->db->update($this->class_cfg['tables']['hotlinks'], [
        $hl['expire'] => date('Y-m-d H:i:s')
      ],[
        [$hl['id_user'], '=', $id_user],
        [$hl['expire'], '>', date('Y-m-d H:i:s')]
      ]);
      $magic = $this->usrcls->make_magic_string();
      // Create hotlink
      $this->db->insert($this->class_cfg['tables']['hotlinks'], [
        $hl['magic_string'] => $magic['hash'],
        $hl['id_user'] => $id_user,
        $hl['expire'] => date('Y-m-d H:i:s', $exp)
      ]);
      $id_link = $this->db->last_id();
      $link = "?id=$id_link&key=".$magic['key'];
      $this->send_mail(
        $id_user,
        $this->messages[$message]['subject'],
        sprintf($this->messages[$message]['text'], sprintf($this->messages[$message]['link'], $link))
      );
    }
    else{
      die("User $id_user not found");
    }
    return $this;
  }
  
  /**
   * 
   * @param int $id_user User ID
   * @param int $id_group Group ID
   * @return manager
   */
  public function set_unique_group($id_user, $id_group): bool
  {
    return (bool)$this->db->update($this->class_cfg['tables']['users'], [
      $this->class_cfg['arch']['users']['id_group'] => $id_group
    ], [
      $this->class_cfg['arch']['users']['id'] => $id_user
    ]);
  }

  public function user_has_option($id_user, $id_option, $with_group = true){
    if ( $with_group && $user = $this->get_user($id_user) ){
      $id_group = $user[$this->class_cfg['arch']['users']['id_group']];
      if ( $this->group_has_option($id_group, $id_option) ){
        return true;
      }
    }
    if ( $pref = preferences::get_preferences() ){
      if ( $cfg = $pref->get_class_cfg() ){
        return $this->db->count($cfg['table'], [
          $cfg['cols']['id_option'] => $id_option,
          $cfg['cols']['id_user'] => $id_user
        ]) ? true : false;
      }
    }
    return false;
  }

  public function group_has_option($id_group, $id_option){
    if (
      ($pref = preferences::get_preferences()) &&
      ($cfg = $pref->get_class_cfg())
    ){
      return $this->db->count($cfg['table'], [
        $cfg['cols']['id_option'] => $id_option,
        $cfg['cols']['id_group'] => $id_group
      ]) ? true : false;
    }
    return false;
  }

  public function user_insert_option($id_user, $id_option){
    if (
      ($pref = preferences::get_preferences()) &&
      ($cfg = $pref->get_class_cfg())
    ){
      return $this->db->insert_ignore($cfg['table'], [
        $cfg['cols']['id_option'] => $id_option,
        $cfg['cols']['id_user'] => $id_user
      ]);
    }
    return false;
  }

  public function group_insert_option($id_group, $id_option){
    if (
      ($pref = preferences::get_preferences()) &&
      ($cfg = $pref->get_class_cfg())
    ){
      return $this->db->insert_ignore($cfg['table'], [
        $cfg['cols']['id_option'] => $id_option,
        $cfg['cols']['id_group'] => $id_group
      ]);
    }
    return false;
  }

  public function user_delete_option($id_user, $id_option){
    if (
      ($pref = preferences::get_preferences()) &&
      ($cfg = $pref->get_class_cfg())
    ){
      return $this->db->delete_ignore($cfg['table'], [
        $cfg['cols']['id_option'] => $id_option,
        $cfg['cols']['id_user'] => $id_user
      ]);
    }
    return false;
  }

  public function group_delete_option($id_group, $id_option){
    if (
      ($pref = preferences::get_preferences()) &&
      ($cfg = $pref->get_class_cfg())
    ){
      return $this->db->delete_ignore($cfg['table'], [
        $cfg['cols']['id_option'] => $id_option,
        $cfg['cols']['id_group'] => $id_group
      ]);
    }
    return false;
  }

  public function group_num_users($id_group){
    $u =& $this->class_cfg['arch']['users'];
    return $this->db->count($this->class_cfg['tables']['users'], [
      $u['id_group'] => $id_group
    ]);
  }

  public function group_insert($data){
    $g = $this->class_cfg['arch']['groups'];
    if ( isset($data[$g['group']]) ){
      if ( $this->db->insert($this->class_cfg['tables']['groups'], [
        $g['group'] => $data[$g['group']],
        $g['cfg'] => !empty($g['cfg']) && !empty($data[$g['cfg']]) ? $data[$g['cfg']] : '{}'
      ]) ){
        return $this->db->last_id();
      }
    }
    return false;
  }

  public function group_rename($id, $name){
    $g = $this->class_cfg['arch']['groups'];
    return $this->db->update($this->class_cfg['tables']['groups'], [
      $g['group'] => $name
    ], [
      $g['id'] => $id
    ]);
  }

  public function group_set_cfg($id, $cfg){
    $g = $this->class_cfg['arch']['groups'];
    return $this->db->update($this->class_cfg['tables']['groups'], [
      $g['cfg'] => $cfg ?: '{}'
    ], [
      $g['id'] => $id
    ]);
  }

  public function group_delete($id){
    $g = $this->class_cfg['arch']['groups'];
    if ( $this->group_num_users($id) ){
      /** @todo Error management */
      die("This group has users...");
    }
    return $this->db->delete($this->class_cfg['tables']['groups'], [
      $g['id'] => $id
    ]);
  }

  /**
   * @param int $id_user User ID
   * 
   * @return int|false Update result
	 */
	public function deactivate($id_user){
    $update = [
      $this->class_cfg['arch']['users']['active'] => 0,
      $this->class_cfg['arch']['users']['email'] => null,
    ];
    if ( !empty($this->class_cfg['arch']['users']['login']) ){
      $update[$this->class_cfg['arch']['users']['login']] = null;
    }

    if ($this->db->update($this->class_cfg['tables']['users'], $update, [
      $this->class_cfg['arch']['users']['id'] => $id_user
    ])) {
      // Deleting existing sessions
      $this->db->delete($this->class_cfg['tables']['sessions'], [$this->class_cfg['arch']['sessions']['id_user'] => $id_user]);
    }
	}

	/**
   * @param int $id_user User ID
   * 
   * @return manager
	 */
	public function reactivate($id_user){
    $this->db->update($this->class_cfg['tables']['users'], [
      $this->class_cfg['arch']['users']['active'] => 1
    ], [
      $this->class_cfg['arch']['users']['id'] => $id_user
    ]);
    return $this;
	}


  protected function set_default_list_fields(){
    $fields = $this->class_cfg['arch']['users'];
    unset($fields['id'], $fields['active'], $fields['cfg']);
    $this->list_fields = [];
    foreach ( $fields as $n => $f ){
      if ( !\in_array($f, $this->list_fields) ){
        $this->list_fields[$n] = $f;
      }
    }
  }

  protected static function set_admin_group($id)
  {
    self::$admin_group = $id;
  }

  protected static function set_dev_group($id)
  {
    self::$dev_group = $id;
  }

}
