<?php
/*
Plugin Name: Page Pointer
Description: Create Post about any Page on the web. Visitors to your post are redirected to that page. Your Post appears - complete with excerpt, featured image, etc. - in your blog's taxonomy.
Version: 1.0
Author: Aaron Collegeman, Fat Panda
Author URI: http://aaroncollegeman.com/fatpanda
Plugin URI: http://aaroncollegeman.com/wp-pagepointer
*/

class WpPagePointer {

  const META_URL = '_page_pointer_url';
  const META_IMPORT_FLAG = '_page_pointer_import';
  const META_IMAGE_SOURCE_URL = '_page_pointer_image_source_url';
  const TRANSIENT_HTML = '_pp_%s';

  private static $plugin;
  static function load() {
    $class = __CLASS__; 
    return ( self::$plugin ? self::$plugin : ( self::$plugin = new $class() ) );
  }

  private function __construct() {
    add_action( 'init', array( $this, 'init' ));
    add_action( 'admin_init', array( $this, 'admin_init' ));
  }

  function init() {
    add_action( 'template_redirect', array( $this, 'template_redirect' ) );
    add_action( 'save_post', array($this, 'save_post') );
    add_action( 'wp_ajax_og_preview', array($this, 'og_preview') );
  }

  function admin_init() {
    add_meta_box(__CLASS__, 'Redirect To', array($this, 'meta_box'), 'post');    
  }

  private function get_html($url) {
    $transient = sprintf(self::TRANSIENT_HTML, md5($url));

    if ($result = get_transient($transient)) {
      return $result;

    } else {
      $result = _wp_http_get_object()->get($url, array(
        'sslverify' => false,
        'timeout' => 30000
      ));

      if (!is_wp_error($result)) {
        set_transient($transient, $result, 3600); // cache for one hour
      }

      return $result;
    }
  }

  private function get_image($url) {
    $result = _wp_http_get_object()->get($url, array(
      'sslverify' => false,
      'timeout' => 30000
    ));    

    if (!is_wp_error($result)) {
      return $result['body'];
    } else {
      return false;
    }
  }

  function og_preview() {
    if (!current_user_can('edit_posts')) {
      exit;
    }

    extract($_POST);
    if (empty($url)) {
      exit;
    }

    if (is_wp_error($request = $this->get_html($url))) {
      echo '<p style="color:red;">'.$request->get_error_message().'</p>';
    } else {
      if ($og = WpPagePointer_OpenGraph::parse($request['body'])) {
        ?>
          <?php if ($og->image) { ?>
            <img src="<?php echo esc_attr($og->image) ?>" style="align:left; margin-right:10px;" />
          <?php } ?>
          <div class="about">
            <p>
              <big><a href="<?php echo esc_attr($url) ?>" target="_blank"><?php echo htmlentities($og->title) ?></a>
              on <?php echo $og->site_name ? $og->site_name : 'Unknown Site' ?></big>
            </p>
            <?php if ($og->image) { ?>
              <p>
                <label>
                  <input type="checkbox" name="<?php $this->field('import_flag') ?>" value="1" <?php $this->checked((bool) $_POST['import_flag']) ?> />
                  Import Image and Set as Featured
                </label>
              </p>
            <?php } ?>
          </div>
        <?php
      }
    }
   
    exit;
  }

  function meta_box($post) {
    ?>
      <input type="hidden" name="<?php $this->field('nonce') ?>" value="<?php echo wp_create_nonce(plugin_basename(__FILE__)) ?>" />
      <p>
        <label for="<?php $this->id('url') ?>" style="display:none;">
          This Post should redirect visitors to:
        </label>
        <input value="<?php echo esc_attr(get_post_meta($post->ID, self::META_URL, true)) ?>" type="text" id="<?php $this->id('url') ?>" name="<?php $this->field('url') ?>" value="asdf" style="margin-top:5px; width:100%; font-size:1.5em;" />
      </p>

      <div id="<?php $this->id('og_preview') ?>"></div>
      <div style="clear:both;"></div>

      <script>
        (function($) {
          var url = $('#<?php $this->id('url') ?>');
          var timeout;
          var was;

          var preview = function() {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
              if (was == url.val()) {
                return;
              }
              was = url.val();
              url.addClass('wait');
              var import_checkbox = $('#<?php $this->id('import_flag') ?>');
              var import_flag = '<?php echo get_post_meta($post->ID, self::META_IMPORT_FLAG, true) ?>';
              if (import_checkbox.size()) {
                import_flag = import_checkbox.attr('checked');
              }

              $.post(ajaxurl, { action: 'og_preview', url: url.val(), import_flag: import_flag }, function(html) {
                $('#<?php $this->id('og_preview') ?>').html(html);
                url.removeClass('wait');
              });
            }, 500);
          }

          $('#<?php $this->id('url') ?>').bind('keyup blur', preview);
          preview();
        })(jQuery);
      </script>

      <style>
        #<?php $this->id('url') ?>.wait { background: url(<?php echo plugins_url('wait.gif', __FILE__) ?>) no-repeat; background-position: 99% 50%; }
        #<?php $this->id('og_preview') ?> img { float: left; width: 90px; padding: 1px; border: 1px solid #ccc; }
        #<?php $this->id('og_preview') ?> .about { clear: left; width: auto; padding-top: 2px; }
        #post-body-content #<?php $this->id('og_preview') ?> .about { float: left; clear: none; width: 80%; padding-top: 0; }
      </style>
    <?php
  }

  function save_post($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return false;
    }

    $post = get_post($post_id);
    
    if ($parent_post_id = wp_is_post_revision($post)) {
      return false;
    }

    if (!current_user_can('edit_post', $post->ID)) {
      return false;
    }
     
    if (wp_verify_nonce($this->get_post('nonce'), plugin_basename(__FILE__))) {
      update_post_meta($post_id, self::META_URL, $this->get_post('url'));
      update_post_meta($post_id, self::META_IMPORT_FLAG, $this->get_post('import_flag'));

      if ($this->get_post('import_flag')) {

        if (!is_wp_error($request = $this->get_html($this->get_post('url')))) {

          if ( ($og = WpPagePointer_OpenGraph::parse($request['body'])) && $og->image ) {
      
            // try not to download it twice
            $already_downloaded = false;
            if ($attachment_id = get_post_thumbnail_id($post_id)) {
              $already_downloaded = get_post_meta($attachment_id, self::META_IMAGE_SOURCE_URL, true) == $og->image;
            }

            //
            // Credit: http://wordpress.org/extend/plugins/save-grab/
            //

            if (!$already_downloaded) {

              $imageurl = $og->image;
              $imageurl = stripslashes($imageurl);
              $uploads = wp_upload_dir();
              $filename = wp_unique_filename( $uploads['path'], basename($imageurl), $unique_filename_callback = null );
              $wp_filetype = wp_check_filetype($filename, null );
              $fullpathfilename = $uploads['path'] . '/' . $filename;
              
              try {
                if ( !substr_count($wp_filetype['type'], "image") ) {
                  throw new Exception( basename($imageurl) . ' is not a valid image. ' . $wp_filetype['type']  . '' );
                }
              
                $image_string = $this->get_image($imageurl);
                $fileSaved = file_put_contents($uploads['path'] . "/" . $filename, $image_string);
                if ( !$fileSaved ) {
                  throw new Exception("The file cannot be saved.");
                }
                
                $attachment = array(
                  'post_mime_type' => $wp_filetype['type'],
                  'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
                  'post_content' => '',
                  'post_status' => 'inherit',
                  'guid' => $uploads['url'] . '/' . $filename
                );
               
                $attach_id = wp_insert_attachment( $attachment, $fullpathfilename, $post_id );
                if ( !$attach_id ) {
                  throw new Exception("Failed to save record into database.");
                }

                require_once(ABSPATH . 'wp-admin/includes/image.php');
               
                $attach_data = wp_generate_attachment_metadata( $attach_id, $fullpathfilename );
                wp_update_attachment_metadata( $attach_id,  $attach_data );
                update_post_meta($attach_id, self::META_IMAGE_SOURCE_URL, $og->image);
                set_post_thumbnail( $post_id, $attach_id );
              
              } catch (Exception $e) {
                // TODO: log this instead...
                wp_die($e->getMessage());
              }

            } // end if ($already_downloaded)

          }
        } else {
          // log error message
        }
    

        
      }
    }

    return true;
  }

  function template_redirect() {
    global $post;
    if ($post && $post->ID && is_single()) {
      if ($url = get_post_meta($post->ID, self::META_URL, true)) {
        status_header(302);
        header('Location: '.$url);
        exit;
      } 
    }
  }


  // ===========================================================================
  // Helper functions - Provided to your plugin, courtesy of wp-kitchensink
  // http://github.com/collegeman/wp-kitchensink
  // ===========================================================================
  
  /**
   * This function provides a convenient way to access your plugin's settings.
   * The settings are serialized and stored in a single WP option. This function
   * opens that serialized array, looks for $name, and if it's found, returns
   * the value stored there. Otherwise, $default is returned.
   * @param string $name
   * @param mixed $default
   * @return mixed
   */
  function setting($name, $default = null) {
    $settings = get_option(sprintf('%s_settings', __CLASS__), array());
    return isset($settings[$name]) ? $settings[$name] : $default;
  }

  /**
   * Use this function in conjunction with Settings pattern #3 to generate the
   * HTML ID attribute values for anything on the page. This will help
   * to ensure that your field IDs are unique and scoped to your plugin.
   *
   * @see settings.php
   */
  function id($name, $echo = true) {
    $id = sprintf('%s_settings_%s', __CLASS__, $name);
    if ($echo) {
      echo $id;
    }
    return $id;
  }

  function get_post($name, $default = null) {
    $settings = $_GET[sprintf('%s_settings', __CLASS__)];
    if ($settings && isset($settings[$name])) {
      return $settings[$name];
    } else {
      $settings = $_POST[sprintf('%s_settings', __CLASS__)];
      return isset($settings[$name]) ? $settings[$name] : $default;  
    }    
  }

  /**
   * Use this function in conjunction with Settings pattern #3 to generate the
   * HTML NAME attribute values for form input fields. This will help
   * to ensure that your field names are unique and scoped to your plugin, and
   * named in compliance with the setting storage pattern defined above.
   * 
   * @see settings.php
   */
  function field($name, $echo = true) {
    $field = sprintf('%s_settings[%s]', __CLASS__, $name);
    if ($echo) {
      echo $field;
    }
    return $field;
  }
  
  /**
   * A helper function. Prints 'checked="checked"' under two conditions:
   * 1. $field is a string, and $this->setting( $field ) == $value
   * 2. $field evaluates to true
   */
  function checked($field, $value = null) {
    if ( is_string($field) ) {
      if ( $this->setting($field) == $value ) {
        echo 'checked="checked"';
      }
    } else if ( (bool) $field ) {
      echo 'checked="checked"';
    }
  }

  /**
   * A helper function. Prints 'selected="selected"' under two conditions:
   * 1. $field is a string, and $this->setting( $field ) == $value
   * 2. $field evaluates to true
   */
  function selected($field, $value = null) {
    if ( is_string($field) ) {
      if ( $this->setting($field) == $value ) {
        echo 'selected="selected"';
      }
    } else if ( (bool) $field ) {
      echo 'selected="selected"';
    }
  }
  
}

/*
  Copyright 2010 Scott MacVicar

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
*/

class WpPagePointer_OpenGraph implements Iterator
{
  /**
   * There are base schema's based on type, this is just
   * a map so that the schema can be obtained
   *
   */
  public static $TYPES = array(
    'activity' => array('activity', 'sport'),
    'business' => array('bar', 'company', 'cafe', 'hotel', 'restaurant'),
    'group' => array('cause', 'sports_league', 'sports_team'),
    'organization' => array('band', 'government', 'non_profit', 'school', 'university'),
    'person' => array('actor', 'athlete', 'author', 'director', 'musician', 'politician', 'public_figure'),
    'place' => array('city', 'country', 'landmark', 'state_province'),
    'product' => array('album', 'book', 'drink', 'food', 'game', 'movie', 'product', 'song', 'tv_show'),
    'website' => array('blog', 'website'),
  );

  /**
   * Holds all the Open Graph values we've parsed from a page
   *
   */
  private $_values = array();

  /**
   * Fetches a URI and parses it for Open Graph data, returns
   * false on error.
   *
   * @param $URI    URI to page to parse for Open Graph data
   * @return OpenGraph
   */
  static public function fetch($URI) {
    return self::parse(file_get_contents($URI));
  }

  /**
   * Parses HTML and extracts Open Graph data, this assumes
   * the document is at least well formed.
   *
   * @param $HTML    HTML to parse
   * @return OpenGraph
   */
  static function parse($HTML) {
    $old_libxml_error = libxml_use_internal_errors(true);

    $doc = new DOMDocument();
    $doc->loadHTML($HTML);

    libxml_use_internal_errors($old_libxml_error);

    $tags = $doc->getElementsByTagName('meta');
    if (!$tags || $tags->length === 0) {
      return false;
    }

    $page = new self();

    foreach ($tags AS $tag) {
      if ($tag->hasAttribute('property') &&
          strpos($tag->getAttribute('property'), 'og:') === 0) {
        $key = strtr(substr($tag->getAttribute('property'), 3), '-', '_');
        $page->_values[$key] = $tag->getAttribute('content');
      }
    }

    if (empty($page->_values)) { return false; }

    return $page;
  }

  /**
   * Helper method to access attributes directly
   * Example:
   * $graph->title
   *
   * @param $key    Key to fetch from the lookup
   */
  public function __get($key) {
    if (array_key_exists($key, $this->_values)) {
      return $this->_values[$key];
    }

    if ($key === 'schema') {
      foreach (self::$TYPES AS $schema => $types) {
        if (array_search($this->_values['type'], $types)) {
          return $schema;
        }
      }
    }
  }

  /**
   * Return all the keys found on the page
   *
   * @return array
   */
  public function keys() {
    return array_keys($this->_values);
  }

  /**
   * Helper method to check an attribute exists
   *
   * @param $key
   */
  public function __isset($key) {
    return array_key_exists($key, $this->_values);
  }

  /**
   * Will return true if the page has location data embedded
   *
   * @return boolean Check if the page has location data
   */
  public function hasLocation() {
    if (array_key_exists('latitude', $this->_values) && array_key_exists('longitude', $this->_values)) {
      return true;
    }

    $address_keys = array('street_address', 'locality', 'region', 'postal_code', 'country_name');
    $valid_address = true;
    foreach ($address_keys AS $key) {
      $valid_address = ($valid_address && array_key_exists($key, $this->_values));
    }
    return $valid_address;
  }

  /**
   * Iterator code
   */
  private $_position = 0;
  public function rewind() { reset($this->_values); $this->_position = 0; }
  public function current() { return current($this->_values); }
  public function key() { return key($this->_values); }
  public function next() { next($this->_values); ++$this->_position; }
  public function valid() { return $this->_position < sizeof($this->_values); }
}


WpPagePointer::load();

#
# Load global functions (e.g., template functions)
#
require(dirname(__FILE__).'/globals.php');