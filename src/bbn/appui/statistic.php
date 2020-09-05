<?php

namespace bbn\appui;

use bbn;

class statistic extends bbn\models\cls\db
{
  use bbn\models\tts\optional;

  /**
   * @var string The default start date of the statistic
   */
  protected const ODATE = '2014-01-01';

  /**
   * @var array List of accepteed values for the opr option config
   */
  protected static $types = ['insert', 'update', 'delete', 'restore', 'count', 'sum', 'avg'];

  /**
   * @var string The type of operation on which the statistic is done among those in self::$oprs
   */
  protected string $type;

  /**
   * @var string The UID of the statistic's option
   */
  protected string $id_option;

  /**
   * @var appui\database The DB object used to retrieve columns IDs
   */
  protected bbn\appui\databases $dbo;

  /**
   * @var array The whole request config for $db
   */
  protected array $request;

  /**
   * @var bool If true the statistic will show the aggregated total for each day
   */
  protected bool $is_total = false;

  /**
   * @var array The configuration array as used in constructor
   */
  protected array $cfg;

  /**
   * @var ?array The database configuration array ending the construction
   */
  protected ?array $db_cfg = null;

  /**
   * @var array The history configuration of the table in $cfg
   */
  protected array $hcfg;

  /** 
   * @var array The configuration from the statistic's option
   */
  protected array $ocfg;

  /**
   * @var string The configuration's code from the statistic's option
   */
  protected string $code;

  /**
   * @var string The UID of the user inserting.
   */
  protected ?string $inserter = null;

  /**
   * @var string The UID of the user updating.
   */
  protected ?string $updater = null;

  /**
   * @var string The UID of the user deleting.
   */
  protected ?string $deleter = null;

  /**
   * @var string The UID.
   */
  private string $_id_field;

  /**
   * @var string The expression that will be used as placeholder for the timestamps
   */
  private static string $_placeholder = '___BBN_TST___';

  /**
   * Constructor.
   *
   * @param bbn\db $db   The database connection
   * @param string $code The code of the option
   * @param array  $cfg  The configuration
   */
  public function __construct(bbn\db $db, string $code, array $cfg)
  {
    // Parent constructors
    parent::__construct($db);
    self::optional_init();
    // Db ok
    if ($this->db->check()
        // History ok
        && history::is_init()
        // Id option corresponding to code in active statistics
        && ($this->id_option = self::get_option_id($code, 'active'))
        // Option retrieved
        && ($this->ocfg = self::get_option($this->id_option))
        // Right props in cfg
        && bbn\x::has_props($cfg, ['type', 'table'], true)
        // And right types
        && \bbn\x::is_string($cfg['type'], $cfg['table'])
        // Correcting case
        && ($cfg['type'] = strtolower($cfg['type']))
        // Type accepted
        && (\bbn\x::indexOf(self::$types, $cfg['type']) > -1)
        // History config retrieved
        && ($this->hcfg = history::get_table_cfg($cfg['table']))
    ) {
      if ((\bbn\x::indexOf(['sum', 'avg'], $cfg['type']) > -1) && !isset($cfg['field'])) {
        throw new \Error(_("The field parameter is mandatory for sum and avg types"));
      }
      $this->code = $code;
      $this->dbo = new \bbn\appui\databases($this->db);
      if (isset($cfg['field'])) {
        if (!($this->_id_field = $this->dbo->column_id($cfg['field'], $cfg['table']))) {
          throw new \Error(_("The field parameter must be a known field of the table"));
        }
      }
      if (($cfg['type'] === 'update') && empty($this->_id_field)) {
        throw new \Error(_("The field parameter is mandatory for statistics of type update"));
      }
      $this->type = $cfg['type'];
      $this->cfg = $cfg;
      if (!empty($cfg['inserter']) && bbn\str::is_uid($cfg['inserter'])) {
        $this->inserter = $cfg['inserter'];
      }
      if (!empty($cfg['updater']) && bbn\str::is_uid($cfg['updater'])) {
        $this->updater = $cfg['updater'];
      }
      if (!empty($cfg['deleter']) && bbn\str::is_uid($cfg['deleter'])) {
        $this->deleter = $cfg['deleter'];
      }
      $req = $this->_set_request_cfg();
      $this->db_cfg = $this->db->process_cfg($req);
    }
  }

  /**
   * Checks if the constructor is gone through.
   *
   * @return bool
   */
  public function check(): bool
  {
    return !!$this->db_cfg;
  }


  /**
   * Code getter.
   *
   * @return string|null
   */
  public function get_code(): ?string
  {
    if ($this->check()) {
      return $this->code;
    }
    return null;
  }

  /**
   * Run the stat
   *
   * @param [type] $start
   * @param [type] $end
   * @return void
   */
  public function run($start, $end = null)
  {
    if ($this->check() && !empty($this->db_cfg['values'])) {
      if (is_string($start)) {
        $start = strtotime($start.(strlen($start) === 10 ? ' 00:00:00' : ''));
      }
      if (!$start || !is_int($start)) {
        throw new Error(_('Impossible to read the given start date'));
      }
      if (!$this->is_total) {
        if (!$end || ($end <= $start)) {
          $end = mktime(23, 59, 59, date('n', $start), date('j', $start), date('Y', $start));
        }
        if (!$end || !is_int($end)) {
          throw new Error(_('Impossible to read the given end date'));
        }
      }
      $vals = [];
      foreach ($this->db_cfg['values'] as $v) {
        if (!$this->is_total && ($v === self::$_placeholder.'2')) {
          $vals[] = $end;
        }
        else {
          $vals[] = $v === self::$_placeholder ? $start : $v;
        }
      }
      $cfg = $this->db_cfg;
      $cfg['values'] = $vals;
      return $this->db->select_one($cfg);
    }
  }

  /**
   * Update a statistic in the table bbn_statistics from the start of time
   *
   * @param string      $variant 
   * @param string|null $start   Start of time
   * @return int
   */
  public function update(string $variant = null, string $start = null): ?int
  {
    if ($this->check()) {
      if (!$variant) {
        $variant = 'default';
      }
      if (!($real_start = $this->db->select_one('bbn_statistics', 'MAX(day)', ['id_option' => $this->id_option, 'code' => $variant]))) {
        if ($start) {
          $real_start = $start;
        }
        elseif (!empty($this->ocfg['start'])) {
          $real_start = $this->ocfg['start'];
        }
        else {
          $real_start = self::ODATE;
        }
      }
      if (\bbn\str::is_date_sql($real_start)) {
        $num_days = 0;
        $num = $this->db->count(
          'bbn_statistics',
          [
            'id_option' => $this->id_option,
            'code' => $variant
          ]
        );
        $today = date('Ymd');
        $last_res = null;
        $last_date = $real_start;
        $time = mktime(
          12,
          0,
          0,
          (int)substr($real_start, 5, 2),
          (int)substr($real_start, 8, 2),
          (int)substr($real_start, 0, 4)
        );
        $test = date('Ymd', $time);
        while ($test <= $today) {
          $res = $this->run($real_start);
          if ($num_days) {
            \bbn\x::hdump($res, $this->db->get_last_values());
          }
          else {
            \bbn\x::hdump($res, $this->db->last(), $this->db->get_last_values());
          }
          $num_days++;
          if (!$res) {
            $res = 0;
          }
          if (($res !== $last_res) || !$num) {
            if ($this->db->count(
              'bbn_statistics', [
              'id_option' => $this->ocfg['id'],
              'code' => $variant,
              'day' => $real_start
              ]
            )
            ) {
              $this->db->update(
                'bbn_statistics', [
                'res' => $res
                ], [
                'id_option' => $this->ocfg['id'],
                'code' => $variant,
                'day' => $real_start
                ]
              );
            }
            else {
              $this->db->insert(
                'bbn_statistics', [
                'id_option' => $this->ocfg['id'],
                'code' => $variant,
                'day' => $real_start,
                'res' => $res
                ]
              );
            }
            $last_res = $res;
            $num++;
          }
          else {
            $this->db->update(
              'bbn_statistics', [
              'day' => $real_start
              ], [
              'id_option' => $this->ocfg['id'],
              'code' => $variant,
              'day' => $last_date
              ]
            );
          }
          $last_date = $real_start;
          $time += 24*3600;
          $real_start = date('Y-m-d', $time);
          $test = date('Ymd', $time);
        }
        return $num_days;
      }
    }
    return null;
  }

  public function serie(int $values = 30, string $start = null, string $end = null): ?array
  {
    if ($this->ocfg) {
      if (!$end) {
        $end = date('Y-m-d');
      }
      if (!$start) {
        $start = date('Y-m-d', strtotime($end.' 12:00:00') - ($values*24*3600));
      }
      if (bbn\str::is_date_sql($start, $end)) {
        $res = [
          'labels' => [],
          'series' => []
        ];
        // All the values between start and end
        $all = $this->db->rselect_all(
          'bbn_statistics', ['day', 'res'], [
          [
            'field' => 'id_option',
            'value' => $this->id_option
          ], [
            'field' => 'day',
            'operator' => '>=',
            'value' => $start
          ], [
            'field' => 'day',
            'operator' => '<=',
            'value' => $end
          ]
          ], [
          'day' => 'ASC'
          ]
        );
        // As the row appears with its last date for value we pick the nextr row if the last row is not the last day
        $last = count($all) - 1;
        if (($all[$last]['day'] !== $end) && ($tmp = $this->db->rselect(
          'bbn_statistics', ['day', 'res'], [
          [
            'field' => 'id_option',
            'value' => $this->id_option
          ], [
            'field' => 'day',
            'operator' => '>',
            'value' => $end
          ]
          ], [
          'day' => 'ASC'
          ]
        ))
        ) {
          $all[] = $tmp;
          $last++;
        }
        if (count($all)) {
          $dcurrent = new \DateTime($start);
          $dend = new \DateTime($end);
          $num_days = (int)$dend->diff($dcurrent)->format('%a');
          $diff = $num_days;
          $interval = (int)floor(($num_days+1) / $values);
          $num = 0;
          $idx = 0;
          $didx = 0;
          $pstart = false;
          while ($diff >= 0) {
            $current = $dcurrent->format('Y-m-d');
            if (!$pstart) {
              $pstart = $current;
            }
            if ($num === $interval) {
              $num = 0;
            }
            if (!$num) {
              if (!empty($this->ocfg['total']) || ($interval === 1)) {
                $res['labels'][$didx] = $current;
              }
              else {
                $res['labels'][$didx] = bbn\date::format($pstart, 's').' - '.bbn\date::format($current, 's');
              }
              if (isset($res['series'][$didx]) && empty($this->ocfg['total'])) {
                $res['series'][$didx] = $res['series'][$didx] + $all[$idx]['res'];
              }
              else {
                $res['series'][$didx] = $all[$idx]['res'];
              }
              $pstart = false;
              $didx++;
            }
            elseif (empty($this->ocfg['total'])) {
              $res['labels'][$didx] = $current;
              $res['series'][$didx] += (int)$all[$idx]['res'];
            }
            if (!$diff) {
              break;
            }
            if ($current === $all[$idx]['day']) {
              $idx++;
            }
            $dcurrent = $dcurrent->add(date_interval_create_from_date_string('1 days'));
            $diff = (int)$dend->diff($dcurrent)->format('%a');
            $num++;
          }
        }
        return $res;
      }
    }
    return null;
  }

  public function serie_values(int $values = 30, string $start = null, string $end = null): ?array
  {
    if ($res = $this->serie($values, $start, $end)) {
      $res = array_map(
        function ($a) {

        }, $res
      );
    }
    return $res;
  }

  public function serie_by_period(int $values = 30, string $unit = 'm', string $end = null, string $pstart = null): ?array
  {
    if ($this->ocfg && $values) {
      if (!$end) {
        $end = date('Y-m-d');
      }
      if (bbn\str::is_date_sql($end)) {
        switch (strtolower($unit)) {
          case 'y':
            $funit = 'years';
            $tmp = date('Y-m-d', mktime(23, 59, 59, 12, 31, substr($end, 0, 4)));
            if ($end !== $tmp) {
              $end = date('Y-m-d', mktime(23, 59, 59, 12, 31, (int)substr($end, 0, 4) - 1));
            }
            break;
          case 't':
            $funit = 'months';
            $values *= 3;
          case 'm':
            $funit = 'months';
            $month = (int)substr($end, 5, 2);
            $remain = $month % 3;
            if ($remain) {
              $remain = 3 - $remain;
            }
            $tmp = date('Y-m-d', mktime(23, 59, 59, $month + 1, 0, substr($end, 0, 4)));
            if (($end !== $tmp) || $remain) {
              $end = date('Y-m-d', mktime(23, 59, 59, $month - $remain + 1, 0, (int)substr($end, 0, 4)));
            }
            break;
          case 'w':
            $funit = 'weeks';
            
            break;
          case 'd':
            $funit = 'days';
            break;
        }
        if (isset($funit)) {
          $dend = new \DateTime($end);
          $dstart = $dend->sub(date_interval_create_from_date_string("$values $funit"));
          $start = $dstart->format('Y-m-d');
          $res = [
            'labels' => [],
            'series' => []
          ];
          if ($all = $this->db->rselect_all(
            'bbn_statistics', ['day', 'res'], [
            [
              'field' => 'id_option',
              'value' => $this->id_option
            ], [
              'field' => 'day',
              'operator' => '<=',
              'value' => $end
            ], [
              'field' => 'day',
              'operator' => '>=',
              'value' => $start
            ]
            ], [
            'day' => 'ASC'
            ]
          )
          ) {
            $last = count($all) - 1;
            if (($all[$last]['day'] !== $end) && ($tmp = $this->db->rselect(
              'bbn_statistics', ['day', 'res'], [
              [
                'field' => 'id_option',
                'value' => $this->id_option
              ], [
                'field' => 'day',
                'operator' => '>',
                'value' => $end
              ]
              ], [
              'day' => 'ASC'
              ]
            ))
            ) {
              $all[] = $tmp;
              $last++;
            }
            $dcurrent = new \DateTime($start);
            $num_days = (int)$dend->diff($dcurrent)->format('%a');
            $diff = $num_days;
            $interval = (int)floor($num_days / $values);
            $num = 0;
            $idx = 0;
            $didx = 0;
            while ($diff >= 0) {
              $current = $dcurrent->format('Y-m-d');
              if ($num === $interval) {
                $num = 0;
              }
              if (!$num) {
                $res['labels'][$didx] = $current;
                $res['series'][$didx] = $all[$idx]['res'];
                $didx++;
              }
              elseif (empty($this->ocfg['total'])) {
                $res['labels'][$didx] = $current;
                $res['series'][$didx] += (int)$all[$idx]['res'];
              }
              if (!$diff) {
                break;
              }
              if ($current === $all[$idx]['day']) {
                $idx++;
              }
              $dcurrent = $dcurrent->add(date_interval_create_from_date_string('1 days'));
              $diff = (int)$dend->diff($dcurrent)->format('%a');
              $num++;
            }
          }
          return $res;
        }
      }
    }
    return null;
  }

  private function _set_request_cfg(): ?array
  {
    if ($this->type) {
      $cfg = [
        'tables' => ['bbn_history'],
        'join' => [
          [
            'table' => $this->cfg['table'],
            'on' => [
              [
                'field' => $this->cfg['table'].'.'.$this->hcfg['primary'],
                'exp' => 'bbn_history.uid'
              ]
            ]
          ]
        ],
        'where' => [
          'logic' => 'AND',
          'conditions' => [[
            'field' => 'bbn_history.tst',
            'operator' => '<=',
            'value' => self::$_placeholder.($this->is_total ? '' : '2')
          ]]
        ]
      ];
      if ($this->inserter) {
        if ($this->type === 'insert') {
          $cfg['where']['conditions'][] = [
            'field' => 'bbn_history.usr',
            'value' => $this->inserter
          ];
        }
        else {
          $alias = \bbn\str::genpwd(12);
          $cfg['join'][] = [
            'table' => 'bbn_history',
            'alias' => $alias,
            'on' => [
              [
                'field' => 'bbn_history.uid',
                'exp' => $alias.'.uid'
              ], [
                'field' => 'bbn_history.tst',
                'operator' => '>',
                'exp' => $alias.'.tst'
              ], [
                'field' => $alias.'.opr',
                'value' => 'INSERT'
              ]
            ]
          ];
          $cfg['where']['conditions'][] = [
            'field' => $alias.'.usr',
            'value' => $this->inserter
          ];
        }
      }
      switch ($this->type) {
        case 'insert':
          $this->_set_insert_cfg($cfg);
          break;
        case 'update':
          $this->_set_update_cfg($cfg);
          break;
        case 'delete':
          $this->_set_delete_cfg($cfg);
          break;
        case 'restore':
          $this->_set_restore_cfg($cfg);
          break;
        case 'count':
          $this->_set_count_cfg($cfg);
          break;
        case 'sum':
        case 'avg':
          $this->_set_fn_cfg($this->type, $cfg);
          break;
      }
      if (bbn\x::has_prop($this->cfg, 'filter', true)
          && ($conditions = $this->db->treat_conditions($this->cfg['filter']))
          && !empty($conditions['where']['conditions'])
          && ($tmp2 = $this->_set_filter($conditions['where']))
      ) {
        foreach ($tmp2['join'] as $j) {
          $cfg['join'][] = $j;
        }
        if (!empty($tmp2['filter'])) {
          $cfg['where']['conditions'][] = $tmp2['filter'];
        }
      }
      $this->request = $cfg;
      return $cfg;
    }
    return null;
  }

  private function _set_count_cfg(array &$cfg): array
  {
    $alias = \bbn\str::genpwd(12);
    $cfg['fields'] = ['COUNT(DISTINCT bbn_history.uid)'];
    $cfg['join'][] = [
      'table' => 'bbn_history',
      'alias' => $alias,
      'type' => 'left',
      'on' => [
        // Same UID
        [
          'field' => $alias.'.uid',
          'operator' => '=',
          'exp' => 'bbn_history.uid'
        ],
        // Delete action
        [
          'field' => $alias.'.opr',
          'operator' => 'LIKE',
          'value' => 'DELETE'
        ],
        // Performed after the INSERT
        [
          'field' => $alias.'.tst',
          'operator' => '>',
          'exp' => 'bbn_history.tst'
        ],
        // Performed before the end of the period
        [
          'field' => $alias.'.tst',
          'operator' => '<=',
          'value' => self::$_placeholder.($this->is_total ? '' : '2')
        ]
      ]
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.opr',
      'operator' => 'LIKE',
      'value' => 'INSERT'
    ];
    $cfg['where']['conditions'][] = [
      'field' => $alias.'.uid',
      'operator' => 'isnull'
    ];
    return $cfg;
  }

  private function _set_fn_cfg($fn, array &$cfg): array
  {
    $alias = \bbn\str::genpwd(12);
    $alias1 = bbn\str::genpwd(12);
    $alias2 = bbn\str::genpwd(12);
    $field = $this->db->cfn($this->cfg['field'], $this->cfg['table'], true);
    $fn = strtoupper($fn);
    $cfg['fields'] = ["$fn(IFNULL($alias1.val, $field))"];
    $cfg['join'][] = [
      'table' => 'bbn_history',
      'alias' => $alias,
      'type' => 'left',
      'on' => [
        // Same UID
        [
          'field' => $alias.'.uid',
          'operator' => '=',
          'exp' => 'bbn_history.uid'
        ],
        // Delete action
        [
          'field' => $alias.'.opr',
          'operator' => 'LIKE',
          'value' => 'DELETE'
        ],
        // Performed after the INSERT
        [
          'field' => $alias.'.tst',
          'operator' => '>',
          'exp' => 'bbn_history.tst'
        ],
        // Performed before the end of the period
        [
          'field' => $alias.'.tst',
          'operator' => '<=',
          'value' => self::$_placeholder.($this->is_total ? '' : '2')
        ]
      ]
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.opr',
      'operator' => 'LIKE',
      'value' => 'INSERT'
    ];
    $cfg['where']['conditions'][] = [
      'field' => $alias.'.uid',
      'operator' => 'isnull'
    ];
    $join1 = [
      'table' => 'bbn_history',
      'alias' => $alias1,
      'type' => 'LEFT',
      'on' => [
        [
          'field' => $alias1.'.uid',
          'operator' => '=',
          'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
        ], [
          'field' => $alias1.'.opr',
          'operator' => 'LIKE',
          'value' => 'UPDATE'
        ], [
          'field' => $alias1.'.col',
          'operator' => '=',
          'value' => $this->_id_field
        ], [
          'field' => $alias1.'.tst',
          'operator' => '>',
          'value' => self::$_placeholder.'2'
        ]
      ]
    ];
    $join2 = [
      'table' => 'bbn_history',
      'alias' => $alias2,
      'type' => 'LEFT',
      'on' => [
        [
          'field' => $alias2.'.uid',
          'operator' => '=',
          'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
        ], [
          'field' => $alias2.'.col',
          'operator' => '=',
          'exp' => $alias1.'.col'
        ], [
          'field' => $alias2.'.opr',
          'operator' => 'LIKE',
          'exp' => $alias1.'.opr'
        ], [
          'field' => $alias2.'.tst',
          'operator' => '<',
          'exp' => $alias1.'.tst'
        ]
      ]
    ];
    $cfg['join'][] = $join1;
    $cfg['join'][] = $join2;
    $cfg['where']['conditions'][] = [
      'field' => $alias2.'.uid',
      'operator' => 'isnull'
    ];
    return $cfg;
  }

  private function _set_insert_cfg(array &$cfg): array
  {
    $cfg['fields'] = ['COUNT(DISTINCT bbn_history.uid)'];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.opr',
      'operator' => 'LIKE',
      'value' => 'INSERT'
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.tst',
      'operator' => '>=',
      'value' => self::$_placeholder
    ];
    return $cfg;
  }

  private function _set_update_cfg(array &$cfg)
  {
    if (empty($this->cfg['field'])) {
      throw new \Error(_("The parameters field and value must be given for update statistics"));
    }
    if (!$this->_id_field) {
      throw new \Error(_("The parameters field must be a valid column from the given table"));
    }
    $cfg['fields'] = ['COUNT(DISTINCT bbn_history.uid)'];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.opr',
      'value' => 'UPDATE'
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.col',
      'value' => $this->_id_field
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.tst',
      'operator' => '>=',
      'value' => self::$_placeholder
    ];
    if (array_key_exists('value', $this->cfg)) {
      $alias1 = bbn\str::genpwd(12);
      $alias2 = bbn\str::genpwd(12);
      $join1 = [
        'table' => 'bbn_history',
        'alias' => $alias1,
        'type' => 'LEFT',
        'on' => [
          [
            'field' => $alias1.'.uid',
            'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
          ], [
            'field' => $alias1.'.opr',
            'exp' => 'bbn_history.opr'
          ], [
            'field' => $alias1.'.col',
            'value' => 'bbn_history.col'
          ], [
            'field' => $alias1.'.tst',
            'operator' => '>',
            'value' => 'bbn_history.tst'
          ]
        ]
      ];
      $join2 = [
        'table' => 'bbn_history',
        'alias' => $alias2,
        'type' => 'LEFT',
        'on' => [
          [
            'field' => $alias2.'.uid',
            'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
          ], [
            'field' => $alias2.'.col',
            'exp' => $alias1.'.col'
          ], [
            'field' => $alias2.'.opr',
            'exp' => $alias1.'.opr'
          ], [
            'field' => $alias2.'.tst',
            'operator' => '<',
            'exp' => $alias1.'.tst'
          ]
        ]
      ];
      $cd = [
        'logic' => 'OR',
        'conditions' => [
          [
            'logic' => 'AND',
            'conditions' => [
              [
                'field' => $this->db->cfn($this->cfg['field'], $this->cfg['table']),
                'operator' => is_null($this->cfg['value']) ? 'isnull' : ($this->cfg['operator'] ?? '='),
                'value' => $this->cfg['value']
              ], [
                'field' => $alias1.'.uid',
                'operator' => 'isnull'
              ]
            ]
          ], [
            'field' => 'IFNULL('.$alias1.'.ref, '.$alias1.'.val)',
            'operator' => is_null($this->cfg['value']) ? 'isnull' : ($this->cfg['operator'] ?? '='),
            'value' => $this->cfg['value']
          ]
        ]
      ];
      $cfg['join'][] = $join1;
      $cfg['join'][] = $join2;
      $cfg['where']['conditions'][] = [
        'logic' => 'AND',
        'conditions' => [
          $cd,
          [
            'field' => $alias2.'.uid',
            'operator' => 'isnull'
          ]
        ]
      ];
    }
    return $cfg;
  }

  private function _set_delete_cfg(array &$cfg)
  {
    $cfg['fields'] = ['COUNT(DISTINCT bbn_history.uid)'];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.opr',
      'value' => 'DELETE'
    ];
    $cfg['where']['conditions'][] = [
      'field' => 'bbn_history.tst',
      'operator' => '>=',
      'value' => self::$_placeholder
    ];
    return $cfg;
  }

  private function _set_restore_cfg(array &$cfg)
  {
  }

  /**
   * Combines the history filters with config filters recursively.
   *
   * @todo  Useless second argument, what for?
   * 
   * @param array $conditions A conditions array in a conditions prop.
   * @param int   $tst        A Timestamp.
   * @return array|null
   */
  private function _set_filter(array $conditions, int $tst = 0): ?array
  {
    if (!empty($conditions['conditions'])) {
      $flt = [
        'logic' => $conditions['logic'],
        'conditions' => []
      ];
      $join = [];
      foreach ($conditions['conditions'] as $c) {
        if (!empty($c['conditions'])) {
          if ($tmp = $this->_set_filter($c)) {
            if (!empty($tmp['join'])) {
              foreach ($tmp['join'] as $j) {
                $join[] = $j;
              }
            }
            if (bbn\x::has_deep_prop($tmp, ['filter', 'conditions'], true)) {
              $flt['conditions'][] = $tmp['filter'];
            }
          }
        }
        // Adding for each filter 2 alternative conditions:
        // - The value matches and has not been changed since then
        // - The value used to match but has been changed
        elseif ($id_col = $this->dbo->column_id($c['field'], $this->cfg['table'])) {
          $alias1 = bbn\str::genpwd(12);
          $alias2 = bbn\str::genpwd(12);
          $join1 = [
            'table' => 'bbn_history',
            'alias' => $alias1,
            'type' => 'LEFT',
            'on' => [
              [
                'field' => $alias1.'.uid',
                'operator' => '=',
                'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
              ], [
                'field' => $alias1.'.opr',
                'operator' => 'LIKE',
                'value' => 'UPDATE'
              ], [
                'field' => $alias1.'.col',
                'operator' => '=',
                'value' => $id_col
              ], [
                'field' => $alias1.'.tst',
                'operator' => '>',
                'value' => self::$_placeholder
              ]
            ]
          ];
          $join2 = [
            'table' => 'bbn_history',
            'alias' => $alias2,
            'type' => 'LEFT',
            'on' => [
              [
                'field' => $alias2.'.uid',
                'operator' => '=',
                'exp' => $this->cfg['table'].'.'.$this->hcfg['primary']
              ], [
                'field' => $alias2.'.col',
                'operator' => '=',
                'exp' => $alias1.'.col'
              ], [
                'field' => $alias2.'.opr',
                'operator' => 'LIKE',
                'exp' => $alias1.'.opr'
              ], [
                'field' => $alias2.'.tst',
                'operator' => '<',
                'exp' => $alias1.'.tst'
              ]
            ]
          ];
          $cd = [
            'logic' => 'OR',
            'conditions' => [
              [
                'logic' => 'AND',
                'conditions' => [
                  [
                    'field' => $this->db->cfn($c['field'], $this->cfg['table']),
                    'operator' => $c['operator']
                  ], [
                    'field' => $alias1.'.uid',
                    'operator' => 'isnull'
                  ]
                ]
              ], [
                'field' => 'IFNULL('.$alias1.'.ref, '.$alias1.'.val)',
                'operator' => $c['operator']
              ]
            ]
          ];
          if (!empty($c['exp'])) {
            $cd['conditions'][0]['conditions'][0]['exp'] = $c['exp'];
            $cd['conditions'][1]['exp'] = $c['exp'];
          }
          elseif (\bbn\x::has_prop($c, 'value')) {
            $cd['conditions'][0]['conditions'][0]['value'] = $c['value'];
            $cd['conditions'][1]['value'] = $c['value'];
          }
          $join[] = $join1;
          $join[] = $join2;
          $tmp = [
            'logic' => 'AND',
            'conditions' => [
              $cd,
              [
                'field' => $alias2.'.uid',
                'operator' => 'isnull'
              ]
            ]
          ];
          $flt['conditions'][] = $tmp;
        }

      }
      return ['join' => $join, 'filter' => $flt];
    }
    return null;
  }
}
