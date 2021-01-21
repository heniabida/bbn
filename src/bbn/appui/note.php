<?php
/**
 * Created by PhpStorm.
 * User: BBN
 * Date: 14/04/2016
 * Time: 20:38
 */

namespace bbn\appui;

use bbn;

if (!\defined('BBN_DATA_PATH')) {
  die("The constant BBN_DATA_PATH must be defined in order to use note");
}

class note extends bbn\models\cls\db
{

  use
    bbn\models\tts\references,
    bbn\models\tts\optional,
    bbn\models\tts\dbconfig;

  private $medias; 

  protected static
    /** @var array */
    $default_class_cfg = [
      'errors' => [
        19 => 'wrong fingerprint'
      ],
      'table' => 'bbn_notes',
      'tables' => [
        'notes' => 'bbn_notes',
        'versions' => 'bbn_notes_versions',
        'nmedias' => 'bbn_notes_medias',
        'medias' => 'bbn_medias'
      ],
      'arch' => [
        'notes' => [
          'id' => 'id',
          'id_parent' => 'id_parent',
                    'id_alias' => 'id_alias',
          'id_type' => 'id_type',
          'private' => 'private',
                    'locked' => 'locked',
          'pinned' => 'pinned',
          'creator' => 'creator',
          'active' => 'active'
        ],
        'versions' => [
          'id_note' => 'id_note',
          'version' => 'version',
          'title' => 'title',
          'content' => 'content',
          'id_user' => 'id_user',
          'creation' => 'creation'
        ],
        'nmedias' => [
          'id' => 'id',
          'id_media' => 'id_media',
          'id_note' => 'id_note',
          'version' => 'version',
          'id_user' => 'id_user',
          'comment' => 'comment',
          'creation' => 'creation',
        ],
        'medias' => [
          'id' => 'id',
          'id_user' => 'id_user',
          'type' => 'type',
          'name' => 'name',
          'title' => 'title',
          'content' => 'content',
          'private' => 'private'
        ]
      ],
            'paths' => [
                'medias' => 'media/'
            ]
    ];

  public function __construct(bbn\db $db)
  {
    parent::__construct($db);
    self::_init_class_cfg(self::$default_class_cfg);
    self::optional_init();
  }

  public function get_media_instance()
  {
    if (!$this->medias) {
      $this->medias = new medias($this->db);
    }
    return $this->medias;
  }

  public function insert(string $title, string $content, string $type = null, bool $private = false, bool $locked = false, string $parent = null, string $alias = null)
  {
        $cf =& $this->class_cfg;
    if (is_null($type)) {
      $type = self::get_option_id('personal', 'types');
    }
    if (($usr = bbn\user::get_instance()) 
        && $this->db->insert(
          $cf['table'], [
          $cf['arch']['notes']['id_parent'] => $parent,
                $cf['arch']['notes']['id_alias'] => $alias,
          $cf['arch']['notes']['id_type'] => $type,
          $cf['arch']['notes']['private'] => !empty($private) ? 1 : 0,
          $cf['arch']['notes']['locked'] => !empty($locked) ? 1 : 0,
          $cf['arch']['notes']['creator'] => $usr->get_id()
          ]
        ) 
        && ($id_note = $this->db->last_id()) 
        && $this->insert_version($id_note, $title, $content)
    ) {
      return $id_note;
    }
    return false;
  }

  /**
   * Adds a new version to the given note.
   *
   * @param string $id_note
   * @param string $title
   * @param string $content
   * @return integer|null
   */
  public function insert_version(string $id_note, string $title, string $content): ?int
  {
    if ($this->check() && ($usr = bbn\user::get_instance()) && ($note = $this->get($id_note))) {
      $cf =& $this->class_cfg;
      $latest = $note['version'] ?? 0;
      if (!$latest || ($note['content'] !== $content) || ($note['title'] !== $title)) {
        $next = $latest + 1;
      }
      if (isset($next) && $this->db->insert(
        $cf['tables']['versions'], [
        $cf['arch']['versions']['id_note'] => $id_note,
        $cf['arch']['versions']['version'] => $next,
        $cf['arch']['versions']['title'] => $title,
        $cf['arch']['versions']['content'] => $content,
        $cf['arch']['versions']['id_user'] => $usr->get_id(),
        $cf['arch']['versions']['creation'] => date('Y-m-d H:i:s')
        ]
      )
      ) {
        return $next;
      }
      return $latest;
    }
    return null;
  }

  public function update(string $id, string $title, string $content, bool $private = null, bool $locked = null): ?int
  {
    $ok = null;
    if ($old = $this->db->rselect('bbn_notes', [], ['id' => $id])) {  
      bbn\x::hdump('update',$old, $id, $title, $content);    
      $ok = 0;
      $new = [];
      if (!\is_null($private) && ($private != $old['private'])) {
        $new['private'] = $private;
      }
      if (!\is_null($locked) && ($locked != $old['locked'])) {
        $new['locked'] = $locked;
      }
      if (!empty($new)) {
        $ok = $this->db->update('bbn_notes', $new, ['id' => $id]);
      }
      if ($old_v = $this->get($id)) {
        $changed = false;
        $new_v = [
          'title' => $old_v['title'],
          'content' => $old_v['content']
        ];
        if ($title !== $old_v['title']) {
          $changed = true;
          $new_v['title'] = $title;
        }
        if ($content !== $old_v['content']) {
          $changed = true;
          $new_v['content'] = $content;
        }
        if (!empty($changed)) {         
          $ok = $this->insert_version($id, $new_v['title'], $new_v['content']);    
        }
      }
    }
    return $ok;
  }

  public function latest($id)
  {
    $cf =& $this->class_cfg;
    return $this->db->select_one(
      $cf['tables']['versions'], 'MAX('.$cf['arch']['versions']['version'].')', [
      $cf['arch']['versions']['id_note'] => $id
      ]
    );
  }

  public function get(string $id, int $version = null, bool $simple = false):? array
  {
    $cf =& $this->class_cfg;
    if (!\is_int($version)) {
      $version = $this->latest($id) ?: 1;
    }
    if ($res = $this->db->rselect(
      $cf['tables']['notes'], [], [
      $cf['arch']['notes']['id'] => $id
      ]
    )
    ) {
      if ($tmp = $this->db->rselect(
        $cf['tables']['versions'], [], [
        $cf['arch']['versions']['id_note'] => $id,
        $cf['arch']['versions']['version'] => $version
        ]
      )
      ) {
        $res = array_merge($res, $tmp);
      }
      if ($simple) {
        unset($res[$cf['arch']['versions']['content']]);
      }
      else {
        if ($medias = $this->db->get_column_values(
          $cf['tables']['nmedias'], $cf['arch']['nmedias']['id_media'], [
          $cf['arch']['nmedias']['id_note'] => $id,
          $cf['arch']['nmedias']['version'] => $version
          ]
        ) 
        ) {
          $media = $this->get_media_instance();
          $res['medias'] = [];
          foreach ($medias as $m) {
            $res['medias'][] = $media->get_media($m, true);
          }
        }
      }
      return $res;
    }
    return null;
  }

  public function get_full(string $id, int $version = null):? array
  {
    $cf =& $this->class_cfg;
    if (!\is_int($version)) {
      $version = $this->latest($id);
    }
    if ($res = $this->db->rselect(
      [
      'table' => $cf['table'],
      'fields' => [
        $cf['arch']['notes']['id'],
        $cf['arch']['notes']['id_parent'],
        $cf['arch']['notes']['id_alias'],
        $cf['arch']['notes']['id_type'],
        $cf['arch']['notes']['private'],
        $cf['arch']['notes']['locked'],
        $cf['arch']['notes']['pinned'],
        $cf['arch']['versions']['version'],
        $cf['arch']['versions']['title'],
        $cf['arch']['versions']['content'],
        $cf['arch']['versions']['id_user'],
        $cf['arch']['versions']['creation']
      ],
      'join' => [[
        'table' => $cf['tables']['versions'],
        'on' => [
          'conditions' => [[
            'field' => $cf['arch']['versions']['id_note'],
            'exp' => $cf['arch']['notes']['id'],
          ], [
            'field' => $cf['arch']['versions']['version'],
            'value' => $version
          ]]
        ]
      ]],
      'where' => [
        'conditions' => [[
          'field' => $cf['arch']['notes']['id'],
          'value' => $id
        ]]
      ]
      ]
    ) 
    ) {
      $res['medias'] = $this->get_medias($id, $version);
      return $res;
    }
    return null;
  }

  public function get_by_type($type = null, $id_user = false, $limit = 0, $start = 0)
  {
    $db =& $this->db;
    $cf =& $this->class_cfg;
    $res = [];
    if (!\bbn\str::is_uid($type)) {
      $type = $type = self::get_option_id(is_null($type) ? 'personal' : $type, 'types');
    }
    if (\bbn\str::is_uid($type) && is_int($limit) && is_int($start)) {
      $where = [[
        'field' => $db->cfn($cf['arch']['notes']['id_type'], $cf['table']),
        'value' => $type
      ], [
        'field' => $db->cfn($cf['arch']['notes']['active'], $cf['table']),
        'value' => 1
      ], [
        'field' => 'versions2.'.$cf['arch']['versions']['version'],
        'operator' => 'isnull'
      ]];
      if (\bbn\str::is_uid($id_user)) {
        $where[] = [
          'field' => $db->cfn($cf['arch']['notes']['creator'], $cf['table']),
          'value' => $id_user
        ];
      }
      $notes = $db->rselect_all(
        [
        'table' => $cf['table'],
        'fields' => [
          'versions1.'.$cf['arch']['versions']['id_note'],
          'versions1.'.$cf['arch']['versions']['version'],
          'versions1.'.$cf['arch']['versions']['title'],
          'versions1.'.$cf['arch']['versions']['content'],
          'versions1.'.$cf['arch']['versions']['id_user'],
          'versions1.'.$cf['arch']['versions']['creation']
        ],
        'join' => [[
          'table' => $cf['tables']['versions'],
          'type' => 'left',
          'alias' => 'versions1',
          'on' => [
            'conditions' => [[
              'field' => $db->cfn($cf['arch']['notes']['id'], $cf['table']),
              'exp' => 'versions1.'.$cf['arch']['versions']['id_note']
            ]]
          ]
        ], [
          'table' => $cf['tables']['versions'],
          'type' => 'left',
          'alias' => 'versions2',
          'on' => [
            'conditions' => [[
              'field' => $db->cfn($cf['arch']['notes']['id'], $cf['table']),
              'exp' => 'versions2.'.$cf['arch']['versions']['id_note']
            ], [
              'field' => 'versions1.'.$cf['arch']['versions']['version'],
              'operator' => '<',
              'exp' => 'versions2.'.$cf['arch']['versions']['version']
            ]]
          ]
        ]],
        'where' => [
          'conditions' => $where
        ],
        'group_by' => $db->cfn($cf['arch']['notes']['id'], $cf['table']),
        'order' => [[
          'field' => 'versions1.'.$cf['arch']['versions']['version'],
          'dir' => 'DESC'
        ], [
          'field' => 'versions1.'.$cf['arch']['versions']['creation'],
          'dir' => 'DESC'
        ]],
        'limit' => $limit,
        'start' => $start
        ]
      );
      foreach ($notes as $note){
        if ($medias = $db->get_column_values(
          $cf['tables']['nmedias'], $cf['arch']['nmedias']['id_media'], [
          $cf['arch']['nmedias']['id_note'] => $note[$cf['arch']['versions']['id_note']],
          $cf['arch']['nmedias']['version'] => $note[$cf['arch']['versions']['version']],
          ]
        ) 
        ) {
          $note['medias'] = [];
          foreach ($medias as $m){
            if ($med = $db->rselect($cf['tables']['medias'], [], [$cf['arch']['medias']['id'] => $m])) {
              if (\bbn\str::is_json($med[$cf['arch']['medias']['content']])) {
                $med[$cf['arch']['medias']['content']] = json_decode($med[$cf['arch']['medias']['content']]);
              }
              $version['medias'][] = $med;
            }
          }
        }
        $res[] = $note;
      }
      \bbn\x::sort_by($res, $cf['arch']['versions']['creation'], 'DESC');
      return $res;
    }
    return false;
  }

  public function get_versions(string $id): ?array
  {
    if (\bbn\str::is_uid($id)) {
      $cf =& $this->class_cfg;
      return $this->db->rselect_all(
        [
        'table' => $cf['tables']['versions'],
        'fields' => [
          $cf['arch']['versions']['version'],
          $cf['arch']['versions']['id_user'],
          $cf['arch']['versions']['creation'],
        ],
        'where' => [
          'conditions' => [[
            'field' => $cf['arch']['versions']['id_note'],
            'value' => $id
          ]]
        ],
        'order' => [[
          'field' => $cf['arch']['versions']['version'],
          'dir' => 'DESC'
        ]]
        ]
      );
    }
    return null;
  }

  public function count_by_type($type = null, $id_user = false)
  {
    $db =& $this->db;
    $cf =& $this->class_cfg;
    if (!\bbn\str::is_uid($type)) {
      $type = $type = self::get_option_id(is_null($type) ? 'personal' : $type, 'types');
    }
    if (\bbn\str::is_uid($type)) {
      $where = [[
        'field' => $cf['arch']['notes']['active'],
        'value' => 1
      ], [
        'field' => $cf['arch']['notes']['id_type'],
        'value' => $type
      ]];
      if (!empty($id_user) && \bbn\str::is_uid($id_user)) {
        $where[] = [
          'field' => $cf['arch']['notes']['creator'],
          'value' => $id_user
        ];
      }
      return $db->select_one(
        [
        'table' => $cf['table'],
        'fields' => ['COUNT(DISTINCT '.$cf['arch']['notes']['id'].')'],
        'where' => [
          'conditions' => $where
        ]
        ]
      );
    }
    return false;
  }

  public function add_media($id_note, string $name, array $content = null, string $title = '', string $type='file', bool $private = false): ?string
  {
    $cf =& $this->class_cfg;
    $media = $this->get_media_instance();
    // Case where we give also the version (i.e. not the latest)
    if (\is_array($id_note) && (count($id_note) === 2)) {
      $version = $id_note[1];
      $id_note = $id_note[0];
    }
    else{
      $version = $this->latest($id_note) ?: 1;
    }
    
    if ($this->exists($id_note) 
        && ($id_media = $media->insert($name, $content, $title, $type, $private)) 
        && $this->add_media_to_note($id_media, $id_note, $version)
    ) {
      return $id_media;
    }
  
    return null;
  }

  public function add_media_to_note(string $id_media, string $id_note, int $version):? int
  {
    if ($usr = bbn\user::get_instance()) {
      $cf =& $this->class_cfg;
      return $this->db->insert(
        $cf['tables']['nmedias'], [
        $cf['arch']['nmedias']['id_note'] => $id_note,
        $cf['arch']['nmedias']['version'] => $version,
        $cf['arch']['nmedias']['id_media'] => $id_media,
        $cf['arch']['nmedias']['id_user'] => $usr->get_id(),
        $cf['arch']['nmedias']['creation'] => date('Y-m-d H:i:s')
        ]
      );
    }
    return null;
  }

  public function remove_media(string $id_media, string $id_note, $version = false)
  {
    $cf =& $this->class_cfg;
    if ($this->db->select_one($cf['tables']['medias'], $cf['arch']['medias']['id'], [$cf['arch']['medias']['id'] => $id_media])
        && $this->exists($id_note)
    ) {
      $filter = [
        $cf['arch']['nmedias']['id_note'] => $id_note,
        $cf['arch']['nmedias']['version'] => $version ?: $this->latest($id_note),
        $cf['arch']['nmedias']['id_media'] => $id_media
      ];
      if ($version === true) {
        unset($filter[$cf['arch']['nmedias']['version']]);
      }
      return $this->db->delete($cf['tables']['nmedias'], $filter);
    }
    return null;
  }

  public function media2version(string $id_media, string $id_note, $version = false)
  {
    $cf =& $this->class_cfg;
    return !empty($id_media) &&
      $this->db->select_one($cf['tables']['medias'], $cf['arch']['medias']['id'], [$cf['arch']['medias']['id'] => $id_media]) &&
      $this->exists($id_note) &&
      $this->db->insert(
        $cf['tables']['nmedias'], [
        $cf['arch']['nmedias']['id_note'] => $id_note,
        $cf['arch']['nmedias']['version'] => $version ?: $this->latest($id_note),
        $cf['arch']['nmedias']['id_media'] => $id_media,
        $cf['arch']['nmedias']['id_user'] => \bbn\user::get_instance()->get_id(),
        $cf['arch']['nmedias']['creation'] => date('Y-m-d H:i:s')
        ]
      );
  }

  public function get_medias(string $id_note, $version = false, $type = false): array
  {
    $ret = [];
    $media = $this->get_media_instance();
    $cf =& $this->class_cfg;
    if ($this->exists($id_note)) {
      $filter = [
            $cf['arch']['nmedias']['id_note'] => $id_note,
            $cf['arch']['nmedias']['version'] => $version ?: $this->latest($id_note),
      ];
      if ($version === true) {
        unset($filter[$cf['arch']['nmedias']['version']]);
      }
      if ($medias = $this->db->get_column_values($cf['tables']['nmedias'], $cf['arch']['nmedias']['id_media'], $filter)) {
        foreach ($medias as $m){
          $ret[] = $media->get_media($m, true);
        }
      }
    }
      return $ret;
  }
  
  public function has_medias(string $id_note, $version = false, string $id_media = ''): ?bool
  {
    $cf =& $this->class_cfg;
    if ($this->exists($id_note)) {
      $where = [
      $cf['arch']['nmedias']['id_note'] => $id_note,
      $cf['arch']['nmedias']['version'] => $version ?: $this->latest($id_note)
      ];
      if (!empty($id_media) && \bbn\str::is_uid($id_media)) {
        $where[$cf['arch']['nmedias']['id_media']] = $id_media;
      }
      return !!$this->db->count($cf['tables']['nmedias'], $where);
    }
    return null;
  }

  public function browse($cfg)
  {
    if (isset($cfg['limit']) && ($user = bbn\user::get_instance())) {
      /** @var bbn\db $db */
      $db =& $this->db;
      $cf =& $this->class_cfg;
      $grid_cfg = [
        'table' => $cf['table'],
        'fields' => [
          $db->cfn($cf['arch']['notes']['id'], $cf['table']),
          $db->cfn($cf['arch']['notes']['id_parent'], $cf['table']),
          $db->cfn($cf['arch']['notes']['id_alias'], $cf['table']),
          $db->cfn($cf['arch']['notes']['id_type'], $cf['table']),
          $db->cfn($cf['arch']['notes']['private'], $cf['table']),
          $db->cfn($cf['arch']['notes']['locked'], $cf['table']),
          $db->cfn($cf['arch']['notes']['pinned'], $cf['table']),
          $db->cfn($cf['arch']['notes']['creator'], $cf['table']),
          $db->cfn($cf['arch']['notes']['active'], $cf['table']),
          'first_version.'.$cf['arch']['versions']['creation'],
          'last_version.'.$cf['arch']['versions']['title'],
          'last_version.'.$cf['arch']['versions']['content'],
          'last_version.'.$cf['arch']['versions']['id_user'],
          'last_edit' => 'last_version.'.$cf['arch']['versions']['creation']
        ],
        'join' => [[
          'table' => $cf['tables']['versions'],
          'alias' => 'versions',
          'on' => [
            'logic' => 'AND',
            'conditions' => [[
              'field' => 'versions.'.$cf['arch']['versions']['id_note'],
              'operator' => '=',
              'exp' => $db->cfn($cf['arch']['notes']['id'], $cf['table'])
            ]]
          ]
        ], [
          'table' => $cf['tables']['versions'],
          'alias' => 'last_version',
          'on' => [
            'logic' => 'AND',
            'conditions' => [[
              'field' => 'last_version.'.$cf['arch']['versions']['id_note'],
              'operator' => '=',
              'exp' => $db->cfn($cf['arch']['notes']['id'], $cf['table'])
            ]]
          ]
        ], [
          'table' => $cf['tables']['versions'],
          'alias' => 'test_version',
          'type' => 'left',
          'on' => [
            'logic' => 'AND',
            'conditions' => [[
              'field' => 'test_version.'.$cf['arch']['versions']['id_note'],
              'operator' => '=',
              'exp' => $db->cfn($cf['arch']['notes']['id'], $cf['table'])
            ], [
              'field' => 'last_version.'.$cf['arch']['versions']['version'],
              'operator' => '<',
              'exp' => 'test_version.'.$cf['arch']['versions']['version']
            ]]
          ]
        ], [
          'table' => $cf['tables']['versions'],
          'alias' => 'first_version',
          'on' => [
            'logic' => 'AND',
            'conditions' => [[
              'field' => 'first_version.'.$cf['arch']['versions']['id_note'],
              'operator' => '=',
              'exp' => $db->cfn($cf['arch']['notes']['id'], $cf['table'])
            ], [
              'field' => 'first_version.'.$cf['arch']['versions']['version'],
              'operator' => '=',
              'value' => 1
            ]]
          ]
        ]],
        'filters' => [[
          'field' => $db->cfn($cf['arch']['notes']['active'], $cf['table']),
          'operator' => '=',
          'value' => 1
        ], [
          'field' => 'test_version.'.$cf['arch']['versions']['version'],
          'operator' => 'isnull'
        ]],
        'group_by' => $db->cfn($cf['arch']['notes']['id'], $cf['table']),
        'order' => [[
          'field' => 'last_edit',
          'dir' => 'DESC'
        ]]
      ];
      if (!empty($cfg['fields'])) {
        $grid_cfg['fields'] = bbn\x::merge_arrays($grid_cfg['fields'], $cfg['fields']);
        unset($cfg['fields']);
      }
      if (!empty($cfg['join'])) {
        $grid_cfg['join'] = bbn\x::merge_arrays($grid_cfg['join'], $cfg['join']);
        unset($cfg['join']);
      }
      $grid = new grid($this->db, $cfg, $grid_cfg);
      return $grid->get_datatable();
    }
  }

  public function count()
  {
    if ($user = bbn\user::get_instance()) {
      $cf =& $this->class_cfg;
      $db =& $this->db;
      $sql = "
      SELECT COUNT(DISTINCT {$db->cfn($cf['arch']['notes']['id'], $cf['tables']['notes'], 1)})
      FROM {$db->tsn($cf['tables']['notes'], 1)}
        JOIN {$db->tsn($cf['tables']['versions'], 1)}
          ON {$db->cfn($cf['arch']['notes']['id'], $cf['tables']['notes'], 1)} = {$db->cfn($cf['arch']['versions']['id_note'], $cf['tables']['versions'], 1)}
      WHERE {$db->cfn($cf['arch']['notes']['creator'], $cf['tables']['notes'], 1)} = ?
      OR {$db->cfn($cf['arch']['versions']['id_user'], $cf['tables']['versions'], 1)} = ?";
      return $db->get_one($sql, $user->get_id(), $user->get_id());
    }
  }

  /**
   * @param string $id   The note's uid
   * @param bool   $keep Set it to true if you want change active property to 0 instead of delete the row from db
   * @return bool|int
   */
  public function remove(string $id, $keep = false)
  {
    if (\bbn\str::is_uid($id)) {
      $cf =& $this->class_cfg;
      if (empty($keep)) {
        if ($medias = $this->get_medias($id, true)) {
          foreach ($medias as $m) {
            $this->remove_media($m['id'], $id, true);
          }
        }
        $this->db->delete($cf['tables']['versions'], [$cf['arch']['versions']['id_note'] => $id]);
        return $this->db->delete($cf['table'], [$cf['arch']['notes']['id'] => $id]);
      }
      else {
        return $this->db->update($cf['table'], [$cf['arch']['notes']['active'] => 0], [$cf['arch']['notes']['id'] => $id]);
      }
    }
    return false;
  }

  public function copy(string $id, int $version = null, bool $private = null):? string
  {
    if ($note = $this->get_full($id, $version)) {
      if ($private === null) {
        $private = $note['private'];
      }
      $id_note = $this->insert($note['title'], $note['content'], $note['type'], $private);
      foreach ($note['medias'] as $m){
        $this->add_media_to_note($m['id'], $id, $note['version']);
      }
      return $id_note;
    }
  }

  /**
   * Selects from db all medias that have the property content not null and a correspondant existing file.
   *
   * @param integer $start
   * @param integer $limit
   * @return array
   */
  public function get_medias_notes(int $start = 0, $limit ): array
  {
    $res = [];
    $cf =& $this->class_cfg;
    $all = $this->db->rselect_all(
      [
      'table'=> $cf['tables']['medias'],
      'fields' => $cf['arch']['medias'],
      'where' => [
      'conditions'=> [[
        'field' => $cf['arch']['medias']['private'],
        'value' => 0
      ],[
        'field' => $cf['arch']['medias']['content'],
        'operator' => 'isnotnull'
      ]]
      ],
      'start'  => $start,
      'limit' => $limit
      ]
    );
    if (!empty($all)) {
      $root = \bbn\mvc::get_data_path('appui-note').'media/';
      foreach ($all as $i => $a) {
        if (bbn\str::is_json($a['content']) && ($media_obj = $this->get_media_instance())) {
          $content = json_decode($a['content'], true);
          $path = $root.$content['path'].'/';  
          $full_path =  $path.$a['id'].'/'.$a['name'];  
          if (file_exists($full_path)) {
            $all[$i]['notes'] = $this->get_media_notes($a['id']);
            //if the media is an image it takes the thumb 60, 60 for src
            if ($media_obj->is_image($full_path) && ($thumb = $media_obj->get_thumbs($full_path))) {
              $all[$i]['is_image'] = true;
            }
            $res[] = $all[$i];
            
          }
        }
      }
    }
    return $res;
  }

  /**
   * returns all the notes linked to the media
   *
   * @param string $id_media
   * @return void
   */
  public function get_media_notes(string $id_media)
  {
      $notes = [];
    $cms = new \bbn\appui\cms($this->db);
    $ids = $this->db->rselect_all(
      $this->class_cfg['tables']['nmedias'], [
        $this->class_cfg['arch']['nmedias']['id_note'],
        $this->class_cfg['arch']['nmedias']['version']
      ], 
      [
        $this->class_cfg['arch']['nmedias']['id_media'] => $id_media
      ]
    );   
    
    if (!empty($ids)) {
      foreach($ids as $i){
        $tmp = $this->get($i['id_note']);
        $tmp['is_published'] = $cms->is_published($i['id_note']);
        $notes[] = $tmp;
              //return $notes;
      }
    }
    return $notes;
  }
}
