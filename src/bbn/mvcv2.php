<?php
namespace bbn;

/**
 * Model View Controller Class
 *
 *
 * This class will route a request to the according model and/or view through its controller.
 * A model and a view can be automatically associated if located in the same directory branch with the same name than the controller in their respective locations
 * A view can be directly imported in the controller through this very class
 *
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright BBN Solutions
 * @since Apr 4, 2011, 23:23:55 +0000
 * @category  MVC
 * @license   http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @version 0.2r89
 * @todo Merge the output objects and combine JS strings.
 * @todo Stop to rely only on sqlite and offer file-based or any db-based solution.
 * @todo Look into the check function and divide it
 */

class mvcv2 extends obj implements \bbn\mvc\api{

	use mvc\common;

	private
		/**
		 * Is set to null while not routed, then 1 if routing was sucessful, and false otherwise.
		 * @var null|boolean
		 */
		$is_routed,
		/**
		 * The first controller to be called at the top of the script.
		 * @var null|string
		 */
		$original_controller,
		/**
		 * The list of used controllers with their corresponding request, so we don't have to look for them again.
		 * @var array
		 */
		$known_controllers = [],
		/**
		 * The list of views which have been loaded. We keep their content in an array to not have to include the file again. This is useful for loops.
		 * @var array
		 */
		$loaded_views = [],
		/**
		 * The list of views which have been loaded. We keep their content in an array to not have to include the file again. This is useful for loops.
		 * @var array
		 */
		$params = [],
		/**
		 * The path sent to the main controller.
		 * @var null|string
		 */
		$path,
		/**
		 * @var \bbn\db\connection Database object
		 */
		$db,
		/**
		 * Determines if it is sent through the command line
		 * @var boolean
		 */
		$cli;

	public
		/**
		 * An external object that can be filled after the object creation and can be used as a global with the function add_inc
		 * @var stdClass
		 */
		$inc,
		/**
		 * The data model
		 * @var null|array
		 */
		$data = [],
		/**
		 * The output object
		 * @var null|object
		 */
		$obj,
		/**
		 * The file extension of the view
		 * @var null|string
		 */
		$ext,
		/**
		 * The request sent to the server to get the actual controller.
		 * @var null|string
		 */
		$url,
		/**
		 * @var array $_POST
		 */
		$post = [],
		/**
		 * @var array $_GET
		 */
		$get = [],
		/**
		 * @var array $_FILES
		 */
		$files = [],
		/**
		 * List of possible outputs with their according file extension possibilities
		 * @var array
		 */
		$outputs = ['dom'=>'html','html'=>'html','image'=>'jpg,jpeg,gif,png,svg','json'=>'json','text'=>'txt','xml'=>'xml','js'=>'js','css'=>'css','less'=>'less'],

		/**
		 * List of possible and existing universal controller.
		 * First every item is set to one, then if a universal controller is needed, self::universal_controller() will look for it and sets the according array element to the file name if it's found and to false otherwise.
		 * @var array
		 */
		$ucontrollers = [
		'dom' => 1,
		'html' => 1,
		'image' => 1,
		'json' => 1,
		'text' => 1,
		'xml' => 1,
		'css' => 1,
		'js' => 1
	];

	/**
	 * This will call the initial build a new instance. It should be called only once from within the script. All subsequent calls to controllers should be done through $this->add($path).
	 *
	 * @param object | string $db The database object in the first call and the controller path in the calls within the class (through Add)<em>(e.g books/466565 or html/home)</em>
	 * @param string | object $parent The parent controller</em>
	 * @return bool
	 */
	public function __construct($db = null, $routes = []){
		// The initial call should only have $db as parameter
		if ( defined('BBN_CUR_PATH') ){
			if ( is_object($db) && ( $class = get_class($db) ) && ( $class === 'PDO' || strpos($class, 'bbn\\db\\') !== false ) ){
				$this->db = $db;
			}
			else{
				$this->db = false;
			}
			$this->inc = new \stdClass();
			$this->routes = $routes;
			$this->cli = (php_sapi_name() === 'cli');
			// When using CLI a first parameter can be used as route,
			// a second JSON encoded can be used as $this->post
			if ( $this->cli ){
				global $argv;
				// Controller called with CLI through arguments
				if ( isset($argv[1]) ){
					$this->set_params($argv[1]);
					if ( isset($argv[2]) && json_decode($argv[2]) ){
						$this->post = array_map(function($a){
							return \bbn\str\text::correct_types($a);
						}, json_decode($argv[2], 1));
					}
				}
			}
			else{
				if ( count($_POST) > 0 ){
					$this->post = array_map(function($a){
						return \bbn\str\text::correct_types($a);
					}, $_POST);
				}
				if ( count($_GET) > 0 ){
					$this->get = array_map(function($a){
						return \bbn\str\text::correct_types($a);
					}, $_GET);
				}
				if ( count($_FILES) > 0 ){
					foreach ( $_FILES as $n => $f ){
						if ( is_array($f['name']) ){
							$this->files[$n] = [];
							foreach ( $f['name'] as $i => $v ){
								array_push($this->files[$n], [
									'name' => $v,
									'tmp_name' => $f['tmp_name'][$i],
									'type' => $f['type'][$i],
									'error' => $f['error'][$i],
									'size' => $f['size'][$i],
								]);
							}
						}
						else{
							$this->files[$n] = $f;
						}
					}
				}
				if ( isset($_SERVER['REQUEST_URI']) &&
					( BBN_CUR_PATH === '' || strpos($_SERVER['REQUEST_URI'],BBN_CUR_PATH) !== false ) ){
					$url = explode("?", urldecode($_SERVER['REQUEST_URI']))[0];
					$this->set_params(substr($url, strlen(BBN_CUR_PATH)));
				}
			}

			if ( $this->cli ){
				$this->original_mode = 'cli';
			}
			// @todo
			// Otherwise in the case there's a "appui" POST we'll throw back JSON
			else if ( isset($this->post['appui']) && isset($this->outputs[$this->post['appui']]) ){
				$this->original_mode = $this->post['appui'];
				unset($this->post['appui']);
			}
			else if ( count($this->post) > 0 ){
				if ( isset($this->post['appui']) ){
					unset($this->post['appui']);
				}
				$this->original_mode = 'json';
			}
			// Otherwise we'll return a whole DOM (HTML page)
			else{
				$this->original_mode = 'dom';
			}
			$this->url = implode('/',$this->params);
			$path = $this->url;
		}
		if ( isset($path) ){
			$this->route($path);
		}
	}

	public function get_params(){
		return $this->params;
	}

	public function get_post(){
		return $this->post;
	}

	public function get_get(){
		return $this->get;
	}

	public function get_files(){
		return $this->files;
	}

	/**
	 * This will fetch the route to the controller for a given path. Chainable
	 *
	 * @param string $path The request path <em>(e.g books/466565 or xml/books/48465)</em>
	 * @return void
	 */
	private function route($path='')
	{
		if ( !$this->is_routed && self::check_path($path) )
		{
			$this->is_routed = 1;
			$fpath = $path;

			// We go through each path, starting by the longest until it's empty
			while ( strlen($fpath) > 0 ){
				if ( isset($this->known_controllers[$path]) ){

				}
				else if ( isset($this->routes[$fpath]) ){
					$s1 = strlen($path);
					$s2 = strlen($fpath);
					$add = $s1 !== $s2 ? substr($path, $s2) : '';
					$this->path = (is_array($this->routes[$fpath]) ? $this->routes[$fpath][0] :
							$this->routes[$fpath]).$add;
				}
				else{
					$fpath = strpos($fpath,'/') === false ? '' : substr($this->path,0,strrpos($fpath,'/'));
				}
			}
			if ( !isset($this->path) ) {
				$this->path = $path;
			}

			$this->controller = new \bbn\mvc\controller($this, $this->path);
		}
		return $this;
	}

	private function set_params($path)
	{
		$this->params = [];
		$tmp = explode('/', $path);
		$num_params = count($tmp);
		foreach ( $tmp as $t ){
			if ( !empty($t) ){
				array_push($this->params, $t);
			}
		}
	}

	/**
	 * This function gets the content of a view file and adds it to the loaded_views array.
	 *
	 * @param string $p The full path to the view file
	 * @return string The content of the view
	 */
	private function add_view($p)
	{
		if ( !isset($this->loaded_views[$p]) && is_file(self::vpath.$p) ){
			$this->loaded_views[$p] = file_get_contents(self::vpath.$p);
		}
		if ( !isset($this->loaded_views[$p]) ){
			die("The view $p doesn't exist");
		}
		return $this->loaded_views[$p];
	}

	/**
	 * This function gets the source of a PHP template file and adds it to the loaded_phps array.
	 *
	 * @param string $p The full path to the PHP file without the extension
	 * @return string The source of the template
	 */
	private function add_php($p)
	{
		if ( !isset($this->loaded_phps[$p]) && is_file(self::vpath.$p) ){
			$this->loaded_phps[$p] = file_get_contents(self::vpath.$p);
		}
		if ( !isset($this->loaded_phps[$p]) ){
			die("The template $p doesn't exist");
		}
		return $this->loaded_phps[$p];
	}

	/**
	 * This fetches the universal controller for the according mode if it exists.
	 *
	 * @param string $c The mode (dom, html, json, txt, xml...)
	 * @return string controller full name
	 */
	private function universal_controller($c)
	{
		if ( !isset($this->ucontrollers[$c]) ){
			return false;
		}
		if ( $this->ucontrollers[$c] === 1 ){
			$this->ucontrollers[$c] = is_file(self::cpath.$c.'.php') ? self::cpath.$c.'.php' : false;
		}
		return $this->ucontrollers[$c];
	}

	/**
	 * Adds the newly found controller to the known controllers array, and sets the original controller if it has not been set yet
	 *
	 * @param string $c The name of the request or how set by the controller
	 * @param file $f The actual controller file ($this->controller)
	 * @return void
	 */
	private function set_controller($c)
	{
		if ( $this->controller && $this->mode ){
			if ( !isset($this->known_controllers[$this->mode.'/'.$c]) ){
				$this->known_controllers[$this->mode.'/'.$c] = [
					'path' => $this->controller,
					'args' => $this->arguments
				];
			}
			if ( is_null($this->original_controller) ){
				$this->original_controller = $this->mode.'/'.$c;
			}
		}
	}

	/**
	 * This directly renders content with arbitrary values using the existing Mustache engine.
	 *
	 * @param string $view The view to be rendered
	 * @param array $model The data model to fill the view with
	 * @return void
	 */
	public function render($view, $model='')
	{
		if ( empty($model) && $this->has_data() ){
			$model = $this->data;
		}
		if ( !is_array($model) ){
			$model = [];
		}
		if ( is_string($view) ) {
			return \bbn\tpl::render($view, $model);
		}
		die(\bbn\tools::hdump("Problem with the template", $view));
	}

	/**
	 * Returns true if called from CLI/Cron, false otherwise
	 *
	 * @return boolean
	 */
	public function is_cli()
	{
		return $this->cli;
	}

	/**
	 * This looks for a given controller in the file system if it has not been already done and returns it if it finds it, false otherwise.
	 *
	 * @param string $p
	 * @return void
	 */
	private function get_controller($p)
	{
		if ( !$this->controller ){
			if ( !is_string($p) ){
				return false;
			}
			if ( isset($this->known_controllers[$this->mode.'/'.$p]) ){
				$this->dest = $p;
				$this->dir = dirname($p);
				if ( $this->dir === '.' ){
					$this->dir = '';
				}
				else{
					$this->dir .= '/';
				}
				$this->controller = $this->known_controllers[$this->mode.'/'.$p]['path'];
				if ( isset($this->known_controllers[$this->mode.'/'.$p]['args']) ){
					$this->arguments = $this->known_controllers[$this->mode.'/'.$p]['args'];
				}
			}
			else{
				if ( isset($this->routes[$this->mode][$p]) ){
					$p = is_array($this->routes[$this->mode][$p]) ? $this->routes[$this->mode][$p][0] : $this->routes[$this->mode][$p];
				}
				if ( is_file(self::cpath.$this->mode.'/'.$p.'.php') ){
					$this->controller = self::cpath.$this->mode.'/'.$p.'.php';
					$parts = explode('/', $p);
					$num = count($parts);
					$path = self::cpath.$this->mode.'/';
					// if the current directory of the controller, or any directory above it in the controllers' filesystem, has a file called _ctrl.php, it will be executed and expected to return a non false value in order to authorize the loading of the controller
					foreach ( $parts as $pt ){
						if ( is_file($path.'_ctrl.php') ){
							array_push($this->checkers, $path.'_ctrl.php');
						}
						$path .= $pt.'/';
					}
				}
				else{
					return false;
				}
				$this->dest = $p;
				$this->dir = dirname($p);
				if ( $this->dir === '.' ){
					$this->dir = '';
				}
				else{
					$this->dir .= '/';
				}
				$this->set_controller($p);
			}
		}
		return 1;
	}

	/**
	 * This will reroute a controller to another one seemlessly. Chainable
	 *
	 * @param string $path The request path <em>(e.g books/466565 or xml/books/48465)</em>
	 * @return void
	 */
	public function reroute($path='', $check = 1)
	{
		$this->is_routed = false;
		$this->controller = false;
		$this->is_controlled = null;
		$this->route($path);
		if ( $check ){
			$this->check();
		}
		return $this;
	}

	/**
	 * This will add the given string to the script property, and create it if needed. Chainable
	 *
	 * @param string $script The javascript chain to add
	 * @return void
	 */
	public function add_script($script)
	{
		if ( is_object($this->obj) ){
			if ( !isset($this->obj->script) ){
				$this->obj->script = '';
			}
			$this->obj->script .= $script;
		}
		return $this;
	}

	/**
	 * This will get a javascript view encapsulated in an anonymous function for embedding in HTML.
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function get_js($path='')
	{
		if ( $r = $this->get_view($path, 'js') ){
			return '
<script>
(function($){
'.$r.'
})(jQuery);
</script>';
		}
		return false;
	}

	/**
	 * This will get a CSS view encapsulated in a scoped style tag.
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function get_css($path='')
	{
		if ( $r = $this->get_view($path, 'css') ){
			return '<style scoped>'.\CssMin::minify($r).'</style>';
		}
		return false;
	}

	/**
	 * This will get and compile a LESS view encapsulated in a scoped style tag.
	 *
	 * @param string $path
	 * @return string|false
	 */
	public function get_less($path='')
	{
		if ( !isset($this->less) ){
			if ( !class_exists('lessc') ){
				die("No less class, check composer");
			}
			$this->less = new \lessc();
		}
		if ( $r = $this->get_view($path, 'less') ){
			return '<style scoped>'.\CssMin::minify($this->less->compile($r)).'</style>';
		}
		return false;
	}

	/**
	 * This will add a javascript view to $this->obj->script
	 * Chainable
	 *
	 * @param string $path
	 * @param string $mode
	 * @return string|false
	 */
	public function add_js()
	{
		$args = func_get_args();
		foreach ( $args as $a ){
			if ( is_array($a) ){
				$data = $a;
			}
			else if ( is_string($a) ){
				$path = $a;
			}
		}
		if ( $r = $this->get_view(isset($path) ? $path : '', 'js') ){
			$this->add_script($this->render($r, isset($data) ? $data : $this->data));
		}
		return $this;
	}

	/**
	 * This will get a view.
	 *
	 * @param string $path
	 * @param string $mode
	 * @return string|false
	 */
	public function get_view($path, $data=[])
	{
		if ( isset($this->loaded_views[$path]) ){
			return $this->loaded_views[$path];
		}
		$view = new \bbn\mvc\view($path, $data);
		$this->loaded_views[$path] = $view;
		return $this->get();
	}

	/**
	 * This will get the model. There is no order for the arguments.
	 *
	 * @params string path to the model
	 * @params array data to send to the model
	 * @return array|false A data model
	 */
	public function get_model($path, $data=[])
	{
		$model = new \bbn\mvc\model($this->db, $path, $data);
		return $model->get();
	}

	/**
	 * Adds a property to the MVC object inc if it has not been declared.
	 *
	 * @return bool
	 */
	public function add_inc($name, $obj)
	{
		if ( !isset($this->inc->{$name}) ){
			$this->inc->{$name} = $obj;
		}
	}

	/**
	 * Processes the controller and checks whether it has been routed or not.
	 *
	 * @return bool
	 */
	public function check()
	{
		foreach ( $this->checkers as $chk ){
			// If a checker file returns false, the controller is not processed
			if ( !include_once($chk) ){
				return false;
			}
		}
		$this->process();
		return $this->is_routed;
	}

	/**
	 * Returns the output object.
	 *
	 * @return object|false
	 */
	public function get()
	{
		if ( $this->check() ){
			return $this->obj;
		}
		return false;
	}

	/**
	 * Returns the rendered result from the current mvc if successufully processed
	 * process() (or check()) must have been called before.
	 *
	 * @return string|false
	 */
	public function get_rendered()
	{
		if ( isset($this->obj->output) ){
			return $this->obj->output;
		}
		return false;
	}

	/**
	 * Returns the rendered result from the current mvc if successufully processed
	 * process() (or check()) must have been called before.
	 *
	 * @return string|false
	 */
	public function get_script()
	{
		if ( isset($this->obj->script) ){
			return $this->obj->script;
		}
		return '';
	}

	/**
	 * Merges the existing data if there is with this one. Chainable.
	 *
	 * @return void
	 */
	public function add_data(array $data)
	{
		$ar = func_get_args();
		foreach ( $ar as $d ){
			if ( is_array($d) ){
				$this->data = $this->has_data() ? array_merge($this->data,$d) : $d;
			}
		}
		return $this;
	}

	/**
	 * Merges the existing data if there is with this one. Chainable.
	 *
	 * @return void
	 */
	public function add($d, $data=array())
	{
		$o = new mvc($d, $this, $data);
		if ( $o->check() ){
			return $o;
		}
		return false;
	}

	/**
	 * Outputs the result.
	 *
	 * @return void
	 */
	public function output()
	{
		if ( !$this->obj ){
			$this->obj = new \stdClass();
		}
		if ( $this->check() && $this->obj ){

			if ( $this->cli ){
				die(isset($this->obj->output) ? $this->obj->output : "no output");
			}
			if ( isset($this->obj->prescript) ){
				if ( empty($this->obj->prescript) ){
					unset($this->obj->prescript);
				}
				else{
					$this->obj->prescript = \JShrink\Minifier::minify($this->obj->prescript);
				}
			}
			if ( isset($this->obj->script) ){
				if ( empty($this->obj->script) ){
					unset($this->obj->script);
				}
				else{
					$this->obj->script = \JShrink\Minifier::minify($this->obj->script);
				}
			}
			if ( isset($this->obj->postscript) ){
				if ( empty($this->obj->postscript) ){
					unset($this->obj->postscript);
				}
				else{
					$this->obj->postscript = \JShrink\Minifier::minify($this->obj->postscript);
				}
			}
			if ( count((array)$this->obj) === 0 ){
				header('HTTP/1.0 404 Not Found');
				exit();
			}
			switch ( $this->mode ){
				case 'json':
				case 'js':
				case 'css':
				case 'dom':
				case 'html':
					if ( !ob_start("ob_gzhandler" ) ){
						ob_start();
					}
					else{
						header('Content-Encoding: gzip');
					}
					break;
				default:
					ob_start();
			}
			if ( empty($this->obj->output) && !empty($this->obj->file) ){
				if ( is_file($this->obj->file) ){
					$this->obj->file = new \bbn\file\file($this->obj->file);
					$this->mode = '';
				}
				else if ( is_object($this->obj->file) ){
					$this->mode = '';
				}
			}
			if ( (empty($this->obj->output) && empty($this->obj->file) && ($this->mode !== 'json')) ||
				(($this->mode === 'json') && empty($this->obj)) ){
				$this->mode = '';
			}
			switch ( $this->mode ){

				case 'json':
					if ( isset($this->obj->output) ){
						$this->obj->html = $this->obj->output;
						unset($this->obj->output);
					}
					header('Content-type: application/json; charset=utf-8');
					echo json_encode($this->obj);
					break;

				case 'dom':
				case 'html':
					header('Content-type: text/html; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'js':
					header('Content-type: application/javascript; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'css':
					header('Content-type: text/css; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'less':
					header('Content-type: text/x-less; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'text':
					header('Content-type: text/plain; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'xml':
					header('Content-type: text/xml; charset=utf-8');
					echo $this->obj->output;
					break;

				case 'image':
					if ( isset($this->obj->img) ){
						$this->obj->img->display();
					}
					else{
						$this->log("Impossible to display the following image: ".$this->obj->img->name);
						header('HTTP/1.0 404 Not Found');

					}
					break;

				default:
					//die(\bbn\tools::dump($this->obj->file, method_exists($this->obj->file, 'download')));
					if ( isset($this->obj->file) && is_object($this->obj->file) && method_exists($this->obj->file, 'download') ){
						$this->obj->file->download();
					}
					else{
						$this->log("Impossible to display the following controller", $this);
						header('HTTP/1.0 404 Not Found');
						exit();
					}
			}
		}
	}
}
