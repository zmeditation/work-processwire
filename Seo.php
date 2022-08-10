<?php namespace RockFrontend;

use Exception;
use ProcessWire\Page;
use ProcessWire\Pageimage;
use ProcessWire\Pageimages;
use ProcessWire\Paths;
use ProcessWire\Wire;
use ProcessWire\WireData;
use ProcessWire\WireHttp;

class Seo extends Wire {

  /** @var array */
  protected $rawValues;

  /** @var WireData */
  protected $strValues;

  /** @var WireData */
  protected $tags;

  /** @var WireData */
  protected $values;

  public function __construct() {
    $this->tags = new WireData();
    $this->values = new WireData();
    $this->rawValues = [];
    $this->strValues = new WireData();
    $this->setupDefaults();
  }

  /** ##### public API ##### */

    /**
     * Get markup for given tag
     */
    public function getMarkup($tag): string {
      return $this->tags->get($tag) ?: '';
    }

    /**
     * Get the raw value of a placeholder in a tag
     * eg returns a Pageimage for og:image {value}
     * $seo->getRaw('og:image', 'value');
     * Note that the 2nd param optional since "value" is the default!
     * @return mixed
     */
    public function getRaw($tag, $key = "value") {
      $k = "$tag||$key";
      if(array_key_exists($k, $this->rawValues)) return $this->rawValues[$k];

      $values = $this->getValuesData($tag);
      $raw = $values->get($key);

      if(is_callable($raw) AND !is_string($raw)) {
        $raw = $raw->__invoke($this->wire->page);
      }

      // save to cache
      $this->rawValues[$k] = $raw;
      return $raw;
    }

    /**
     * Get values array of given tag
     */
    public function getValues($tag): array {
      $values = $this->values->get($tag);
      if(is_array($values)) return $values;
      return [];
    }

    /**
     * Get or set markup of a tag
     *
     * Use as getter:
     * echo $seo->markup('title');
     *
     * Use as setter:
     * $seo->markup('title', '<title>{value} - My Company</title>');
     *
     * @return self
     */
    public function markup($tag, $value = null): self {
      // no value --> act as getter
      if($value === null) {
        $this->tags->get($tag);
        return $this;
      }

      // value --> act as setter
      return $this->setMarkup($tag, $value);
    }

    public function render(): string {
      $out = "<!-- RockFrontend\Seo -->\n  ";
      foreach($this->tags as $name=>$tag) {
        $out .= $this->renderTag($name)."\n  ";
      }
      return $out;
    }

    /**
     * Render given tag and populate placeholders
     */
    public function renderTag($tag): string {
      $out = '';

      // get markup and values
      $markup = $out = $this->getMarkup($tag);

      // populate placeholders
      preg_replace_callback(
        "/{(.*?)(:(.*))?}/",
        function($matches) use(&$out, $tag) {
          $key = $matches[1];
          $trunc = array_key_exists(3, $matches) ? $matches[3] : false;
          $search = $trunc ? "{{$key}:{$trunc}}" : "{{$key}}";

          // get raw value for given key
          $value = $this->getStringValue($tag, $key);

          // get truncated tag
          if($trunc) $value = $this->truncate($value, $trunc, $tag);

          $out = str_replace($search, (string)$value, $out);
        },
        $markup
      );

      return $out;
    }

    /**
     * Set a tags markup
     */
    public function setMarkup($tag, $markup): self {
      $this->tags->set($tag, $markup);
      return $this;
    }

    /**
     * Set the value for the {value} tag
     *
     * This is a shortcut for using setValues()
     *
     * Usage:
     * $seo->setValue('title', $page->title);
     *
     * $seo->setValue('title', function($page) {
     *   if($page->template == 'foo') return $page->foo;
     *   elseif($page->template == 'bar') return $page->bar;
     *   return $page->title;
     * });
     */
    public function setValue($tag, $value): self {
      if(is_array($tag)) {
        foreach($tag as $t) $this->setValue($t, $value);
        return $this;
      }
      return $this->setValues($tag, ['value' => $value]);
    }

    /**
     * Set values for a tag
     *
     * Usage:
     * $seo->setValues('title', [
     *   'val' => $page->title,
     * ]);
     */
    public function setValues($tag, array $values): self {
      $values = array_merge($this->getValues($tag), $values);
      $this->values->set($tag, $values);
      return $this;
    }

    /**
     * Shortcut for setting both title and og:title
     */
    public function title($value): self {
      $this->setValue('title', $value);
      $this->setValue('og:title', $value);
      return $this;
    }

  /** ##### end public API ##### */

  /** ##### hookable methods ##### */

    /**
     * Given a pageimage return the full http url for usage in markup
     * The second parameter can be used to modify behaviour via hook.
     */
    public function ___getImageUrl($image, $tag): string {
      if($image instanceof Pageimages) $image = $image->first();
      return $this->getImageInfo($image, $tag)->url;
    }

    /**
     * Return the scaled image
     * @return mixed
     */
    public function ___getImageScaled(Pageimage $image, $tag) {
      $opt = ['upscaling' => true];
      return $image->size(1200,630,$opt);
    }

    /**
     * Get the non-truncated string value for given tag and key
     * eg get key "value" for tag "title"
     */
    public function ___getStringValue($tag, $key = 'value'): string {
      $val = $this->strValues->get("$tag||$key");
      if(is_string($val)) return $val;

      // get raw value
      $value = $this->getRaw($tag, $key);

      // convert to string
      if($tag == 'og:image') $value = $this->getImageUrl($value, $tag);

      // create final string that will be returned and stored in cache
      $str = $value ?: '';

      // save to cache
      $this->strValues->set("$tag||$key", $str);

      return $str;
    }

    public function ___setupDefaults() {
      // title
      $this->setMarkup('title', '<title>{value:60}</title>');
      $this->setMarkup('og:title', '<meta property="og:title" content="{value:95}">');
      $this->setValue(['title', 'og:title'], function($page) {
        return $page->title;
      });

      // og:image
      $this->setMarkup('og:image', '<meta property="og:image" content="{value}">');
      $this->setValue('og:image', function(Page $page) {
        try {
          return $this->wire->pages->get(1)->images->first();
        } catch (\Throwable $th) {}
      });
      $this->setMarkup('og:image:type', '<meta property="og:image:type" content="{value}">');
      $this->setValue('og:image:type', function() {
        $img = $this->getRaw('og:image');
        return $this->getImageInfo($img, 'og:image')->mime;
      });

      $this->setMarkup('og:image:width', '<meta property="og:image:width" content="{value}">');
      $this->setValue('og:image:width', function() {
        $img = $this->getRaw('og:image');
        return $this->getImageInfo($img, 'og:image')->width;
      });
      $this->setMarkup('og:image:height', '<meta property="og:image:height" content="{value}">');
      $this->setValue('og:image:height', function() {
        $img = $this->getRaw('og:image');
        return $this->getImageInfo($img, 'og:image')->height;
      });
      $this->setMarkup('og:image:alt', '<meta property="og:image:alt" content="{value:95}">');
      $this->setValue('og:image:alt', function() {
        return $this->getRaw('title');
      });
    }

    public function getImageInfo($img, $tag, $scale = true) {
      $info = new WireData();

      if($img instanceof Pageimage) {
        if($scale) $img = $this->getImageScaled($img, $tag);
        $path = Paths::normalizeSeparators($img->filename);
        $info->setArray([
          'path' => $path,
          'url' => str_replace(
            $this->wire->config->paths->root,
            $this->wire->pages->get(1)->httpUrl(),
            $path
          ),
          'width' => $img->width,
          'height' => $img->height,
          'mime' => mime_content_type($img->filename),
        ]);
      }
      elseif(is_string($img)) {
        // image is a string
        // that means it is a relative string like /site/templates/img/foo.jpg
        $filename = Paths::normalizeSeparators($img);
        $filename = ltrim($filename, "/");
        $filename = $this->wire->config->paths->root.$filename;
        if(is_file($filename)) {
          $size = getimagesize($filename);
          $info->setArray([
            'path' => $filename,
            'url' => str_replace(
              $this->wire->config->paths->root,
              $this->wire->pages->get(1)->httpUrl(),
              $filename
            ),
            'width' => $size[0],
            'height' => $size[1],
            'mime' => $size['mime'],
          ]);
        }
      }

      // bd($info->getArray(), 'info');
      return $info;
    }

    /**
     * Return truncated value
     */
    public function ___truncate($value, $length, $tag): string {
      return $this->wire->sanitizer->getTextTools()
        ->truncate((string)$value, [
          'type' => 'word',
          'maximize' => true,
          'maxLength' => $length,
          'visible' => true,
          'more' => false,
          'collapseLinesWith' => '; ',
        ]);
    }

  /** ##### end hookable methods ##### */

  /** ##### internal methods ##### */

    /**
     * Returns a WireData object instead of a plain php array
     * That ensures that requesting non-existing properties does not throw
     * an error.
     */
    protected function getValuesData($tag): WireData {
      $values = new WireData();
      $values->setArray($this->getValues($tag));
      return $values;
    }

  /** ##### end internal methods ##### */

  /** ##### magic methods ##### */

  public function __debugInfo() {
    return [
      'tags' => $this->tags->getArray(),
      'values' => $this->values->getArray(),
      'rawValues (cache)' => $this->rawValues,
      'strValues (cache)' => $this->strValues->getArray(),
      'render()' => $this->render(),
    ];
  }

  public function __toString() {
    return $this->render();
  }

}
