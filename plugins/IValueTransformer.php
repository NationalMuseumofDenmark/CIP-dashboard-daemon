<?php
namespace CIP;
require_once 'plugins/IPlugin.php';

interface IValueTransformer extends IPlugin {
  /**
   * @param $value The value to be transformed
   * @return The transformed value
   */
  public function transform($value);

  /**
   * @return A descriptive name of the kind of data this transformer returns
   */
  public function getType();
}
