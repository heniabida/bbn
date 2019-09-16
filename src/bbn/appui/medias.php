<?php
namespace bbn\appui;
use bbn;

if ( !\defined('BBN_DATA_PATH') ){
  die("The constant BBN_DATA_PATH must be defined in order to use medias");
}

class medias extends bbn\models\cls\db
{

  use
    bbn\models\tts\references,
    bbn\models\tts\dbconfig;

  protected static
    /** @var array */
    $_defaults = [
      'table' => 'bbn_medias',
      'tables' => [
        'medias' => 'bbn_medias'
      ],
      'arch' => [
        'medias' => [
          'id' => 'id',
          'id_user' => 'id_user',
					'type' => 'type',
          'name' => 'name',
          'title' => 'title',
					'content' => 'content',
          'private' => 'private'
        ]
      ]
    ];

  private
    $opt,
    $usr,
    $opt_id;

  public function __construct(bbn\db $db){
    parent::__construct($db);
    $this->_init_class_cfg();
    $this->opt = bbn\appui\options::get_instance();
    $this->usr = bbn\user::get_instance();
    $this->opt_id = $this->opt->from_root_code('media', 'notes', 'appui');
  }

  public function insert($name, $content = null, $title = '', $type='file', $private = false){
    $cf =& $this->class_cfg;
    if (
      !empty($name) &&
      ($id_type = $this->opt->from_code($type, $this->opt_id))
    ){
      $content = null;
      $ok = false;
      switch ( $type ){
        case 'link':
          if ( empty($title) ){
            $title = basename($name);
          }
          $ok = 1;
        break;
        default:
          $fs = new bbn\file\system();
          if ( $fs->is_file($name) ){
            $root = $fs->create_path($private && $this->usr->check() ? 
              bbn\mvc::get_user_data_path($this->usr->get_id(), 'appui-notes').'media/' : 
              bbn\mvc::get_data_path('appui-notes').'media/');
            if ( $root ){
              $path = bbn\x::make_storage_path($root, '', 0, $fs);
              $dpath = substr($path, strlen($root) + 1);
              $content = [
                'path' => $dpath,
                'size' => $fs->filesize($name)
              ];
              $file = basename($name);
              if ( empty($title) ){
                $title = basename($file);
              }
              $ok = 1;
            }
          }
          break;
      }
      if ( $ok ){
        $this->db->insert($cf['table'], [
          $cf['arch']['medias']['id_user'] => $this->usr->get_id(),
          $cf['arch']['medias']['type'] => $id_type,
          $cf['arch']['medias']['title'] => $title,
          $cf['arch']['medias']['name'] => $file ?? null,
          $cf['arch']['medias']['content'] => $content ? json_encode($content) : null,
          $cf['arch']['medias']['private'] => $private ? 1 : 0
        ]);
        $id = $this->db->last_id();
        if ( isset($file) && $fs->create_path($path.$id) ){
          $fs->move(
            $name,
            $path.$id
          );
        }
        return $id;
      }
    }
    return false;
  }

  public function delete(string $id){
    if ( \bbn\str::is_uid($id) ){
      $cf =& $this->class_cfg;
      if ( $this->db->delete($cf['table'], [$cf['arch']['medias']['id'] => $id]) ){
        if ( is_dir(bbn\mvc::get_data_path('appui-notes').'media/'.$id) ){
          return \bbn\file\dir::delete(bbn\mvc::get_data_path('appui-notes').'media/'.$id);
        }
        return true;
      }
    }
    return false;
  }

  public function get_media(string $id, $details = false){
    $cf =& $this->class_cfg;
    if (
      \bbn\str::is_uid($id) &&
      ($link_type = $this->opt->from_code('link', $this->opt_id)) &&
      ($media = $this->db->rselect($cf['table'], [], [$cf['arch']['medias']['id'] => $id])) &&
      ($link_type !== $media[$cf['arch']['medias']['type']]) //&&
      //is_file(bbn\mvc::get_data_path('appui-notes').'media/'.$id.'/'.$media[$cf['arch']['medias']['name']])
    ){
      $path = '';
      if ( $media['content'] ){
        $tmp = json_decode($media[$cf['arch']['medias']['content']], true);
        if ( isset($tmp['path']) ){
          $path = $tmp['path'];
        }
      }
      $media['path'] = (
        $media['private'] ? 
          bbn\mvc::get_user_data_path('appui-notes') :
          bbn\mvc::get_data_path('appui-notes')
      ).'media/'.$path.$id.'/'.$media[$cf['arch']['medias']['name']];
      return empty($details) ? $media['path'] : $media;
    }
    return false;
  }

  public function zip($medias, $dest){
    if ( is_string($medias) ){
      $medias = [$medias];
    }
    if ( 
      is_array($medias) &&
      ($zip = new \ZipArchive()) &&
      (
        (
          is_file($dest) &&
          ($zip->open($dest, \ZipArchive::OVERWRITE) === true)
        ) ||
        ($zip->open($dest, \ZipArchive::CREATE) === true)
      )
    ){
      foreach ( $medias as $media ){
        if ( $file = $this->get_media($media) ){
          $zip->addFile($file, basename($file));
        }
      }
      return $zip->close();
    }
    return false;
  }
}
