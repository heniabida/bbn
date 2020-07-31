<?php

/**
 * Manages the translations berween the URLs requested and the app filesystem.
 *
 * @category  MVC
 * @package MVC
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright 2015 BBN Solutions
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 *
 * @link https://bbn.io/php/doc/bbn/mvc/router
 * @since May 12, 2015, 12:55:56 +0000
 */

namespace bbn\mvc;

use bbn;

/**
 * @category MVC
 * @package MVC
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link https://bbn.io/php/doc/bbn/mvc/router
 */
class router
{
  use common;
  use bbn\models\tts\retriever;

  /**
   * The path for the default controller.
   *
   * @var array
   */
  private static $_def = 'default';
  /**
   * The list of types of controllers.
   *
   * @var array
   */
  private static $_controllers = ['cli', 'dom', 'content', 'public', 'private'];
  /**
   * The list of filetypes for each non controller element.
   *
   * @var array
   */
  private static $_filetypes = [
    'model' => ['php'],
    'html' => ['html', 'php'],
    'js' => ['js', 'coffee'],
    'css' => ['css', 'less', 'scss'],
  ];
  /**
   * The list of types.
   *
   * @var array
   */
  private static $_modes = [
    'image',
    'file',
    'cli',
    'private',
    'dom',
    'public',
    'model',
    'html',
    'js',
    'css',
  ];
  /**
   * @var array list of used routes with each original request to avoid looking for them again
   */
  private static $_known = [
    'cli' => [],
    'dom' => [],
    'public' => [],
    'private' => [],
    'model' => [],
    'html' => [],
    'js' => [],
    'css' => [],
    'component' => [],
  ];

  /**
   * @var string The current mode as defined in self::$_modes
   */
  private $_mode;
  /**
   * @var string The path to prepend to the given path
   *
   * @todo deprecated
   */
  private $_prepath;
  /**
   * @var string The path to the app root (where is ./mvc)
   */
  private $_root;
  /**
   * @var array The list of known external controllers routes
   */
  private $_routes = [];

  /**
   * Checks whether Yhe given string is a valid mode.
   *
   * @param string $mode The mode as defined in self::$_modes
   *
   * @return bool
   */
  public static function is_mode(string $mode): bool
  {
    return (bool)\in_array($mode, self::$_modes, true);
  }

  /**
   * Removes trailing slashes.
   *
   * @param string $path
   *
   * @return string
   */
  public static function parse(string $path): string
  {
    while ( strpos($path, '//') !== false ){
      $path = str_replace('//', '/', $path);
    }
    $path = trim($path, '/\\ ');

    return $path ?: '.';
  }

  /**
   * Get the full path in the mvc/mode of the main app.
   *
   * @param string $mode The mode as defined in self::$_modes
   *
   * @return string
   */
  private function _get_root(string $mode): string
  {
    if (self::is_mode($mode)) {
      return $this->_root . $this->_get_mode_path($mode);
    }

    return null;
  }

  private function _get_mode_path(string $mode)
  {
    if ($mode === 'dom') {
      return 'mvc/public/';
    }
    if ($mode === 'cli') {
      return 'cli/';
    }
    if (in_array($mode, self::$_modes)) {
      return 'mvc/'.$mode.'/';
    }
    die("The mode $mode doesn't exist in router!");
  }

  /**
   * Get the full path in the mvc/mode of an external app (plugin).
   *
   * @param string $mode The mode as defined in self::$_modes
   * @param string $path The path of the plugin
   * @return void
   */
  private function _get_alt_root(string $mode, string $path = null): ?string
  {
    if (($path || $this->alt_root) &&
        self::is_mode($mode) &&
        isset($this->_routes['root'][$path ?: $this->alt_root])
    ) {
      $res = bbn\str::parse_path($this->_routes['root'][$path ?: $this->alt_root]['path']) .
        '/src/' . $this->_get_mode_path($mode);
      return $res;
    }

    return null;
  }

  /**
   * Checks whether a path is part of the routes['alias'] array.
   *
   * @param mixed $path
   *
   * @return string|null
   */
  private function _is_alias(string $path): ?string
  {
    if (!empty($this->_routes['alias'])) {
      $path = self::parse($path);
      if (isset($this->_routes['alias'][$path])) {
        return $path;
      }
      foreach (array_keys($this->_routes['alias']) as $p) {
        if (strpos($path, $p . '/') === 0) {
          return $p;
        }
      }
    }
    return null;
  }

  /**
   * Checks whether a path is part of the routes['alias'] array.
   *
   * @param mixed $path
   *
   * @return string|null
   */
  private function _get_alias(string $path): ?string
  {
    $path = self::parse($path);
    if (isset($this->_routes['alias'][$path])) {
      return \is_array($this->_routes['alias'][$path]) ? $this->_routes['alias'][$path][0] : $this->_routes['alias'][$path];
    }

    return null;
  }

  /**
   * Checks whether a path is know for its corresponding mode.
   *
   * @param string $path
   * @param string $mode
   *
   * @return bool
   */
  private function _is_known(string $path, string $mode): bool
  {
    return self::is_mode($mode) && isset(self::$_known[$mode][$path]);
  }

  /**
   * Retrieves the route from a given path in a given mode.
   *
   * @param string $path
   * @param string $mode
   *
   * @return array|null
   */
  private function _get_known(string $path, string $mode): ?array
  {
    if ($this->_is_known($path, $mode)) {
      // If it's a controller based on an alias the original known array has to be retrieved
      if (
        \in_array($mode, self::$_controllers, true) &&
        \is_string(self::$_known[$mode][$path]) &&
        isset(self::$_known[$mode][self::$_known[$mode][$path]])
      ) {
        $path = self::$_known[$mode][$path];
      }
      return self::$_known[$mode][$path];
    }

    return null;
  }

  /**
   * Sets and stores a given route, adding the corresponding checkers.
   *
   * @param array $o
   * @param mixed bool
   */
  private function _set_known(array $o, bool $save = true): ?array
  {
    // mode, path and file indesxes are mandatory
    if (!isset($o['mode'], $o['path'], $o['file']) || !self::is_mode($o['mode']) || !\is_string($o['path']) || !\is_string($o['file'])) {
      return null;
    }
    $mode = $o['mode'];
    $path = self::parse($o['path']);
    // The root in the main application where to search in is defined according to the mode
    $root = $this->_get_root($mode);
    if (!empty($o['plugin'])) {
      $plugin_root = $this->_get_alt_root($mode, $o['plugin']);
      $plugin_path = substr($path, strlen($o['plugin']) + 1);
      /*
      if (!$plugin_root || !$plugin_path) {
        die(var_dump($plugin_root, $plugin_path, $o));
        return null;
      }
      */
      $plugin = $o['plugin'];
    }
    else {
      $plugin = '-';
    }
    // About to define self::$_known[$mode][$path] so first check it has not already been defined
    if (!isset(self::$_known[$mode][$path])) {
      self::$_known[$mode][$path] = $o;
      $s = &self::$_known[$mode][$path];
      // Defining the checker files' name according to the mode (controllers, models and CSS)
      if (\in_array($mode, self::$_controllers, true)) {
        $checker_file = '_ctrl.php';
      }
      else if (isset($o['ext'])) {
        if ($o['ext'] === 'less') {
          $checker_file = '_mixins.less';
        }
        else if (($o['mode'] === 'model')) {
          $checker_file = '_model.php';
        }
      }
      if (!empty($checker_file)) {
        // Looking for checker files in each parent directory
        $s['checkers'] = [];
        $tmp = $path;
        // Going backwards in the tree, so adding reversely to the array (prepending)
        while (\strlen($tmp) > 0) {
          $tmp = self::parse(\dirname($tmp));
          $checker = ($tmp === '.' ? '' : $tmp . '/') . $checker_file;
          if (!empty($o['plugin'])) {
            $plugin_path = self::parse(\dirname($plugin_path));
            $alt_ctrl = $plugin_root . ($plugin_path === '.' ? '' : $plugin_path . '/') . $checker_file;
            if (is_file($alt_ctrl) && !\in_array($alt_ctrl, $s['checkers'], true)) {
              array_unshift($s['checkers'], $alt_ctrl);
            }
          }
          if (is_file($root . $checker) && !\in_array($root . $checker, $s['checkers'], true)) {
            array_unshift($s['checkers'], $root . $checker);
          }
          if ($tmp === '.') {
            $tmp = '';
          }
        }
        // Particular case where it's CLI: we want the first _ctrl to be executed
        if (($mode === 'cli') && is_file($this->_get_root('public').$checker_file)) {
          array_unshift($s['checkers'], $this->_get_root('public').$checker_file);
        }
      }
    }
    if (!$save) {
      // If not saving the index is unset and the funciton will be relaunched ion case the same request is done again
      $o = self::$_known[$mode][$path];
      unset(self::$_known[$mode][$path]);

      return $o;
    }

    return self::$_known[$mode][$path];
  }

  /**
   * Return the actual controller file corresponding to a gievn path.
   *
   * @param string $path
   * @param string $mode
   *
   * @return mixed
   */
  private function _find_controller($path, $mode): ?array
  {
    // Removing tgrailing slashes
    $path = self::parse($path);
    // If the result is already known we just return it
    if ($this->_is_known($path, $mode)) {
      return $this->_get_known($path, $mode);
    }
    /** @var string $root The directory corresponding to mode where the files will be searched for */
    $root = $this->_get_root($mode);
    /** @var bool|string $file Once found, full path and filename */
    $file = false;
    /** @var string $tmp Will contain the different states of the path along searching for the file */
    $tmp = $path;
    /** @var array $args Each element of the URL outside the file path */
    $args = [];
    // Decomposing the path into parts
    $parts = bbn\x::split($path, '/');
    // Checking first if the specific route exists (through $routes['alias'])
    if ($alias_name = $this->_is_alias($tmp)) {
      // Adding args accordingly
      while (bbn\x::join($parts, '/') !== $alias_name) {
        array_unshift($args, array_pop($parts));
        if (!count($parts)) {
          break;
        }
      }
      $alias = $this->_get_alias($alias_name);
      $tmp = $alias;
    }
    /** @var array|null $plugin Plugin info if it's inside one */
    $plugin = $this->_find_plugin($tmp);
    /** @var string $root The alternative directory corresponding to mode where the files will be searched for */
    $plugin_root = $plugin ? $this->_get_alt_root($mode, $plugin['url']) : null;
    /** The path parsed from this alternative root */
    $plugin_path = $plugin ? substr($tmp, strlen($plugin['url']) + 1) : null;
    /** @var string $real_path The real application path (ie from root to the controller) */
    $real_path = null;
    // We go through the path, removing a bit each time until we find the corresponding file
    while (\strlen($tmp) > 0) {
      // navigation (we are in dom and dom is default or we are not in dom, i.e. public)
      if ((($mode === 'dom') && (BBN_DEFAULT_MODE === 'dom')) || ($mode !== 'dom')) {
        // Then looks for a corresponding file in the regular MVC
        if (file_exists($root . $tmp . '.php')) {
          $real_path = $tmp;
          $file = $root . $tmp . '.php';
          $plugin = false;
        }
        // Then looks for a home.php file in the corresponding directory
        else if (is_dir($root . $tmp) && is_file($root . $tmp . '/home.php')) {
          $real_path = $tmp . '/home';
          $file = $root . $tmp . '/home.php';
          $plugin = false;
        }
        // If an alternative root exists (plugin), we look into it for the same
        else if ($plugin) {
          // Corresponding file
          if (file_exists($plugin_root . $plugin_path . '.php')) {
            $real_path = $tmp;
            $file = $plugin_root . $plugin_path . '.php';
            $root = $plugin_root;
          }
          // home.php in corresponding dir
          else if (is_dir($plugin_root . $plugin_path) && is_file($plugin_root . ($plugin_path ? $plugin_path . '/' : '') . 'home.php')) {
            $real_path = $tmp . '/home';
            $file = $plugin_root . $plugin_path . '/home.php';
            $root = $plugin_root;
          }
        }
      }
      // Full DOM requested
      if (!$file && ($mode === 'dom')) {
        // Root index file (if $tmp is at the root level)
        if (($tmp === '.') && !$plugin) {
          // If file exists
          if (file_exists($root . 'index.php')) {
            $real_path = '.';
            $file = $root . 'index.php';
          }
          // Otherwise $file will remain undefined
          else {
            /* @todo throw an alert as there is no default index */
            $this->log(_('Impossible to find a route'));

            return null;
          }
        }
        // There is an index file in a subfolder
        else if (file_exists($root . ($tmp === '.' ? '' : $tmp . '/') . 'index.php')) {
          $real_path = $tmp;
          $file = $root . ($tmp === '.' ? '' : $tmp . '/') . 'index.php';
          $plugin = false;
        }
        // An alternative root exists, we look into it
        else if ($plugin) {
          // Corresponding file
          $dir = $plugin_root . ($plugin_path ? $plugin_path . '/' : '');
          if (is_dir($dir) && file_exists($dir . 'index.php')) {
            $real_path = $tmp;
            $file = $dir . 'index.php';
            $root = $plugin_root;
          }
          // home.php in corresponding dir
        }
      }
      if ($file) {
        break;
      }
      array_unshift($args, basename($tmp));
      $tmp = strpos($tmp, '/') === false ? '' : substr($tmp, 0, strrpos($tmp, '/'));
      if ($plugin) {
        $plugin_path = strpos($plugin_path, '/') === false ? '' : dirname($plugin_path);
      }
      if (empty($tmp) && ($mode === 'dom')) {
        $tmp = '.';
      } else if ($tmp === '.') {
        $tmp = '';
      }
    }
    /**
     * @todo Should there be a 404? If so, a real one or a default file? For which modes?
     */
    // Not found, sending the default controllers
    /*
                if ( !$file && is_file($root.'404.php') ){
                  $real_path = '404';
                  $file = $root.'404.php';
                }
                */

    if ($file) {
      return $this->_set_known([
        'file' => $file,
        'path' => $real_path,
        'root' => \dirname($root, 2) . '/',
        'request' => $path,
        'mode' => $mode,
        'plugin' => $plugin ? $plugin['url'] : false,
        'args' => $args,
      ]);
    }

    return null;
    // Aaaargh!
    //die(bbn\x::dump("No default file defined for mode $mode $tmp (and no 404 file either)"));
  }

  private function _find_plugin($path): ?array
  {
    if ($plugins = $this->get_plugins()) {
      foreach ($plugins as $p) {
        if ((strpos($path, $p['url'] . '/') === 0) || ($p['url'] === $path)) {
          return $p;
        }
      }
    }

    return null;
  }

  private function _find_translation(string $plugin = null): ?string
  {
    if (\defined('BBN_LANG')) {
      $fpath = $plugin ? $this->plugin_path($plugin) : $this->_mvc->app_path();
      if (file_exists($fpath . 'locale/' . BBN_LANG . '/' . BBN_LANG . '.json')) {
        return $fpath . 'locale/' . BBN_LANG . '/' . BBN_LANG . '.json';
      }
    }

    return null;
  }

  private function _get_classic_root($mode): ?string
  {
    return $this->_get_root($mode);
  }

  private function _get_plugin_root($mode, $plugin): ?string
  {
    if (self::is_mode($mode)) {
      return $this->plugin_path($plugin) . $this->_get_mode_path($mode);
    }
  }

  private function _get_subplugin_root($mode, $plugin, $subplugin): ?string
  {
    if (isset(self::$_filetypes[$mode])) {
      return $this->plugin_path($plugin) . 'plugins/' . $subplugin . '/' . $mode . '/';
    }
  }

  private function _get_custom_root($mode, $plugin): ?string
  {
    if (isset(self::$_filetypes[$mode])) {
      return $this->_root . 'plugins/' . $plugin . '/' . $mode . '/';
    }
  }

  private function _find_mv(string $path, string $mode): ?array
  {
    // Mode exists
    if (self::is_mode($mode)) {
      $plugin = $this->_find_plugin($path);
      $plugin_url = $plugin ? $plugin['url'] : false;
      $root = $this->_get_classic_root($mode);
      $file = false;
      $alt_root = false;
      if ($plugin_url) {
        $p = $this->_routes['root'][$plugin_url];
        $plugin = $p['name'];
        $alt_path = substr($path, strlen($plugin_url) + 1);
        $alt_root = $this->_get_plugin_root($mode, $plugin);
      }
      foreach (self::$_filetypes[$mode] as $t) {
        if (is_file($root . $path . '.' . $t)) {
          $file = $root . $path . '.' . $t;
          break;
        } else if ($alt_root) {
          if (is_file($alt_root . $alt_path . '.' . $t)) {
            $file = $alt_root . $alt_path . '.' . $t;
            break;
          }
        }
      }
      if (!empty($file)) {
        return $this->_set_known([
          'file' => $file,
          'path' => $path,
          'plugin' => $plugin_url,
          'ext' => $t,
          'mode' => $mode,
          'i18n' => $t === 'js' ? $this->_find_translation($plugin ?? null) : null,
        ], true);
      }
    }
    return null;
  }

  /**
   * This will fetch the route to the controller for a given path. Chainable.
   *
   * @param string $path The request path <em>(e.g books/466565 or xml/books/48465)</em>
   */
  public function __construct(bbn\mvc $mvc, array $routes = [])
  {
    self::retriever_init($this);
    $this->_mvc = $mvc;
    $this->_routes = $routes;
    $this->_root = $this->_mvc->app_path();
  }

  public function apply_locale(string $plugin): self
  {
    if (
      \defined('BBN_LOCALE') &&
      isset($this->_routes['root'][$plugin]['name']) &&
      is_dir($this->_routes['root'][$plugin]['path'] . '/src/locale')
    ) {
      //$lang_path = \dirname($this->_routes['root'][$plugin]['path']).'/src/locale';
      if ($lang_path = \dirname($this->_routes['root'][$plugin]['path']) . '/' . $this->_routes['root'][$plugin]['name'] . '/src/locale') {
        $textdomain = $this->_routes['root'][$plugin]['name'] . (is_file($lang_path . '/index.txt') ? (string) file_get_contents($lang_path . '/index.txt') : '');
        bindtextdomain($textdomain, $lang_path);
        bind_textdomain_codeset($textdomain, 'UTF-8');
        textdomain($textdomain);
      }
    }

    return $this;
  }

  public function reset(): self
  {
    $this->alt_root = false;

    return $this;
  }

  public function set_prepath($path): bool
  {
    if (!$this->check_path($path)) {
      die("The prepath $path is not valid");
    }
    $this->_prepath = $path;
    if (substr($this->_prepath, -1) !== '/') {
      $this->_prepath = $this->_prepath . '/';
    }
    if ($this->_mode) {
      $this->route($this->_mvc->get_url(), $this->_mode);
    }

    return true;
  }

  public function get_prepath($with_slash = 1): string
  {
    if (!empty($this->_prepath)) {
      return $with_slash ? $this->_prepath : substr($this->_prepath, 0, -1);
    }

    return '';
  }

  public function get_plugin_from_component(string $name): ?array
  {
    foreach ($this->get_plugins() as $n => $p) {
      if (bbn\x::indexOf($name, $n . '-') === 0) {
        return $p;
      }
    }

    return null;
  }

  public function route_component(string $name): ?array
  {
    if ($p = $this->get_plugin_from_component($name)) {
      $root = $p['path'] . 'src/';
      $prefix = $p['name'] . '-';
      $plugin = $p['name'];
      $plugin_url = $p['url'];
    } else {
      $prefix = (defined('BBN_APP_PREFIX') ? BBN_APP_PREFIX : BBN_APP_NAME) . '-';
      $root = $this->app_path();
      $plugin = null;
      $plugin_url = null;
    }
    if (!empty($root) && (bbn\x::indexOf($name, $prefix) === 0)) {
      $local_name = substr($name, strlen($prefix));
      $parts = explode('-', $local_name);
      $root .= 'components/';
      $path = implode('/', $parts);
      $dir = $root . $path;
      if (!empty($plugin)) {
        $this->apply_locale($plugin);
      }
      if (is_dir($dir)) {
        $res = [
          'js' => [],
          'html' => [],
          'css' => [],
        ];
        $fpath = $root . $path . '/' . end($parts);
        foreach ($res as $mode => $c) {
          foreach (self::$_filetypes[$mode] as $f) {
            if (is_file($fpath . '.' . $f)) {
              $res[$mode] = $this->_set_known([
                'file' => $fpath . '.' . $f,
                'path' => str_replace('-', '/', $local_name),
                'plugin' => $plugin_url,
                'component' => true,
                'ext' => $f,
                'mode' => $mode,
                'i18n' => $mode === 'js' ? $this->_find_translation($plugin ?? null) : null,
              ], true);
              break;
            }
          }
        }

        return $res;
      }
    }
    return null;
  }

  public function route_custom_plugin(string $path, string $mode, string $plugin): ?array
  {
    if ($root = $this->_get_custom_root($mode, $plugin)) {
      foreach (self::$_filetypes[$mode] as $t) {
        if (is_file($root . $path . '.' . $t)) {
          $file = $root . $path . '.' . $t;
          break;
        }
      }
      if (!empty($file)) {
        return $this->_set_known([
          'file' => $file,
          'path' => $path,
          'ext' => $t,
          'plugin' => $plugin,
          'mode' => $mode,
          'i18n' => $t === 'js' ? $this->_find_translation($plugin ?? null) : null,
        ], true);
      }
    }
    return null;
  }

  public function route_subplugin(string $path, string $mode, string $plugin, string $subplugin): ?array
  {
    if ( $root = $this->_get_subplugin_root($mode, $plugin, $subplugin) ){
      foreach (self::$_filetypes[$mode] as $t) {
        if (is_file($root . $path . '.' . $t)) {
          $file = $root . $path . '.' . $t;
          break;
        }
      }
      if (!empty($file)) {
        return $this->_set_known([
          'file' => $file,
          'path' => $path,
          'ext' => $t,
          'plugin' => $plugin,
          'mode' => $mode,
          'i18n' => $t === 'js' ? $this->_find_translation($plugin ?? null) : null,
        ], true);
      }
    }
    return null;
  }

  public function route(string $path, string $mode): ?array
  {
    if (self::is_mode($mode)) {
      // If there is a prepath defined we prepend it to the path
      if ($this->_prepath && (strpos($path, '/') !== 0) && (strpos($path, $this->_prepath) !== 0)) {
        $path = $this->_prepath . $path;
      }
      // We only try to retrieve a file path through a whole URL for controllers
      if (\in_array($mode, self::$_controllers, true)) {
        $this->_mode = $mode;
        return $this->_find_controller($path, $mode);
      }
      return $this->_find_mv($path, $mode);
    }

    return null;
  }

  public function fetch_dir($path, $mode): ?array
  {
    // Only for views and models
    if (self::is_mode($mode) && !\in_array($mode, self::$_controllers)) {
      // If there is a prepath defined we prepend it to the path
      if ($this->_prepath &&
          (strpos($path, '/') !== 0) &&
          (strpos($path, $this->_prepath) !== 0)
      ) {
        $path = $this->_prepath . $path;
      }

      /** @var string $root Where the files will be searched for by default */
      $root = $this->_get_root($mode);
      $plugin = $this->_find_plugin($path);
      if ($plugin && ($alt_path = $plugin['url'])) {
        $alt_root = $this->_get_alt_root($mode, $alt_path);
      }
      else if ($alt_root = $this->_get_alt_root($mode)) {
        $alt_path = $this->alt_root;
      }
      $dir = false;
      foreach (self::$_filetypes[$mode] as $t) {
        $dir1 = self::parse($root . $path);
        if (is_dir($dir1) && (strpos($dir1, $root) === 0)) {
          $dir = $dir1;
        }
        else if (
          $alt_path && ($dir2 = self::parse($alt_root . substr($path, \strlen($alt_path) + 1))) && (strpos($dir2, $alt_root) === 0) &&
          is_dir($dir2)
        ) {
          $dir = $dir2;
        }
        if ($dir) {
          $res = [];
          $files = bbn\file\dir::get_files($dir);
          foreach ($files as $f) {
            if (\in_array(bbn\str::file_ext($f), self::$_filetypes[$mode], true)) {
              $res[] = $path . '/' . bbn\str::file_ext($f, true)[0];
            }
          }

          return $res;
        }
      }
    }

    return null;
  }

  public function get_routes(): array
  {
    return $this->_routes;
  }
}
