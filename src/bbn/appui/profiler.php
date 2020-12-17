<?php
namespace bbn\appui;

/**
 * Undocumented class
 */
use bbn;
use bbn\x;

/**
 * Class chat
 * @package bbn\appui
 */
class profiler extends bbn\models\cls\db
{
  use bbn\models\tts\dbconfig;

  protected $is_started = false;

  protected $chrono;

  protected static $delay = 2;

  /** @var array */
  protected static $default_class_cfg = [
    'table' => 'bbn_profiler',
    'tables' => [
      'bbn_profiler' => 'bbn_profiler'
    ],
    'arch' => [
      'bbn_profiler' => [
        'id' => 'id',
        'id_user' => 'id_user',
        'url' => 'url',
        'time' => 'time',
        'length' => 'length',
        'content' => 'content'
      ]
    ]
  ];

  public static function set_delay(int $delay)
  {
    self::$delay = $delay;
  }

  /**
   * Constructor.
   *
   * @param bbn\db $db The db connection
   */
  public function __construct(bbn\db $db)
  {
    parent::__construct($db);
    $this->chrono = new \bbn\util\timer();
    self::_init_class_cfg(self::$default_class_cfg);
  }


  /**
   * Starting the profiling.
   *
   * @return bool
   */
  public function start(bool $force = false): bool
  {
    if ($this->check() && !$this->is_started) {
      $c = &$this->class_cfg['arch']['bbn_profiler'];
      $last = $this->db->select_one(
        $this->class_table,
        "MAX(`$c[time]`)"
      );
      if ($force || !$last || (time() - strtotime($last) > self::$delay)) {
        $this->chrono->start();
        if (function_exists('tideways_xhprof_enable')) {
          tideways_xhprof_enable(
            TIDEWAYS_XHPROF_FLAGS_MEMORY | 
            TIDEWAYS_XHPROF_FLAGS_CPU |
            TIDEWAYS_XHPROF_FLAGS_MEMORY_ALLOC_AS_MU |
            TIDEWAYS_XHPROF_FLAGS_MEMORY_ALLOC |
            TIDEWAYS_XHPROF_FLAGS_MEMORY_MU
          );
          $this->is_started = true;
          return true;
        }
        elseif (function_exists('xhprof_enable')) {
          xhprof_enable(
            XHPROF_FLAGS_MEMORY | 
            XHPROF_FLAGS_CPU |
            XHPROF_FLAGS_MEMORY_ALLOC_AS_MU |
            XHPROF_FLAGS_MEMORY_ALLOC |
            XHPROF_FLAGS_MEMORY_MU
          );
          $this->is_started = true;
          return true;
        }
      }
    }

    return false;
  }


  /**
   * Finishing the profiling and inserting profile in DB.
   *
   * @param bbn\mvc $mvc
   *
   * @return bool
   */
  public function finish(bbn\mvc $mvc): bool
  {
    if ($this->$is_started && $this->check()) {
      $this->is_started = false;
      $data = tideways_xhprof_disable();
      $c = &$this->class_cfg['arch']['bbn_profiler'];
      return !!$this->db->insert(
        $this->class_table,
        [
          $c['id_user'] => $mvc->inc->user->get_id(),
          $c['url'] => $mvc->get_request(),
          $c['time'] => date('Y-m-d-H:i:s'),
          $c['length'] => $this->chrono->stop(),
          $c['content'] => serialize($data)
        ]
      );
    }

    return false;
  }


  public function get(string $id): ?array
  {
    if ($this->check()) {
      $c   = &$this->class_cfg['arch']['bbn_profiler'];
      $row = $this->db->rselect($this->class_table, [], [$c['id'] => $id]);
      if ($row) {
        $content       = unserialize($row['content']);
        unset($row['content']);

        $res         = $row;
        $res['data'] = [];
        foreach ($content as $fn => $data) {
          [
            $data['parent'],
            $data['child']
          ]               = x::split($fn, '==>');
          $data['mem_na'] = $data['mem.na'];
          $data['mem_nf'] = $data['mem.nf'];
          $data['mem_aa'] = $data['mem.aa'];
          unset($data['mem.na'], $data['mem.nf'], $data['mem.aa']);
          $res['data'][] = $data;
        }

        return $res;
      }
    }

    return null;
  }


  public function get_urls(): ?array
  {
    if ($this->check()) {
      $c = &$this->class_cfg['arch']['bbn_profiler'];
      return $this->db->get_column_values($this->class_table, $c['url']);
    }

    return null;
  }


  public function get_list(array $data): ?array
  {
    if ($this->check()) {
      $c = $this->class_cfg['arch']['bbn_profiler'];
      unset($c['content']);
      $data['limit'] = isset($data['limit']) && is_int($data['limit']) ? $data['limit'] : 50;
      $data['start'] = isset($data['start']) && is_int($data['start']) ? $data['start'] : 0;
      $grid = new \bbn\appui\grid(
        $this->db,
        $data,
        [
          'table' => $this->class_table,
          'fields' => $c,
          'order' => [[
            'field' => 'time',
            'dir' => 'DESC'
          ]]
        ]
      );

      if ($grid->check()) {
        return $grid->get_datatable(true);
      }
    }
    return null;
  }


}
