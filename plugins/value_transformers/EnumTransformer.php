<?php
namespace CIP;
require_once 'plugins/IValueTransformer.php';

class EnumTransformer implements IValueTransformer {
  public function transform($array) {
    if (isset($array['id']) && isset($array['displaystring'])) {
      return $array['id'];
    } else {
      error_log('EnumTransformer expects an array with "id" and "displaystring" fields');
      error_log(var_dump($array));
      error_log(var_export($array));
      return null;
    }
  }
  public function getType() { return 'int'; }
}
