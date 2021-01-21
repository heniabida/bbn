<?php
namespace bbn\user;

use bbn;
use bbn\x;
use bbn\str;
use bbn\user;

class emails extends bbn\models\cls\basic
{
  use bbn\models\tts\dbconfig;
  use bbn\models\tts\optional;

  /** @var array */
  protected static $default_class_cfg = [
    'table' => 'bbn_users_emails',
    'tables' => [
      'users_emails' => 'bbn_users_emails',
      'users_emails_aliases' => 'bbn_users_emails_aliases',
      'users_emails_recipients' => 'bbn_users_emails_recipients',
      'users_contacts' => 'bbn_users_contacts',
      'users_contacts_links' => 'bbn_users_contacts_links'
    ],
    'arch' => [
      'users_emails' => [
        'id' => 'id',
        'id_user' => 'id_user',
        'id_folder' => 'id_folder',
        'msg_uid' => 'msg_uid',
        'msg_unique_id' => 'msg_unique_id',
        'date' => 'date',
        'id_sender' => 'id_sender',
        'subject' => 'subject',
        'size' => 'size',
        'attachments' => 'attachments',
        'flags' => 'flags',
        'is_read' => 'is_read',
        'id_parent' => 'id_parent',
        'id_thread' => 'id_thread',
        'external_uids' => 'external_uids'
      ],
      'users_emails_aliases' => [
        'id_account' => 'id_account',
        'id_link' => 'id_link',
        'main' => 'main'
      ],
      'users_emails_recipients' => [
        'id_email' => 'id_email',
        'id_contact_link' => 'id_contact_link',
        'type' => 'type'
      ],
      'users_contacts' => [
        'id' => 'id',
        'id_user' => 'id_user',
        'name' => 'name',
        'blacklist' => 'blacklist',
        'cfg' => 'cfg'
      ],
      'users_contacts_links' => [
        'id' => 'id',
        'id_contact' => 'id_contact',
        'type' => 'type',
        'value' => 'value',
        'num_sent' => 'num_sent',
        'last_sent' => 'last_sent'
      ]
    ]
  ];


  /** @var array An array of connection objects */
  protected $mboxes = [];

  /** @var user The user object */
  protected $user;

  /** @var preferences The preferences object */
  protected $pref;

  /** @var bbn\appui\option The options object */
  protected $opt;

  /** @var bbn\appui\passwords The passwords object */
  protected $pw;


  public static function get_folder_types(): array
  {
    return self::get_options('folders');
  }


  public static function get_account_types(): array
  {
    return self::get_options('types');
  }


  public function __construct(bbn\db $db, user $user = null, preferences $preferences = null)
  {
    self::optional_init();
    $this->_init_class_cfg();
    $this->db   = $db;
    $this->user = $user ?: bbn\user::get_instance();
    $this->pref = $preferences ?: bbn\user\preferences::get_instance();;
  }


  public function get_mailbox(string $id_account)
  {
    if (!isset($this->mboxes[$id_account])) {
      $this->get_account($id_account);
    }

    if (isset($this->mboxes[$id_account])) {
      $mb = &$this->mboxes[$id_account];
      if (!isset($mb['mailbox'])) {
        $cfg           = $this->mboxes[$id_account];
        $cfg['pass']   = $this->_get_password()->user_get($id_account, $this->user);
        $mb['mailbox'] = new bbn\appui\mailbox($cfg);
      }

      if (isset($mb['mailbox'])) {
        return $mb['mailbox'];
      }
    }

    return null;

  }


  /**
   * Returns the list of the accounts' IDs of the current user.
   *
   * @param bool $force 
   * @return array|null
   */
  public function get_accounts_ids(): ?array
  {
    if ($id_accounts = self::get_option_id('accounts')) {
      return $this->pref->retrieve_ids($id_accounts);
    }

    return null;
  }


  /**
   * Returns the list of the accounts of the current user.
   *
   * @param bool $force 
   * @return array|null
   */
  public function get_accounts(bool $force = false): array
  {
    $res = [];
    if ($ids = $this->get_accounts_ids()) {
      foreach ($ids as $id) {
        $res[] = $this->get_account($id, $force);
      }
    }

    return $res;
  }


  public function get_account(string $id_account, bool $force = false): ?array
  {
    if ($force || !isset($this->mboxes[$id_account])) {
      if ($a = $this->pref->get($id_account)) {
        $this->mboxes[$id_account] = [
          'id' => $a['id'],
          'host' => $a['host'] ?? null,
          'login' => $a['login'],
          'type' => $a['type'],
          'port' => $a['port'] ?? null,
          'ssl' => $a['ssl'] ?? true,
          'folders' => null,
          'last_uid' => $a['last_uid'] ?? null,
          'last_check' => $a['last_check'] ?? null
        ];
        $this->mboxes[$id_account]['folders'] = $this->get_folders($this->mboxes[$id_account]);
      }
    }
    return $this->mboxes[$id_account] ?? null;
  }


  public function check_config($cfg): bool
  {
    if (x::has_props(['login', 'pass', 'type'], true)) {
      $mb = new bbn\appui\mailbox($cfg);
      return $mb->check();
    }
  }


  public function update_account(string $id_account, array $cfg): bool
  {
    if (x::has_props($cfg, ['login', 'pass', 'type'], true)
        && ($acc = $this->get_account($id_account))
        && ($this->pref->set_cfg(
          $id_account,
          [
            'host' => $cfg['host'] ?? null,
            'login' => $cfg['login'],
            'type' => $cfg['type'],
            'port' => $cfg['port'] ?? null,
            'ssl' => $cfg['ssl'] ?? true,
            'last_uid' => $cfg['last_uid'] ?? null,
            'last_check' => $cfg['last_check'] ?? null
          ]
        ))
    ) {
      return true;
    }

    return false;
  }


  public function delete_account(string $id_account): bool
  {
    return (bool)$this->pref->delete($id_account);
  }


  public function add_account(array $cfg): ?string
  {
    if (x::has_props($cfg, ['login', 'pass', 'type'], true)
        && ($id_accounts = self::get_option_id('accounts'))
        && ($id_pref = $this->pref->add_to_group(
          $id_accounts,
          [
            'id_user' => $this->user->get_id(),
            'login' => $cfg['login'],
            'type' => $cfg['type'],
            'host' => $cfg['host'] ?? null,
            'port' => $cfg['port'] ?? null,
            'ssl' => $cfg['ssl'] ?? true
          ]
        ))
        && $this->_get_password()->user_store($cfg['pass'], $id_pref, $this->user)
    ) {
      $this->get_account($id_pref, true);
      if (!empty($cfg['folders'])) {
        $this->sync_folders($id_pref, $cfg['folders']);
      }
      return $id_pref;
    }

    return null;
  }


  public function reset(string $id_account): bool
  {
    if (($account = $this->get_account($id_account))
        && ($num = $this->pref->delete_bits($id_account))
    ) {
      return true;
    }

    return false;
  }


  public function create_folder(string $id_account, string $name, string $id_parent = null): bool
  {

    $this->create_folder_db($id, $id_parent);
  }


  public function create_folder_db(string $id_account, string $name, string $id_parent = null): bool
  {

  }


  public function rename_folder(string $id, string $name): bool
  {

    $this->rename_folder_db($id, $name);
  }


  public function rename_folder_db(string $id, string $name): bool
  {

  }


  public function delete_folder(string $id): bool
  {
    $this->delete_folder_db($id);
  }


  public function delete_folder_db(string $id): bool
  {

  }


  public function check_folder(array $folder, $sync = false)
  {
    if (x::has_prop($folder, 'uid')
        && ($mb = $this->get_mailbox($folder['id_account']))
        && $mb->check()
    ) {
      if ($mb->update($folder['uid'])
          && ($folders = $mb->get_folders())
          && ($res = $folders[$folder['uid']])
      ) {
        if ($folder['last_uid'] !== $res['last_uid']) {
          $id_account = $folder['id_account'];
          unset($folder['id_account']);
          $res = array_merge($folder, $res);
          $this->pref->update_bit($folder['id'], $res, true);
          $res['id_account'] = $id_account;
          $this->get_account($id_account, true);
          if ($sync) {
            $this->sync_emails($res);
          }
        }

        return $res;
      }
    }

    return null;

  }


  public function get_folders($account, $force = false)
  {
    $acc = is_array($account) ? $account : $acc = $this->get_account($account);
    if ($acc) {
      $types = self::get_folder_types();
      if ($force) {
        $this->sync_folders($acc['id']);
      }

      $cfg   = $this->class_cfg['arch']['users_emails'];
      $table = $this->class_cfg['tables']['users_emails'];
      //die(x::dump($this->pref->get_full_bits($acc['id'])));
      return x::map(
        function ($a) use ($types, $cfg, $table) {
          if (!isset($a['uid'])) {

            //die(x::dump("NO UID", $a, debug_backtrace()));
          }

          $res = [
            'id' => $a['id'],
            'id_account' => $a['id_user_option'],
            'text' => $a['text'],
            'uid' => $a['uid'],
            'id_option' => $a['id_option'],
            'type' => x::get_field($types, ['id' => $a['id_option']], 'code'),
            'db_uid' => $this->db->select_one(
              $table,
              'MAX('.$this->db->csn($cfg['msg_uid'], true).')',
              [
                $cfg['id_folder'] => $a['id'],
                $cfg['id_user'] => $this->user->get_id()
              ]
            ),
            'last_uid' => $a['last_uid'] ?? null,
            'last_check' => $a['last_check'] ?? null
          ];
          if (!empty($a['items'])) {
            $res['items'] = $a['items'];
          }

          return $res;
        },
        $this->pref->get_full_bits($acc['id']),
        'items'
      );
    }

    return null;
  }


  public function get_folder(string $id, bool $force = false): ?array
  {
    $types = self::get_folder_types();
    $cfg   = $this->class_cfg['arch']['users_emails'];
    $table = $this->class_cfg['tables']['users_emails'];
    $a = $this->pref->get_bit($id);
    if ($a) {
      return [
        'id' => $a['id'],
        'id_account' => $a['id_user_option'],
        'text' => $a['text'],
        'uid' => $a['uid'],
        'id_option' => $a['id_option'],
        'type' => x::get_field($types, ['id' => $a['id_option']], 'code'),
        'db_uid' => $this->db->select_one(
          $table,
          'MAX('.$this->db->csn($cfg['msg_uid'], true).')',
          [
            $cfg['id_folder'] => $a['id'],
            $cfg['id_user'] => $this->user->get_id()
          ]
        ),
        'last_uid' => $a['last_uid'] ?? null,
        'last_check' => $a['last_check'] ?? null
      ];
    }

    return null;
  }


  public function sync_emails(array $folder, int $limit = 0): ?int
  {
    if (x::has_props($folder, ['id', 'id_account', 'last_uid', 'uid'])) {
      $res = 0;
      if ($folder['last_uid'] && ($folder['last_uid'] !== $folder['db_uid'])) {
        $mb = $this->get_mailbox($folder['id_account']);
        if ($mb->select_folder($folder['uid'])) {
          $start = 1;
          if (!empty($folder['db_uid'])) {
            try {
              $start = $mb->get_msg_no($folder['db_uid']);
            }
            catch (\Exception $e) {
              $start = 1;
            }
          }
          $real_end = 1;
          if (!empty($folder['last_uid'])) {
            try {
              $real_end = $mb->get_msg_no($folder['last_uid']);
            }
            catch (\Exception $e) {
              $real_end = 1;
            }
          }

          /** @todo temporary solution to avoid errors */
          if ($start === $real_end) {
            return 0;
          }

          if ($limit) {
            $real_end = min($real_end, $start + $limit);
          }

          $end      = $start;
          $num      = $real_end - $start;
          //var_dump($folder, $num, $real_end);
          while ($end <= $real_end) {
            $end = min($real_end, $start + 999);
            if ($all = $mb->get_emails_list($folder['uid'], $start, $end)) {
              $start += 1000;
              //var_dump($start, $end);
              foreach ($all as $a) {
                if ($this->insert_email($folder, $a)) {
                  $res++;
                }
                else {
                  //throw new \Exception(_("Impossible to insert the email with ID").' '.$a['message_id']);
                  $this->log(_("Impossible to insert the email with ID").' '.$a['message_id']);
                }
              }

              if ($end === $real_end) {
                $this->pref->update_bit($folder['id'], ['last_check' => date('Y-m-d H:i:s')], true);
                break;
              }
            }
            else {
              throw new \Exception(
                _("Impossible to get the emails for folder")
                .' '.$folder['uid']
                .' '._("from").' '.$start
                .' '._("to").' '.$end
                .' ('.$real_end.')'
              );
            }
          }
        }
      }
      return $res;
    }

    return null;
  }


  /**
   * Returns a list of emails based on their folder.
   *
   * @param string $id_folder
   * @param array $filter
   * @param int $limit
   * @param int $start
   *
   * @return array|null
   */
  public function get_list(string $id_folder, array $post): ?array
  {
    if ($ids = $this->ids_from_folder($id_folder)) {
      $cfg      = $this->class_cfg['arch']['users_emails'];
      $table    = $this->class_cfg['tables']['users_emails'];
      $real_filter = [
        'logic' => 'AND',
        'conditions' => [
          $cfg['id_folder'] => $ids
        ]
      ];
      if (!empty($post['filters'])) {
        if (!isset($post['filters']['conditions'])) {
          $post['filters'] = ['conditions' => $post['filters']];
        }

        if (!empty($post['filters']['conditions'])) {
          $real_filter['conditions'][] = $post['filters'];
        }
      }

      $post['filters'] = $real_filter;
      $grid = new \bbn\appui\grid($this->db, $post, [
        'table' => $table,
        'fields' => $cfg
      ]);
      if ( $grid->check() ){
        return $grid->get_datatable();
      }
    }

    return null;
  }


  public function get_email($id): ?array
  {
    $cfg      = $this->class_cfg['arch']['users_emails'];
    $table    = $this->class_cfg['tables']['users_emails'];
    $em = $this->db->rselect($table, $cfg, [$cfg['id'] => $id]);
    if ($em) {
      $folder = $this->get_folder($em['id_folder']);
      if ($folder
          && ($mb = $this->get_mailbox($folder['id_account']))
          && $mb->select_folder($folder['uid'])
          && ($number = $mb->get_msg_no($em['msg_uid']))
      ) {
        return $mb->get_msg($number);
      }
    }
    return null;
  }


  public function insert_email(array $folder, array $email)
  {
    if (x::has_props($email, ['from', 'uid'])) {
      $cfg      = $this->class_cfg['arch']['users_emails'];
      $table    = $this->class_cfg['tables']['users_emails'];
      $existing = $this->db->select_one(
        $table,
        $cfg['id'],
        [
          $cfg['id_user'] => $this->user->get_id(),
          $cfg['msg_unique_id'] => $email['message_id']
        ]
      );
      foreach (bbn\appui\mailbox::get_dest_fields() as $df) {
        if (!empty($email[$df])) {
          foreach ($email[$df] as &$dest) {
            if ($id = $this->retrieve_email($dest['email'])) {
              $sent_opt = x::get_field(self::get_folder_types(), ['code' => 'sent'], 'id');
              if ($sent_opt === $folder['id_option']) {
                $this->add_sent_to_link($id, date('Y-m-d H:i:s', strtotime($email['date'])));
              }
            }
            elseif (!($id = $this->add_contact_from_mail($dest))) {
              throw new \Exception(_("Impossible to add the contact").' '.$dest['email']);
            }

            $dest['id'] = $id;
          }

          if ($df === 'from') {
            $id_sender = $id;
          }
        }
      }

      if (!empty($id_sender)) {
        $id_parent = null;
        $id_thread = null;
        if (!empty($email['in_reply_to'])) {
          $tmp = $this->db->rselect(
            $table,
            [$cfg['id'], $cfg['id_thread']],
            [
              $cfg['id_user'] => $this->user->get_id(),
              $cfg['msg_unique_id'] => $email['in_reply_to']
            ]
          );
          if ($tmp) {
            $id_parent = $tmp[$cfg['id']];
            $id_thread = $tmp[$cfg['id_thread']] ?: $id_parent;
          }
        }

        //die(var_dump($email));
        $external = null;
        if (!empty($email['in_reply_to']) || !empty($email['references'])) {
          $external = [
            'in_reply_to' => $email['in_reply_to'] ?? null,
            'references'  => $email['references'] ?? null
          ];
        }

        $ar = [
          $cfg['id_user'] => $this->user->get_id(),
          $cfg['id_folder'] => $folder['id'],
          $cfg['msg_uid'] => $email['uid'],
          $cfg['msg_unique_id'] => $email['message_id'],
          $cfg['date'] => date('Y-m-d H:i:s', strtotime($email['date'])),
          $cfg['id_sender'] => $id_sender,
          $cfg['subject'] => empty($email['subject']) ? '' : mb_decode_mimeheader($email['subject']),
          $cfg['size'] => $email['Size'],
          $cfg['attachments'] => empty($email['attachments']) ? null : json_encode($email['attachments']),
          $cfg['flags'] => $email['Flagged'] ?: null,
          $cfg['is_read'] => $email['Unseen'] ? 0 : 1,
          $cfg['id_parent'] => $id_parent,
          $cfg['id_thread'] => $id_thread,
          $cfg['external_uids'] => json_encode($external)
        ];
        $id = false;
        if ($existing) {
          //die(var_dump($ar));
          //$this->db->update($table, $ar, [$cfg['id'] => $existing]);
          $id = $existing;
        }
        elseif ($this->db->insert($table, $ar)) {
          $id = $this->db->last_id();
        }

        if ($id) {
          foreach (bbn\appui\mailbox::get_dest_fields() as $df) {
            if (in_array($df, ['to', 'cc', 'bcc']) && !empty($email[$df])) {
              foreach ($email[$df] as $dest) {
                if (!empty($dest['id'])) {
                  $this->add_link_to_mail($id, $dest['id'], $df);
                }
              }
            }
          }

          return $id;
        }
      }
    }
    $this->log($email);
    //throw new \Exception(_("Invalid email"));
  }


  public function add_contact_from_mail(array $dest, bool $blacklist = false): ?string
  {
    if (x::has_prop($dest, 'email', true)) {
      $cfg_contacts   = $this->class_cfg['arch']['users_contacts'];
      $cfg_links      = $this->class_cfg['arch']['users_contacts_links'];
      $table_contacts = $this->class_cfg['tables']['users_contacts'];
      $table_links = $this->class_cfg['tables']['users_contacts_links'];
      if ($this->db->insert($table_contacts, [
        $cfg_contacts['id_user']   => $this->user->get_id(),
        $cfg_contacts['name']      => $dest['name'] ?? null,
        $cfg_contacts['blacklist'] => $blacklist ? 1 : 0
      ])) {
        $id_contact = $this->db->last_id();
        if ($this->db->insert($table_links, [
          'id_contact' => $id_contact,
          'type' => 'email',
          'value' => $dest['email']
        ])) {
          return $this->db->last_id();
        }
      }
    }

    return null;
  }


  public function get_link($id): ?array
  {
    $cfg   = $this->class_cfg['arch']['users_contacts_links'];
    $table = $this->class_cfg['tables']['users_contacts_links'];
    $data  = $this->db->rselect($table, $cfg, [$cfg['id'] => $id]);
    return $data ?: null;
  }


  public function add_link_to_mail(string $id_email, string $id_link, string $type): bool
  {
    $cfg   = $this->class_cfg['arch']['users_emails_recipients'];
    $table = $this->class_cfg['tables']['users_emails_recipients'];
    return (bool)$this->db->insert_ignore(
      $table,
      [
        $cfg['id_email'] => $id_email,
        $cfg['id_contact_link'] => $id_link,
        $cfg['type'] => $type
      ]
    );

  }


  public function add_sent_to_link(string $id_link, string $date = null): bool
  {
    if ($link = $this->get_link($id_link)) {
      $cfg   = $this->class_cfg['arch']['users_contacts_links'];
      $table = $this->class_cfg['tables']['users_contacts_links'];
      if (!$date) {
        $date = date('Y-m-d H:i:s');
      }
      if ($link['last_sent'] && ($link['last_sent'] > $date))  {
        $date = $link['last_sent'];
      }

      return (bool)$this->db->update(
        $table,
        [
          $cfg['num_sent']  => $link[$cfg['num_sent']] + 1,
          $cfg['last_sent'] => $date
        ], [
          'id' => $id_link
        ]
      );
    }

    return false;
  }


  public function retrieve_email(string $email)
  {
    $contacts = $this->class_cfg['tables']['users_contacts'];
    $cfg_c    = $this->class_cfg['arch']['users_contacts'];
    $links    = $this->class_cfg['tables']['users_contacts_links'];
    $cfg_l    = $this->class_cfg['arch']['users_contacts_links'];
    return $this->db->select_one(
      [
        'tables' => [$links],
        'field'  => $this->db->cfn($cfg_l['id'], $links),
        'join'   => [
          [
            'table' => $contacts,
            'on'    => [
              [
                'field' => $cfg_l['id_contact'],
                'exp'   => $this->db->cfn($cfg_c['id'], $contacts)
              ]
            ]

          ]
        ],
        'where' => [
          'value' => $email,
          'id_user' => $this->user->get_id(),
          'type' => 'email'
        ]
      ]
    );
  }


  public function get_contact(string $email, string $name, $force)
  {

  }


  public function get_contacts(): array
  {
    $contacts = $this->class_cfg['tables']['users_contacts'];
    $cfg_c    = $this->class_cfg['arch']['users_contacts'];
    $links    = $this->class_cfg['tables']['users_contacts_links'];
    $cfg_l    = $this->class_cfg['arch']['users_contacts_links'];
    $rows = $this->db->rselect_all(
      [
        'tables' => [$links],
        'fields'  => [
          $this->db->cfn($cfg_l['id'], $links),
          $this->db->cfn($cfg_l['value'], $links),
          $this->db->cfn($cfg_l['id_contact'], $links),
          $this->db->cfn($cfg_l['num_sent'], $links),
          $this->db->cfn($cfg_l['last_sent'], $links),
          $this->db->cfn($cfg_c['name'], $contacts),
          $this->db->cfn($cfg_c['cfg'], $contacts),
          $this->db->cfn($cfg_c['blacklist'], $contacts),
          'sortIndex' => 'IFNULL('.$this->db->cfn($cfg_c['name'], $contacts, true).','.$this->db->cfn($cfg_l['value'], $links).')'
        ],
        'join'   => [
          [
            'table' => $contacts,
            'on'    => [
              [
                'field' => $cfg_l['id_contact'],
                'exp'   => $this->db->cfn($cfg_c['id'], $contacts)
              ]
            ]

          ]
        ],
        'where' => [
          'id_user' => $this->user->get_id(),
          'type' => 'email'
        ],
        'order' => [
          'sortIndex' => 'ASC'
        ]
      ]
    );
    $res = [];
    if ($rows) {
      foreach ($rows as $r) {
        $res[] = [
          'value' => $r['id'],
          'text' => (empty($r['name']) ? '' : $r['name'].' - ').$r['value'],
          'cfg' => empty($r['cfg']) ? [] : json_decode($r['cfg'], true),
          'id_contact' => $r['id_contact'],
          'num_sent' => $r['num_sent'],
          'last_sent' => $r['last_sent'],
          'blacklist' => $r['blacklist']
        ];
      }
    }

    return $res;
  }


  public function sync_folders(string $id_account, array $subscribed = [])
  {
    if ($mb = $this->get_mailbox($id_account)) {
      $mbParam = $mb->get_params();
      $types   = self::get_folder_types();

      $put_in_res = function (array $a, &$res, $prefix = '') use (&$put_in_res, $subscribed) {
        $ele = array_shift($a);
        $idx = x::find($res, ['text' => $ele]);
        if (null === $idx) {
          $idx   = count($res);
          $res[] = [
            'text' => $ele,
            'uid' => $prefix.$ele,
            'items' => [],
            'subscribed' => in_array($prefix.$ele, $subscribed)
          ];
        }

        if (count($a)) {
          $put_in_res($a, $res[$idx]['items'], $prefix.$ele.'.');
        }
      };

      $compare = function (
          array $real,
          array $db,
          array &$res = null,
          $id_parent = null
      ) use (&$compare): array {
        if (!$res) {
          $res = ['add' => [], 'delete' => []];
        }

        foreach ($real as $r) {
          $idx = x::find($db, ['text' => $r['text']]);
          if (null === $idx) {
            if ($id_parent) {
              $r['id_parent'] = $id_parent;
            }

            $res['add'][] = $r;
          }
          elseif ($r['items'] && $db[$idx]['items']) {
            $compare($r['items'], $db[$idx]['items'], $res, $db[$idx]['id']);
          }
        }

        foreach ($db as $r) {
          $idx = x::find($real, ['text' => $r['text']]);
          if (null === $idx) {
            $res['delete'][] = $r;
          }
        }

        return $res;
      };

      $pref = $this->pref;

      $import = function (array $to_add, $id_parent = null) use ($id_account, &$pref, &$import, &$types) {
        foreach ($to_add as $a) {
          if ($id_parent) {
            $a['id_parent'] = $id_parent;
            $a['id_option'] = x::get_field($types, ['code' => 'folders'], 'id');
          }
          else {
            foreach ($types as $type) {
              if (!empty($type['names'])) {
                if (in_array($a['text'], $type['names'], true)) {
                  $a['id_option'] = $type['id'];
                  break;
                }
              }
            }

            if (!isset($a['id_option'])) {
              $a['id_option'] = x::get_field($types, ['code' => 'folders'], 'id');
            }
          }

          if ($id_bit = $pref->add_bit($id_account, $a)) {
            if (!empty($a['items'])) {
              $import($a['items'], $id_bit);
            }
          }
        }
      };

      $res = [];
      $all = $mb->list_all_folders();
      foreach ($all as $dir) {
        $tmp = str_replace($mbParam, '', $dir);
        $bits = x::split($tmp, '.');
        $put_in_res($bits, $res);
      }

      // We have a tree
      $db_tree = $this->pref->get_full_bits($id_account);

      $result = $compare($res, $db_tree);

      $import($result['add']);

      return ['real' => $res, 'db' => $db_tree, 'compare' => $result];
    }

    return null;
  }


  public function get_structure($id_account, $force)
  {

  }


  protected function ids_from_folder($id_folder): ?array
  {
    $cfg      = $this->class_cfg['arch']['users_emails'];
    $table    = $this->class_cfg['tables']['users_emails'];
    $types    = self::get_folder_types();
    if ($common_folder = x::get_row($types, ['id' => $id_folder])) {
      $ids = [];
      $accounts = $this->get_accounts();
      foreach ($accounts as $a) {
        foreach ($this->get_folders($a['id']) as $f) {
          if ($f['id_option'] === $common_folder['id']) {
            $ids[] = $f['id'];
          }
        }
      }
    }
    elseif (str::is_uid($id_folder)) {
      $bit = $this->pref->get_bit($id_folder);
      if (!$bit) {
        // It's not a folder but an account
        if ($pref = $this->pref->get($id_folder)) {
          // we look for inbox
        }
      }
      else {
        $ids = [$id_folder];
      }
    }
    else if ($id_folder === 'conversations') {
      $inbox = x::get_row($types, ['code' => 'inbox']);
      $sent = x::get_row($types, ['code' => 'sent']);
      $ids = [];
      $accounts = $em->get_accounts();
      foreach ($accounts as $a) {
        foreach ($em->get_folders($a['id']) as $f) {
          if (($f['id_option'] === $inbox['id']) || ($f['id_option'] === $sent['id'])) {
            $ids[] = $f['id'];
          }
        }
      }
    }

    if (!empty($ids)) {
      return $ids;
    }

    return null;
  }


  private function _get_password(): bbn\appui\passwords
  {
    if (!$this->pw) {
      $this->pw = new bbn\appui\passwords($this->db);
    }

    return $this->pw;
  }


}
