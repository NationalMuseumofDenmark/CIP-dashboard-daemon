<?php
namespace CIP;
require_once 'lib/CIP-PHP-Client/src/CIP/CIPClient.php';

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');


class CIPAnalysisDaemon {

  const MAX_VALUES = 100;
  const CATALOG = 'FHM';
  const LAYOUT = 'web';

  protected $_cip_client;
  protected $_plugins = array();
  protected $_value_transformers = array();
  protected $_derived_fields = array();
  protected $_stop_after = 500;

  public $skip_fields = array('name', 'id');

  private function __load_plugin($filename) {
    error_log("Loading plugin $filename");
    $matches = array();
    // I use '#' as regex anchors instead of the usual '/'
    // that is: I use '#regex#' instead of '/regex/'
    // (in order to avoid escaping the slashes)
    preg_match('#plugins/.+/(.+)\.php#', $filename, $matches);
    $plugin_name = $matches[1];

    require_once $filename;

    $qualified_plugin_name = 'CIP\\' . $plugin_name;
    $plugin = new $qualified_plugin_name;

    return array($plugin_name, $plugin);
  }

  public function __construct($cip_client) {
    $this->_cip_client = $cip_client;

    if (getenv('CIP_DAEMON_STOP_AFTER') && is_numeric(getenv('CIP_DAEMON_STOP_AFTER'))) {
      $this->_stop_after = getenv('CIP_DAEMON_STOP_AFTER');
    }

    // Load derived fields
    foreach (glob('plugins/derived_fields/*.php') as $filename) {
      list($plugin_name, $plugin) = $this->__load_plugin($filename);
      $this->_derived_fields[$plugin_name] = $plugin;
    }

    // Load value transformers
    foreach (glob('plugins/value_transformers/*.php') as $filename) {
      list($plugin_name, $plugin) = $this->__load_plugin($filename);
      $this->_value_transformers[$plugin_name] = $plugin;
    }


    if (!getenv("CIP_DAEMON_MONGODB_URL")) {
      error_log('CIP-dashboard-daemon: You need to set the `CIP_DAEMON_MONGODB_URL` enviroment variable in order to save your results');
    } else {
      $m = new \MongoClient(getenv("CIP_DAEMON_MONGODB_URL"));
    }

    // Filter plugins
    #if (getenv('CIP_DAEMON_PLUGINS')) {
    #  $plugins = explode(',', getenv('CIP_DAEMON_PLUGINS'));
    #  foreach ($plugins as $plugin_name) {
    #    $plugin_name = trim(trim($plugin_name), '\'"'); // Trim whitespace and quotes from plugin name

    #    if ($plugin instanceof \CIP\IValueTransformer) {
    #      $this->_value_transformers[] = $plugin;
    #    } else if ($plugin instanceof \CIP\IDerivedField) {
    #    } else {
    #      throw new \InvalidArgumentException("The supplied plugin $plugin_name isn't of any known type (doesn't implement required interfaces).");
    #    }
    #  }
    #}
  }

  public static function constructWithUserPassword($cip_endpoint, $cip_user, $cip_password) {
    $cip_client = new CIPClient($cip_endpoint, true, $cip_user, $cip_password);
    $cip_daemon = new CIPAnalysisDaemon($cip_client);
    return $cip_daemon;
  }



  /**
   * This function sets up the $stats array
   */
  protected function _setupLayout($catalog, $layout) {
    $layout = $this->_cip_client->metadata()->getlayout($catalog, $layout);

    // Add derived fields to layout
    foreach ($this->_derived_fields as $derived_field_plugin) {
      $layout['fields'][] = array(
        'key' => $derived_field_plugin->getUUID(),
        'name' => $derived_field_plugin->getFieldName(),
        'derived_field' => TRUE
      );
    }

    // Setup stats array
    $name_to_uid = array();
    $stats = array();
    foreach ($layout['fields'] as $field) {
      $uid = $field['key'];
      $stats[$uid] = array();
      $stats[$uid]['name'] = $field['name'];
      $stats[$uid]['type'] = 'Values';
      $stats[$uid]['values'] = array();
      $stats[$uid]['empty_values'] = 0;
      $stats[$uid]['zero_length_values'] = 0;
      // $stats[$uid]['max_value_count'] = -1;
      // $stats[$uid]['max_value'] = '';
      if (isset($field['type']) && $field['type'] === 'Enum') {
        // Transform array values (these are enums)
        // TODO: I'm not sure this is the best way to do it
        $stats[$uid]['transform'] = $this->_value_transformers['EnumTransformer'];
      } else {
        $stats[$uid]['transform'] = null;
      }

      $name_to_uid[$field['name']] = $uid;
    }

    return array($layout, $stats);
  }


  public function run($page_size = 100) {
    list($layout, $stats) = $this->_setupLayout(self::CATALOG, self::LAYOUT);

    // I hate do-while as much as the next guy (it has bad readability),
    // but it really is the most logical choice here since the `totalcount`
    // isn't known beforehand.
    $assetcount = 0;
    $current_index = 0;
    do {
      $from = $current_index;
      $to = $current_index+$page_size;
      echo "Fetching assets $from...$to\n";

      // Fetch results from CIP
      // ----------------------
      // The `searchstring` used here searches for all where `Record Name` is
      // either empty or not empty, i.e. it searches for everything
      //
      // '"Record Name" * or "Record Name" !*'
      //
      $result = $this->_cip_client->metadata()->search(
        self::CATALOG,
        self::LAYOUT,
        null,
        null,
        '"Record Name" * or "Record Name" !*',
        $current_index,
        $page_size
      );

      $totalcount = $result['totalcount'];

      foreach ($result['items'] as $asset) {
        $assetcount += 1;

        // PLUGINS: Add derived fields
        foreach ($this->_derived_fields as $derived_field_plugin) {
          $new_field = $derived_field_plugin->createField($asset);
          // Add field to asset
          $asset = array_merge($asset, $new_field);
        }

        foreach ($asset as $uid => $value) {
          // if (in_array($uid, $this->skip_fields) { continue; }


          // TODO: Hash value so that the key in the `values` array will be
          // $hash = md5(serialize($value);
          // $stats[$uid]['values'][$hash] = array($value, $count);

          // Found a UUID which was not in the layout
          if (!isset($stats[$uid])) {
            error_log("Field `$uid` was not found in layout.");
            $stats[$uid] = array();
            $stats[$uid]['name'] = $uid;
            $stats[$uid]['values'] = array();
            $stats[$uid]['empty_values'] = 0;
            $stats[$uid]['zero_length_values'] = 0;
          }

          // If NULL
          if (is_null($value)) {
            $stats[$uid]['empty_values'] += 1;
            continue;
          }

          // If empty string
          if (is_string($value) && mb_strlen($value) === 0) {
            $stats[$uid]['zero_length_values'] += 1;
            continue;
          }

          // PLUGINS: Transform value
          if (isset($stats[$uid]['transform']) && $stats[$uid]['transform'] !== null) {
            $value = $stats[$uid]['transform']->transform($value);
            if ($value === NULL) {
              echo 'transform error in: ' . $stats[$uid]['name'];
            }
          }


          // Transform boolean values
          if (is_bool($value)) {
            $value = $this->_value_transformers['BoolTransformer']->transform($value);
          }

          // PHP array indices must be integers or strings
          if (!is_numeric($value) && !is_string($value)) { continue; }

          // New value or already existing value?
          if (isset($stats[$uid]['values'][$value])) {
            $stats[$uid]['values'][$value] += 1;
          } else {
            $stats[$uid]['values'][$value] = 1;
          }

          // if ($stats[$uid]['values'][$value] > $stats[$uid]['max_value']) {
          //   $stats[$uid]['max_value_count'] = $stats[$uid]['values'][$value];
          //   $stats[$uid]['max_value'] = $value;
          // }

          // If the number of unique values of a field is too high
          // it is transformed by the LengthTransformer
          if (count($stats[$uid]['values']) > self::MAX_VALUES) {
            echo "Too many different values in `{$stats[$uid]['name']}`.";
            echo " Changing to length statistics\n";
            $stats[$uid]['transform'] = $this->_value_transformers['LengthTransformer'];

            $temp_array = array();
            foreach ($stats[$uid]['values'] as $key => $value) {
              $stats[$uid]['type'] = $stats[$uid]['transform']->getType();
              $transformed_key = $stats[$uid]['transform']->transform($key);
              $temp_array[$transformed_key] = $value;
            }
            $stats[$uid]['values'] = $temp_array;
          }
        }

      }

      $current_index += $page_size;
    } while($current_index < $totalcount && $current_index < $this->_stop_after);

    $this->stats = $stats;
    $this->layout = $layout;
    $this->assetcount = $assetcount;
  }

  public function save() {

    $m = new \MongoClient(getenv("CIP_DAEMON_MONGODB_URL"));

    $db = $m->natmus;
    $collection = $db->cip_stats;

    $document = array(
      'stats' => $this->stats,
      'layout' => $this->layout,
      'totalcount' => $this->assetcount
    );

    file_put_contents('daemon-dump.txt', print_r($document, TRUE));

    $collection->insert($document);
  }

}


?>
