<?php
namespace CIP;
require_once 'plugins/IValueTransformer.php';

class BoolTransformer implements IValueTransformer {
  public function transform($bool) {
    if(!assert('is_bool($bool)')) {
      throw new \InvalidArgumentException('BoolTransformer must be passed a boolean value');
    }
    if ($bool === TRUE) {
      return 'TRUE';
    } else {
      return 'FALSE';
    }
  }
  public function getType() { return 'bool'; }
}
