<?php namespace ProcessWire;
/**
 * @author Bernhard Baumrock, 05.01.2022
 * @license COMMERCIAL DO NOT DISTRIBUTE
 * @link https://www.baumrock.com
 *
 * @method string render($filename, array $vars = array(), array $options = array())
 */
class RockFrontend extends WireData implements Module {

  const tags = "RockFrontend";
  const prefix = "rockfrontend_";
  const tagsUrl = "/rockfrontend-layout-suggestions/{q}";

  const field_layout = self::prefix."layout";

  /** @var WireArray $folders */
  public $folders;

  public static function getModuleInfo() {
    return [
      'title' => 'RockFrontend',
      'version' => '0.0.4',
      'summary' => 'Module for easy frontend development',
      'autoload' => true,
      'singular' => true,
      'icon' => 'code',
      'requires' => [
        'RockMigrations',
      ],
      'installs' => [],
    ];
  }

  public function init() {
    $this->wire('rockfrontend', $this);
    $this->rm()->fireOnRefresh($this, "migrate");

    // setup folders that are scanned for files
    $this->folders = $this->wire(new WireArray());
    $this->folders->add($this->config->paths->templates);
    $this->folders->add($this->config->paths->assets);

    // hooks
    $this->addHookAfter("ProcessPageEdit::buildForm", $this, "hideLayoutField");
    $this->addHook(self::tagsUrl, $this, "layoutSuggestions");
  }

  /**
   * Find files to suggest
   * @return array
   */
  public function ___findSuggestFiles($q) {
    $suggestions = [];
    foreach($this->folders as $dir) {
      // find all files to add
      $files = $this->wire->files->find($dir, [
        'extensions' => ['php'],
        'excludeDirNames' => [
          'cache',
        ],
      ]);

      // modify file paths
      $files = array_map(function($item) use($dir) {
        // strip path from file
        $str = str_replace($dir, "", $item);
        // strip php file extension
        return substr($str, 0, -4);
      }, $files);

      // only use files from within subfolders of the specified directory
      $files = array_filter($files, function($str) use($q) {
        if(!strpos($str, "/")) return false;
        return !(strpos($str, $q)<0);
      });

      // merge files into final array
      $suggestions = array_merge(
        $suggestions,
        $files
      );
    }
    // bd($suggestions);
    return $suggestions;
  }

  /**
   * Get file path of file
   * If path is relative we look in the assets folder of RockUikit
   * @return string
   */
  public function getFile($file) {
    $file = Paths::normalizeSeparators($file);

    // add php extension if file has no extension
    if(!pathinfo($file, PATHINFO_EXTENSION)) $file .= ".php";

    // if file exists return it
    // this will also find files relative to /site/templates!
    // TODO maybe prevent loading of relative paths outside assets?
    if(is_file($file)) return $file;

    // look for the file specified folders
    foreach($this->folders as $folder) {
      $folder = Paths::normalizeSeparators($folder);
      $folder = rtrim($folder,"/")."/";
      $file = $folder.ltrim($file,"/");
      if(is_file($file)) return $file;
    }

    // no file, return false
    return false;
  }

  /**
   * Get layout from page field
   * @return array|false
   */
  public function getLayout($page) {
    $layout = $page->get(self::field_layout);
    if(!$layout) return false;
    return explode(" ", $layout);
  }

  /**
   * Hide layout field for non-superusers
   * @return void
   */
  public function hideLayoutField(HookEvent $event) {
    if($this->wire->user->isSuperuser()) return;
    $form = $event->return;
    $form->remove(self::field_layout);
  }

  /**
   * Return an image tag for the given file
   * @return string
   */
  public function img($file) {
    $url = $this->url($file);
    if($url) return "<img src='$url'>";
    return '';
  }

  /**
   * Return layout suggestions
   */
  public function layoutSuggestions(HookEvent $event) {
    return $this->findSuggestFiles($event->q);
  }

  public function migrate() {
    $rm = $this->rm();
    $rm->migrate([
      'fields' => [
        self::field_layout => [
          'type' => 'text',
          'tags' => self::tags,
          'label' => 'Layout',
          'icon' => 'cubes',
          'collapsed' => Inputfield::collapsedYes,
          'notes' => 'This field is only visible to superusers',
          'inputfieldClass' => 'InputfieldTextTags',
          'allowUserTags' => false,
          'useAjax' => true,
          'tagsUrl' => self::tagsUrl,
          'closeAfterSelect' => 0, // dont use false
        ],
      ],
    ]);
    foreach($this->wire->templates as $tpl) {
      $rm->addFieldToTemplate(self::field_layout, $tpl);
    }
  }

  /**
   * Render file
   *
   * If path is provided as array then the first path that returns
   * some output will be used. This makes it possible to define a fallback
   * for rendering: echo $uk->render(["$template.php", "basic-page.php"]);
   *
   * Usage with selectors:
   * echo $uk->render([
   *  'id=1' => 'layouts/home',
   *  'template=foo|bar' => 'layouts/foobar',
   *  'layouts/default', // default layout (fallback)
   * ]);
   *
   * @param string|array $path
   * @param array $vars
   * @param array $options
   * @return string
   */
  public function ___render($path, $vars = null, $options = []) {
    $page = $this->wire->page;
    if(!$vars) $vars = [];

    // we add the $rf variable to all files that are rendered via RockFrontend
    $vars = array_merge($vars, ['rf'=>$this]);

    // options
    $opt = $this->wire(new WireData()); /** @var WireData $opt */
    $opt->setArray([
      'allowedPaths' => $this->folders,
    ]);
    $opt->setArray($options);

    // if path is an array render the first matching output
    if(is_array($path)) {
      foreach($path as $k=>$v) {
        // if the key is a string, it is a selector
        // if the selector does not match we do NOT try to render this layout
        if(is_string($k) AND !$page->matches($k)) continue;

        // no selector, or matching selector
        // try to render this layout/file
        // if no output we try the next one
        // if file returns FALSE we exit here
        $out = $this->render($v, $vars);
        if($out OR $out === false) return $out;
      }
      return; // no output found in any file of the array
    }

    // path is a string, render file
    $file = $this->getFile($path);
    if(!$file) return;

    $options = $opt->getArray();
    return $this->wire->files->render($file, $vars, $options);
  }

  /**
   * Render layout of given page
   * @return string
   */
  public function renderLayout(Page $page, array $fallback) {
    $layout = $this->getLayout($page);
    if($layout === false) return $this->render($fallback);
    $out = '';
    foreach($layout as $file) $out .= $this->render($file);
    return $out;
  }

  /**
   * @return RockMigrations
   */
  public function rm() {
    return $this->wire->modules->get('RockMigrations');
  }

  /**
   * Given a path return the url relative to pw root
   * @return string
   */
  public function url($path) {
    $path = $this->getFile($path);
    $config = $this->wire->config;
    return str_replace($config->paths->root, $config->urls->root, $path);
  }

  public function ___install() {
    $this->init();
    $this->migrate();
  }

}
