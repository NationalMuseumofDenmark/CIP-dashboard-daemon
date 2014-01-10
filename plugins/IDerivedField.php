<?php
namespace CIP;
require 'plugins/IPlugin.php';

interface IDerivedField extends IPlugin {
  /**
   * @param $asset the to which a field should be added
   * @return An array with a single key and value: the name and value of the field to be added to the $asset
   */
  public function createField($asset);
}
