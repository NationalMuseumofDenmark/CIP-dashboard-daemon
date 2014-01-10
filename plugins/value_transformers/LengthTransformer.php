<?php
namespace CIP;
require_once 'plugins/IValueTransformer.php';

class LengthTransformer implements IValueTransformer {
  public function transform($str) {
    return mb_strlen($str);
  }
  public function getType() { return 'length'; }
}
