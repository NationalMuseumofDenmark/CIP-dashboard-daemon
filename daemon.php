<?php
namespace CIP;
require_once 'lib/CIP-PHP-Client/src/CIP/CIPClient.php';

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');


class CIPDashboardDaemon {

  const MAX_VALUES = 100;

  protected $_cip_client;
  protected $_settings;
  protected $_plugins = array();
  protected $_value_transformers = array();
  protected $_derived_fields = array();
  protected $_stop_after = NULL;

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
    $json_settings_str = file_get_contents('conf.json');
    if ($json_settings_str  === FALSE) {
      throw new \Exception('You must create and fill out the configuration file `conf.json`.');
    }

    $this->_settings = json_decode($json_settings_str, TRUE);
    if ($this->_settings === NULL) {
      throw new \Exception('The configuration file `conf.json` could not be understood.');
    }

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
      error_log('CIP-dashboard-daemon: You need to set the `CIP_DAEMON_MONGODB_URL` enviroment variable in order to save your results to a MongoDB that is not on localhost.\nAssuming a MongoDB is setup on localhost.');
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
    $cip_daemon = new CIPDashboardDaemon($cip_client);
    return $cip_daemon;
  }



  /**
   * This function sets up the $stats array
   */
  protected function _setupLayout($catalog_alias, $layout_name) {
    $catalog_layout = $this->_cip_client->metadata()->getlayout($catalog_alias,
                                                                $layout_name);

    // Add derived fields to layout
    foreach ($this->_derived_fields as $derived_field_plugin) {
      $catalog_layout['fields'][] = array(
        'key' => $derived_field_plugin->getUUID(),
        'name' => $derived_field_plugin->getFieldName(),
        'derived_field' => TRUE
      );
    }

    // Setup stats array
    $name_to_uid = array();
    $catalog_stats = array();

    foreach ($catalog_layout['fields'] as $field) {
      $uid = $field['key'];
      $catalog_stats[$uid] = array();
      $catalog_stats[$uid]['name'] = $field['name'];
      // $catalog_stats[$uid]['type'] = 'Values';
      $catalog_stats[$uid]['type'] = (isset($field['type'])) ? $field['type'] : NULL;
      $catalog_stats[$uid]['values'] = array();
      $catalog_stats[$uid]['empty_values'] = 0;
      $catalog_stats[$uid]['zero_length_values'] = 0;
      $catalog_stats[$uid]['unprocessed_values'] = 0;
      // $catalog_stats[$uid]['max_value_count'] = -1;
      // $catalog_stats[$uid]['max_value'] = '';
      if (isset($field['type']) && $field['type'] === 'Enum') {
        // Transform array values (these are enums)
        // TODO: I'm not sure this is the best way to do it
        $catalog_stats[$uid]['transform'] = $this->_value_transformers['EnumTransformer'];
      } else {
        $catalog_stats[$uid]['transform'] = null;
      }

      $name_to_uid[$field['name']] = $uid;
    }

    return array($catalog_stats, $catalog_layout);
  }


  public function run($page_size = 100) {

    $all_total_count = 0;
    $all_processed_count = 0;
    list($all_stats, $all_layout) = $this->_setupLayout(reset($this->_settings['catalogs']),
                                                        $this->_settings['layout']);

    foreach ($this->_settings['catalogs'] as $catalog_name => $catalog_alias) {
      $catalog_processed_count = 0;

      list($catalog_stats, $catalog_layout) =
        $this->_setupLayout($catalog_alias, $this->_settings['layout']);

      error_log("Producing stats for catalog \"$catalog_name\".");

      // I hate do-while as much as the next guy (it has bad readability),
      // but it really is the most logical choice here since the `total_count`
      // isn't known beforehand.
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
          $catalog_alias,
          $this->_settings['layout'],
          null,
          null,
          '"Record Name" * or "Record Name" !*',
          $current_index,
          $page_size
        );

        $catalog_total_count = $result['totalcount'];

        foreach ($result['items'] as $asset) {

          // PLUGINS: Add derived fields
          foreach ($this->_derived_fields as $derived_field_plugin) {
            $new_field = $derived_field_plugin->createField($asset);
            // Add field to asset
            $asset = array_merge($asset, $new_field);
          }

          foreach ($asset as $uid => $value) {
            // Add to the "ALL" catalog
            $this->addAssetToCatalog($all_stats, $all_layout, $uid, $value);
            // Add to the current catalog
            $this->addAssetToCatalog($catalog_stats, $catalog_layout, $uid, $value);
          }
          $catalog_processed_count += 1;
        }

        $current_index += $page_size;
      } while ($current_index < $catalog_total_count &&
               ($this->_stop_after === NULL || $current_index < $this->_stop_after));

      $this->catalogs[$catalog_alias]['name'] = $catalog_name;
      $this->catalogs[$catalog_alias]['stats'] = $catalog_stats;
      $this->catalogs[$catalog_alias]['layout'] = $catalog_layout;
      $this->catalogs[$catalog_alias]['total_count'] = $catalog_total_count;
      $this->catalogs[$catalog_alias]['processed_count'] = $catalog_processed_count;

      $all_processed_count += $catalog_processed_count;
      $all_total_count += $catalog_total_count;
    }

    $this->catalogs['ALL']['name'] = $this->_settings['all_catalogs_label'];
    $this->catalogs['ALL']['stats'] = $all_stats;
    $this->catalogs['ALL']['layout'] = $all_layout;
    $this->catalogs['ALL']['total_count'] = $all_total_count;
    $this->catalogs['ALL']['processed_count'] = $all_processed_count;
  }

  protected function addAssetToCatalog(&$catalog_stats, &$catalog_layout, $uid, $value) {
    // if (in_array($uid, $this->skip_fields) { return; }

    // TODO: Hash value so that the key in the `values` array will be
    // $hash = md5(serialize($value);
    // $catalog_stats[$uid]['values'][$hash] = array($value, $count);

    // Found a UUID which was not in the layout
    if (!isset($catalog_stats[$uid])) {
      error_log("Field `$uid` was not found in layout.");
      $catalog_stats[$uid] = array();
      $catalog_stats[$uid]['name'] = $uid;
      $catalog_stats[$uid]['type'] = 'string';
      $catalog_stats[$uid]['values'] = array();
      $catalog_stats[$uid]['empty_values'] = 0;
      $catalog_stats[$uid]['zero_length_values'] = 0;
      $catalog_stats[$uid]['unprocessed_values'] = 0;
    }

    // If NULL
    if (is_null($value)) {
      $catalog_stats[$uid]['empty_values'] += 1;
      return FALSE;
    }

    // If empty string
    if (is_string($value) && mb_strlen($value) === 0) {
      $catalog_stats[$uid]['zero_length_values'] += 1;
      return FALSE;
    }

    // PLUGINS: Transform value
    if (isset($catalog_stats[$uid]['transform']) && $catalog_stats[$uid]['transform'] !== null) {
      $value = $catalog_stats[$uid]['transform']->transform($value);
      if ($value === NULL) {
        echo 'transform error in: ' . $catalog_stats[$uid]['name'];
      }
    }


    // Transform boolean values
    if (is_bool($value)) {
      $value = $this->_value_transformers['BoolTransformer']->transform($value);
    }

    // PHP array indices must be integers or strings
    if (!is_numeric($value) && !is_string($value)) {
      $catalog_stats[$uid]['unprocessed_values'] += 1;
      return FALSE;
    }

    // New value or already existing value?
    if (isset($catalog_stats[$uid]['values'][$value])) {
      $catalog_stats[$uid]['values'][$value] += 1;
    } else {
      $catalog_stats[$uid]['values'][$value] = 1;
    }

    // if ($catalog_stats[$uid]['values'][$value] > $catalog_stats[$uid]['max_value']) {
    //   $catalog_stats[$uid]['max_value_count'] = $catalog_stats[$uid]['values'][$value];
    //   $catalog_stats[$uid]['max_value'] = $value;
    // }

    // If the number of unique values of a field is too high
    // it is transformed by the LengthTransformer
    if (count($catalog_stats[$uid]['values']) > self::MAX_VALUES && $catalog_stats[$uid]['type'] == 'string') {
      $catalog_stats[$uid]['type'] = 'int';
      echo "Too many different values in `{$catalog_stats[$uid]['name']}`.";
      echo "Changing to length statistics\n";
      $catalog_stats[$uid]['transform'] = $this->_value_transformers['LengthTransformer'];

      $temp_array = array();
      foreach ($catalog_stats[$uid]['values'] as $key => $value) {
        $catalog_stats[$uid]['type'] = $catalog_stats[$uid]['transform']->getType();
        $transformed_key = $catalog_stats[$uid]['transform']->transform($key);
        $temp_array[$transformed_key] = $value;
      }
      $catalog_stats[$uid]['values'] = $temp_array;
    }
    return TRUE;
  }

  public function save() {

    $m = new \MongoClient(getenv("CIP_DAEMON_MONGODB_URL"));

    $db = $m->natmus;
    $collection = $db->cip_stats;

    $document = array(
      'catalogs' => $this->catalogs,
    );

    file_put_contents('daemon-dump.txt', print_r($document, TRUE));

    $collection->insert($document);
  }

}


?>
